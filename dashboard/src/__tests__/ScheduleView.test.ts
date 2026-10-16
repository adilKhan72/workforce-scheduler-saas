import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import { ApiError, type SchedulerApi } from '../api/client'
import type { ScheduleWeek } from '../api/types'
import ScheduleView from '../views/ScheduleView.vue'
import { addDays } from '../week'

/** Like the server: the week asked for, with one overnight shift on its Saturday. */
function week(overrides: Partial<ScheduleWeek> = {}, weekStart = '2026-10-26'): ScheduleWeek {
  return {
    id: 'w1', weekStart, status: 'DRAFT', publishedVersion: 0, publishedAt: null,
    shifts: [{
      id: 's1', localDate: addDays(weekStart, 5), startLocal: '22:00', endLocal: '06:00', durationMinutes: 540,
      crossesDstTransition: true, jobRole: 'barista', requiredSkill: null, headcount: 1, assignments: [],
    }],
    violations: [],
    ...overrides,
  }
}

function fakeApi(overrides: Partial<Record<keyof SchedulerApi, unknown>> = {}) {
  return {
    locations: vi.fn().mockResolvedValue([{ id: 'l1', name: 'Brooklyn', timezone: 'America/New_York', rulePack: 'us-ny' }]),
    staff: vi.fn().mockResolvedValue([{ id: 'ella', name: 'Ella', jobRole: 'barista', skills: ['barista'] }]),
    schedule: vi.fn((_: string, weekStart: string) => Promise.resolve(week({}, weekStart))),
    assign: vi.fn(),
    unassign: vi.fn(),
    createShift: vi.fn(),
    publish: vi.fn(),
    ...overrides,
  } as unknown as SchedulerApi
}

describe('ScheduleView', () => {
  it("loads the first location's current week and lays shifts out by local day", async () => {
    const api = fakeApi()
    const wrapper = mount(ScheduleView, { props: { api } })
    await flushPromises()

    expect(api.schedule).toHaveBeenCalledWith('l1', expect.stringMatching(/^\d{4}-\d{2}-\d{2}$/))
    const saturday = wrapper.findAll('.day').find((d) => d.find('h2').text().startsWith('Sat'))!
    expect(saturday.find('.shift .time').text()).toBe('22:00–06:00')
    expect(wrapper.findAll('.empty')).toHaveLength(6)
  })

  it('shows a blocked assignment next to the shift and a notice', async () => {
    const api = fakeApi({
      assign: vi.fn().mockResolvedValue({
        blocked: true, assignment: null,
        violations: [{ rule: 'minor_worker_restrictions', severity: 'BLOCK', packId: 'us-ny', packVersion: '2026.1', message: 'Ella (age 15) cannot work past 22:00.', staffMemberId: 'ella', shiftIds: ['s1'] }],
      }),
    })
    const wrapper = mount(ScheduleView, { props: { api } })
    await flushPromises()

    await wrapper.find('.shift select').setValue('ella')
    await wrapper.find('.shift form').trigger('submit')
    await flushPromises()

    expect(api.assign).toHaveBeenCalledWith('s1', 'ella')
    expect(wrapper.find('.shift .violation.block').text()).toContain('cannot work past 22:00')
    expect(wrapper.find('.notice.error').text()).toContain('refused')
  })

  it('disables publishing while the week has blocking violations', async () => {
    const api = fakeApi({
      schedule: vi.fn((_: string, weekStart: string) => Promise.resolve(week({
        violations: [{ rule: 'no_overlapping_shifts', severity: 'BLOCK', packId: 'us-ny', packVersion: '2026.1', message: 'overlap', staffMemberId: 'x', shiftIds: [] }],
      }, weekStart))),
    })
    const wrapper = mount(ScheduleView, { props: { api } })
    await flushPromises()

    expect(wrapper.find('button.primary').attributes('disabled')).toBeDefined()
    expect(wrapper.find('.violations').text()).toContain('overlap')
  })

  it('publishes and shows the new version', async () => {
    const api = fakeApi({ publish: vi.fn().mockResolvedValue({ published: true, week: { status: 'PUBLISHED', publishedVersion: 1 }, violations: [] }) })
    const wrapper = mount(ScheduleView, { props: { api } })
    await flushPromises()

    await wrapper.find('button.primary').trigger('click')
    await flushPromises()

    expect(wrapper.find('.notice.ok').text()).toBe('Published version 1.')
  })

  it('navigates weeks and reloads', async () => {
    const api = fakeApi()
    const wrapper = mount(ScheduleView, { props: { api } })
    await flushPromises()
    const first = vi.mocked(api.schedule).mock.calls[0]![1]

    await wrapper.find('button[aria-label="Next week"]').trigger('click')
    await flushPromises()

    const next = vi.mocked(api.schedule).mock.calls[1]![1]
    expect(new Date(next).getTime() - new Date(first).getTime()).toBe(7 * 86_400_000)
  })

  it('emits unauthenticated when the token has expired', async () => {
    const api = fakeApi({ locations: vi.fn().mockRejectedValue(new ApiError('Unauthenticated.')) })
    const wrapper = mount(ScheduleView, { props: { api } })
    await flushPromises()

    expect(wrapper.emitted('unauthenticated')).toHaveLength(1)
  })
})
