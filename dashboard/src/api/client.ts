import type { AssignStaffPayload, Location, NewShift, PublishWeekPayload, ScheduleWeek, StaffMember } from './types'

type Fetch = typeof fetch

/** A GraphQL `errors` entry, or a transport failure. */
export class ApiError extends Error {
  readonly category: string | null
  readonly unauthenticated: boolean

  constructor(message: string, category: string | null = null) {
    super(message)
    this.category = category
    this.unauthenticated = message === 'Unauthenticated.'
  }
}

interface GraphQLResponse<T> {
  data?: T
  errors?: { message: string; extensions?: { category?: string; validation?: Record<string, string[]> } }[]
}

export class SchedulerApi {
  private readonly token: string
  private readonly fetchImpl: Fetch

  constructor(token: string, fetchImpl: Fetch = (...args) => fetch(...args)) {
    this.token = token
    this.fetchImpl = fetchImpl
  }

  async query<T>(query: string, variables: Record<string, unknown> = {}): Promise<T> {
    const response = await this.fetchImpl('/graphql', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', Authorization: `Bearer ${this.token}` },
      body: JSON.stringify({ query, variables }),
    })

    if (!response.ok) {
      throw new ApiError(`HTTP ${response.status}`)
    }

    const body = (await response.json()) as GraphQLResponse<T>
    const error = body.errors?.[0]
    if (error) {
      const validation = error.extensions?.validation
      const message = validation ? Object.values(validation).flat().join(' ') : error.message
      throw new ApiError(message, error.extensions?.category ?? null)
    }

    return body.data as T
  }

  async locations(): Promise<Location[]> {
    return (await this.query<{ locations: Location[] }>('{ locations { id name timezone rulePack } }')).locations
  }

  async staff(): Promise<StaffMember[]> {
    return (await this.query<{ staffMembers: StaffMember[] }>('{ staffMembers { id name jobRole skills } }')).staffMembers
  }

  async schedule(locationId: string, weekStart: string): Promise<ScheduleWeek> {
    const data = await this.query<{ schedule: ScheduleWeek }>(
      `query ($locationId: ID!, $weekStart: String!) {
        schedule(locationId: $locationId, weekStart: $weekStart) {
          id weekStart status publishedVersion publishedAt
          shifts {
            id localDate startLocal endLocal durationMinutes crossesDstTransition jobRole requiredSkill headcount
            assignments { id staffMember { id name } }
          }
          violations { rule severity packId packVersion message staffMemberId shiftIds }
        }
      }`,
      { locationId, weekStart },
    )
    return data.schedule
  }

  async createShift(input: NewShift): Promise<void> {
    await this.query('mutation ($input: CreateShiftInput!) { createShift(input: $input) { id } }', { input })
  }

  async assign(shiftId: string, staffMemberId: string): Promise<AssignStaffPayload> {
    const data = await this.query<{ assignStaff: AssignStaffPayload }>(
      `mutation ($shiftId: ID!, $staffMemberId: ID!) {
        assignStaff(shiftId: $shiftId, staffMemberId: $staffMemberId) {
          blocked assignment { id } violations { rule severity packId packVersion message staffMemberId shiftIds }
        }
      }`,
      { shiftId, staffMemberId },
    )
    return data.assignStaff
  }

  async unassign(assignmentId: string): Promise<void> {
    await this.query('mutation ($id: ID!) { unassignStaff(assignmentId: $id) { id } }', { id: assignmentId })
  }

  async publish(locationId: string, weekStart: string): Promise<PublishWeekPayload> {
    const data = await this.query<{ publishWeek: PublishWeekPayload }>(
      `mutation ($locationId: ID!, $weekStart: String!) {
        publishWeek(locationId: $locationId, weekStart: $weekStart) {
          published week { status publishedVersion } violations { rule severity packId packVersion message staffMemberId shiftIds }
        }
      }`,
      { locationId, weekStart },
    )
    return data.publishWeek
  }
}

export interface LoginResult {
  token: string
  user: { name: string; email: string; role: string }
  tenant: { id: string; name: string }
}

export async function login(email: string, password: string, fetchImpl: Fetch = (...args) => fetch(...args)): Promise<LoginResult> {
  const response = await fetchImpl('/api/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
    body: JSON.stringify({ email, password }),
  })
  const body = await response.json()
  if (!response.ok) {
    throw new ApiError(body.message ?? `HTTP ${response.status}`)
  }
  return body as LoginResult
}
