// Mirrors api/graphql/schema.graphql (only the fields the dashboard selects).

export type Severity = 'BLOCK' | 'WARN'

export interface RuleViolation {
  rule: string
  severity: Severity
  packId: string
  packVersion: string
  message: string
  staffMemberId: string
  shiftIds: string[]
}

export interface Location {
  id: string
  name: string
  timezone: string
  rulePack: string
}

export interface StaffMember {
  id: string
  name: string
  jobRole: string
  skills: string[]
}

export interface Assignment {
  id: string
  staffMember: Pick<StaffMember, 'id' | 'name'>
}

export interface Shift {
  id: string
  localDate: string
  startLocal: string
  endLocal: string
  durationMinutes: number
  crossesDstTransition: boolean
  jobRole: string
  requiredSkill: string | null
  headcount: number
  assignments: Assignment[]
}

export interface ScheduleWeek {
  id: string | null
  weekStart: string
  status: 'DRAFT' | 'PUBLISHED'
  publishedVersion: number
  publishedAt: string | null
  shifts: Shift[]
  violations: RuleViolation[]
}

export interface AssignStaffPayload {
  blocked: boolean
  assignment: { id: string } | null
  violations: RuleViolation[]
}

export interface PublishWeekPayload {
  published: boolean
  week: Pick<ScheduleWeek, 'status' | 'publishedVersion'>
  violations: RuleViolation[]
}

export interface NewShift {
  locationId: string
  localDate: string
  startLocal: string
  endLocal: string
  jobRole: string
  requiredSkill: string | null
  headcount: number
}
