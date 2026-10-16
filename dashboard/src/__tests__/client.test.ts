import { describe, expect, it, vi } from 'vitest'
import { ApiError, SchedulerApi, login } from '../api/client'

function respond(body: unknown, status = 200) {
  return vi.fn().mockResolvedValue(new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } }))
}

describe('SchedulerApi', () => {
  it('posts the query with the bearer token and returns data', async () => {
    const fetchImpl = respond({ data: { locations: [{ id: 'l1', name: 'Brooklyn', timezone: 'America/New_York', rulePack: 'us-ny' }] } })
    const api = new SchedulerApi('tok', fetchImpl)

    const locations = await api.locations()

    expect(locations[0]!.name).toBe('Brooklyn')
    const [url, init] = fetchImpl.mock.calls[0]!
    expect(url).toBe('/graphql')
    expect(init.headers.Authorization).toBe('Bearer tok')
    expect(JSON.parse(init.body).query).toContain('locations')
  })

  it('turns GraphQL errors into ApiError with the category', async () => {
    const api = new SchedulerApi('tok', respond({ errors: [{ message: 'Not found.', extensions: { category: 'not_found' } }] }))

    await expect(api.schedule('x', '2026-10-19')).rejects.toMatchObject({ message: 'Not found.', category: 'not_found' })
  })

  it('flags unauthenticated errors so the app can sign out', async () => {
    const api = new SchedulerApi('expired', respond({ errors: [{ message: 'Unauthenticated.' }] }))

    const error = await api.locations().catch((e: unknown) => e)
    expect(error).toBeInstanceOf(ApiError)
    expect((error as ApiError).unauthenticated).toBe(true)
  })

  it('shows validation messages instead of the generic validation error', async () => {
    const api = new SchedulerApi('tok', respond({
      errors: [{ message: 'Validation failed for the field [createShift].', extensions: { validation: { 'input.startLocal': ['The start local field must match the format H:i.'] } } }],
    }))

    await expect(api.createShift({ locationId: 'l', localDate: '2026-10-19', startLocal: '9', endLocal: '17:00', jobRole: 'x', requiredSkill: null, headcount: 1 }))
      .rejects.toThrow('The start local field must match the format H:i.')
  })

  it('returns blocked assignments as data, not as an error', async () => {
    const api = new SchedulerApi('tok', respond({
      data: { assignStaff: { blocked: true, assignment: null, violations: [{ rule: 'minor_worker_restrictions', severity: 'BLOCK', packId: 'us-ny', packVersion: '2026.1', message: 'm', staffMemberId: 's', shiftIds: ['sh'] }] } },
    }))

    const result = await api.assign('sh', 's')
    expect(result.blocked).toBe(true)
    expect(result.violations[0]!.severity).toBe('BLOCK')
  })
})

describe('login', () => {
  it('returns the token and tenant', async () => {
    const result = await login('m@x.test', 'pw', respond({ token: 't', user: { name: 'M', email: 'm@x.test', role: 'manager' }, tenant: { id: '1', name: 'Northwind' } }))
    expect(result.tenant.name).toBe('Northwind')
  })

  it('surfaces the server message on failure', async () => {
    await expect(login('m@x.test', 'bad', respond({ message: 'These credentials do not match our records.' }, 422)))
      .rejects.toThrow('These credentials do not match our records.')
  })
})
