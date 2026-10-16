import { describe, expect, it } from 'vitest'
import { addDays, duration, groupByDay, mondayOf, todayIn, weekDays } from '../week'

describe('week helpers', () => {
  it('finds the Monday of a week, including from a Sunday', () => {
    expect(mondayOf('2026-10-19')).toBe('2026-10-19')
    expect(mondayOf('2026-10-25')).toBe('2026-10-19')
    expect(mondayOf('2026-11-01')).toBe('2026-10-26')
  })

  it('walks calendar days across a DST change without drifting', () => {
    // The browser's zone must not matter: these are calendar dates, not instants.
    expect(weekDays('2026-10-26')).toEqual([
      '2026-10-26', '2026-10-27', '2026-10-28', '2026-10-29', '2026-10-30', '2026-10-31', '2026-11-01',
    ])
    expect(addDays('2026-03-02', 7)).toBe('2026-03-09')
  })

  it("uses the location's today, not the browser's", () => {
    const instant = new Date('2026-10-20T02:30:00Z') // 22:30 on the 19th in New York
    expect(todayIn('America/New_York', instant)).toBe('2026-10-19')
    expect(todayIn('Europe/London', instant)).toBe('2026-10-20')
  })

  it('formats real durations', () => {
    expect(duration(480)).toBe('8h')
    expect(duration(420)).toBe('7h')
    expect(duration(510)).toBe('8h 30m')
  })

  it('groups shifts by local date and keeps empty days', () => {
    const days = weekDays('2026-10-19')
    const grouped = groupByDay([{ localDate: '2026-10-19' }, { localDate: '2026-10-19' }, { localDate: '2026-10-21' }], days)
    expect(grouped['2026-10-19']).toHaveLength(2)
    expect(grouped['2026-10-20']).toEqual([])
    expect(Object.keys(grouped)).toHaveLength(7)
  })
})
