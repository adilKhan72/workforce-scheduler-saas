// Pure date helpers. Dates are local calendar dates (YYYY-MM-DD) of the location, never instants,
// so they are handled as UTC-midnight dates to avoid the browser's own time zone leaking in.

const DAY = 86_400_000

function parse(date: string): Date {
  return new Date(`${date}T00:00:00Z`)
}

function format(date: Date): string {
  return date.toISOString().slice(0, 10)
}

/** Monday of the week containing `date`. */
export function mondayOf(date: string): string {
  const d = parse(date)
  const isoDay = d.getUTCDay() === 0 ? 7 : d.getUTCDay()
  return format(new Date(d.getTime() - (isoDay - 1) * DAY))
}

export function addDays(date: string, days: number): string {
  return format(new Date(parse(date).getTime() + days * DAY))
}

export function weekDays(weekStart: string): string[] {
  return Array.from({ length: 7 }, (_, i) => addDays(weekStart, i))
}

export function dayLabel(date: string): string {
  return parse(date).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })
}

export function duration(minutes: number): string {
  const h = Math.floor(minutes / 60)
  const m = minutes % 60
  return m === 0 ? `${h}h` : `${h}h ${String(m).padStart(2, '0')}m`
}

/** Today's date in a given IANA zone (the location's "today", not the browser's). */
export function todayIn(timezone: string, now: Date = new Date()): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: timezone, year: 'numeric', month: '2-digit', day: '2-digit' }).format(now)
}

export function groupByDay<T extends { localDate: string }>(items: T[], days: string[]): Record<string, T[]> {
  const groups: Record<string, T[]> = Object.fromEntries(days.map((d) => [d, []]))
  for (const item of items) {
    groups[item.localDate]?.push(item)
  }
  return groups
}
