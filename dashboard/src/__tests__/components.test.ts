import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import type { RuleViolation, Shift, StaffMember } from '../api/types'
import ShiftCard from '../components/ShiftCard.vue'
import ViolationList from '../components/ViolationList.vue'

const staff: StaffMember[] = [
  { id: 'ava', name: 'Ava', jobRole: 'supervisor', skills: ['barista'] },
  { id: 'ben', name: 'Ben', jobRole: 'barista', skills: ['barista'] },
]

function shift(overrides: Partial<Shift> = {}): Shift {
  return {
    id: 's1', localDate: '2026-10-31', startLocal: '22:00', endLocal: '06:00', durationMinutes: 540,
    crossesDstTransition: true, jobRole: 'barista', requiredSkill: 'barista', headcount: 2,
    assignments: [{ id: 'a1', staffMember: { id: 'ava', name: 'Ava' } }],
    ...overrides,
  }
}

const block: RuleViolation = {
  rule: 'minor_worker_restrictions', severity: 'BLOCK', packId: 'us-ny', packVersion: '2026.1',
  message: 'Ella (age 15) cannot work past 22:00. (us-ny 2026.1)', staffMemberId: 'ella', shiftIds: ['s1'],
}

describe('ShiftCard', () => {
  it('shows real duration and a DST marker', () => {
    const wrapper = mount(ShiftCard, { props: { shift: shift(), staff } })

    expect(wrapper.find('.time').text()).toBe('22:00–06:00')
    expect(wrapper.find('.duration').text()).toBe('9h')
    expect(wrapper.find('.dst').exists()).toBe(true)
    expect(wrapper.find('.fill').text()).toBe('1/2 staffed')
  })

  it('only offers staff who are not already on the shift, and emits assign', async () => {
    const wrapper = mount(ShiftCard, { props: { shift: shift(), staff } })

    const options = wrapper.findAll('option').map((o) => o.text())
    expect(options).toEqual(['Assign…', 'Ben (barista)'])

    await wrapper.find('select').setValue('ben')
    await wrapper.find('form').trigger('submit')
    expect(wrapper.emitted('assign')).toEqual([['ben']])
  })

  it('hides the assign form when the shift is full and emits unassign', async () => {
    const wrapper = mount(ShiftCard, {
      props: { shift: shift({ headcount: 1, crossesDstTransition: false, durationMinutes: 480 }), staff },
    })

    expect(wrapper.find('form').exists()).toBe(false)
    expect(wrapper.find('.dst').exists()).toBe(false)
    await wrapper.find('button[aria-label="Remove Ava"]').trigger('click')
    expect(wrapper.emitted('unassign')).toEqual([['a1']])
  })

  it('shows the last assign result next to the shift', () => {
    const wrapper = mount(ShiftCard, { props: { shift: shift(), staff, feedback: [block] } })

    expect(wrapper.find('.violation.block').text()).toContain('cannot work past 22:00')
  })
})

describe('ViolationList', () => {
  it('labels blocking and warning violations', () => {
    const warn: RuleViolation = { ...block, rule: 'min_rest_between_shifts', severity: 'WARN', message: 'Rest is 7.0 h' }
    const wrapper = mount(ViolationList, { props: { violations: [block, warn], title: 'Rule check' } })

    expect(wrapper.findAll('.badge').map((b) => b.text())).toEqual(['Blocked', 'Warning'])
  })

  it('renders nothing when there are no violations', () => {
    expect(mount(ViolationList, { props: { violations: [] } }).find('section').exists()).toBe(false)
  })
})
