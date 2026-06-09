import type { PeriodRange } from '@/api/types/dashboards'

/**
 * Month-range helpers for the dashboard period filter. All bounds are
 * `YYYY-MM` strings (inclusive). Kept as pure functions so the picker and the
 * data composable share one source of truth for parsing / defaults / presets.
 */

/** Format a `Date` as a `YYYY-MM` month key (local time). */
export const toMonthKey = (date: Date): string => {
  const month = `${date.getMonth() + 1}`.padStart(2, '0')
  return `${date.getFullYear()}-${month}`
}

/** Parse a `YYYY-MM` key into a `Date` (first day of that month) or `null`. */
export const monthKeyToDate = (key: string | null | undefined): Date | null => {
  if (!key) return null
  const [year, month] = key.split('-').map(Number)
  if (!year || !month || month < 1 || month > 12) return null
  return new Date(year, month - 1, 1)
}

/** `YYYY-MM` for the month `offset` months away from `from` (negative = past). */
const shiftMonth = (from: Date, offset: number): Date =>
  new Date(from.getFullYear(), from.getMonth() + offset, 1)

/**
 * Default range — last 12 months (current month inclusive). Mirrors the
 * backend temporal default so an un-touched picker matches what the server
 * would have computed on its own.
 */
export const defaultRange = (now: Date = new Date()): PeriodRange => ({
  from: toMonthKey(shiftMonth(now, -11)),
  to: toMonthKey(now),
})

/** Normalise a range so `from <= to` (DatePicker can emit either order). */
export const normaliseRange = (a: string, b: string): PeriodRange =>
  a <= b ? { from: a, to: b } : { from: b, to: a }

/** Whether two ranges describe the same span. */
export const rangesEqual = (a: PeriodRange | null, b: PeriodRange | null): boolean =>
  a?.from === b?.from && a?.to === b?.to

export type PeriodPresetId = 'last12' | 'thisYear' | 'last3' | 'currentMonth'

/** Build the preset range for a given id, relative to `now`. */
export const presetRange = (id: PeriodPresetId, now: Date = new Date()): PeriodRange => {
  const to = toMonthKey(now)
  switch (id) {
    case 'last12':
      return { from: toMonthKey(shiftMonth(now, -11)), to }
    case 'last3':
      return { from: toMonthKey(shiftMonth(now, -2)), to }
    case 'thisYear':
      return { from: `${now.getFullYear()}-01`, to }
    case 'currentMonth':
      return { from: to, to }
  }
}

/** The preset id matching `range`, or `null` for a custom span. */
export const matchPreset = (
  range: PeriodRange,
  now: Date = new Date(),
): PeriodPresetId | null => {
  const ids: PeriodPresetId[] = ['last12', 'thisYear', 'last3', 'currentMonth']
  return ids.find((id) => rangesEqual(presetRange(id, now), range)) ?? null
}
