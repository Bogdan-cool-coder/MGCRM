/**
 * Activity UI helpers — kind icons, severity mappers, date formatters.
 *
 * Operational timezone: Asia/Dubai (UTC+4). All date-only strings sent to the
 * API must be built from LOCAL calendar fields (getFullYear/getMonth/getDate)
 * — never from toISOString().split('T')[0] which uses UTC midnight and drifts
 * by 1 day for clients ahead of UTC+4 or when the local clock is in UTC+3.
 */

/**
 * Format a Date to an ISO date string (YYYY-MM-DD) using the LOCAL calendar
 * date of the Date object — TZ-safe for the operational timezone (Asia/Dubai).
 *
 * Usage: `localDateString(new Date())` → "2026-06-26"
 */
export function localDateString(d: Date): string {
  const yyyy = d.getFullYear()
  const mm = String(d.getMonth() + 1).padStart(2, '0')
  const dd = String(d.getDate()).padStart(2, '0')
  return `${yyyy}-${mm}-${dd}`
}

/**
 * Parse a bare date-only string (YYYY-MM-DD) as a LOCAL midnight Date.
 *
 * ECMAScript parses date-only strings as UTC midnight, which causes a one-day
 * shift in any timezone with a non-zero UTC offset. This helper splits the
 * string into components and constructs the Date in local time — safe for
 * display, calendar binding, and form min/max comparisons.
 *
 * Usage: `parseDateLocal('2026-06-28')` → local midnight June 28 2026
 *
 * For datetime strings (ISO with 'T') use `new Date(iso)` as normal.
 */
export function parseDateLocal(iso: string): Date | null {
  // Only handle bare date strings; datetime strings go through normal Date()
  const m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(iso)
  if (!m) return null
  const y = parseInt(m[1]!, 10)
  const mo = parseInt(m[2]!, 10) - 1 // 0-indexed month
  const d = parseInt(m[3]!, 10)
  const result = new Date(y, mo, d)
  return isNaN(result.getTime()) ? null : result
}

/** Operational timezone for the system (server and task deadlines). */
export const OPERATIONAL_TZ = 'Asia/Dubai'

/**
 * Return the current YYYY-MM-DD date in the operational timezone (Asia/Dubai).
 * Use this instead of `new Date().toISOString().slice(0,10)` (UTC) or
 * `localDateString(new Date())` (browser-local) when matching server day boundaries.
 */
export function todayInOperationalTz(): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: OPERATIONAL_TZ })
    .format(new Date()) // en-CA produces YYYY-MM-DD
}

/**
 * Return the ISO day-string (YYYY-MM-DD) for an arbitrary Date in the
 * operational timezone.
 */
export function dateInOperationalTz(d: Date): string {
  return new Intl.DateTimeFormat('en-CA', { timeZone: OPERATIONAL_TZ }).format(d)
}

/**
 * Format a due-at ISO string for display in the operational timezone.
 * Returns relative label (today / tomorrow) or «DD.MM HH:MM».
 *
 * B32: replaces browser-local getHours/toDateString in TaskCard + TaskQuickForm.
 */
export function formatDueDateOperational(dateStr: string, t: (key: string, vals?: Record<string, unknown>) => string): string {
  const d = new Date(dateStr)
  const todayStr = todayInOperationalTz()
  // compute tomorrow in operational tz
  const tomorrow = new Date()
  tomorrow.setDate(tomorrow.getDate() + 1)
  const tomorrowStr = dateInOperationalTz(tomorrow)

  const hhmm = new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    hour: '2-digit',
    minute: '2-digit',
  }).format(d)

  const dueDayStr = dateInOperationalTz(d)

  if (dueDayStr === todayStr) return t('tasks.board.card.today', { time: hhmm })
  if (dueDayStr === tomorrowStr) return t('tasks.board.card.tomorrow', { time: hhmm })

  const parts = new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    day: '2-digit',
    month: '2-digit',
  }).formatToParts(d)
  const day = parts.find((p) => p.type === 'day')?.value ?? '??'
  const month = parts.find((p) => p.type === 'month')?.value ?? '??'
  return `${day}.${month} ${hhmm}`
}

/**
 * Get the start-of-week (Monday) and end-of-week (Sunday) date strings in the
 * operational timezone. Used for B31 matchesPreset this_week boundary.
 */
export function thisWeekRangeInOperationalTz(): { start: string; end: string } {
  const now = new Date()
  // Get current day-of-week in Dubai
  const dayName = new Intl.DateTimeFormat('en-US', {
    timeZone: OPERATIONAL_TZ,
    weekday: 'short',
  }).format(now)
  const dayIdx = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(dayName)
  // Monday-anchored: 0=Sun→6, 1=Mon→0, 2=Tue→1 ...
  const daysFromMonday = dayIdx === 0 ? 6 : dayIdx - 1

  const weekStart = new Date(now.getTime() - daysFromMonday * 86_400_000)
  const weekEnd = new Date(weekStart.getTime() + 6 * 86_400_000)
  return {
    start: dateInOperationalTz(weekStart),
    end: dateInOperationalTz(weekEnd),
  }
}
import type { ActivityKind, ActivityStatus, ActivityPriority } from '@/entities/activity'

/**
 * Type-color map for activity kind icons.
 * Used to tint the icon circle tile and the card border (DealCard §11, EntityCard §5).
 * call=#2A6FDB  meeting=#1F8A5B  follow_up/КП=#E8A317  task=navy  note/other=neutral
 */
const KIND_COLORS: Partial<Record<ActivityKind, string>> = {
  call: '#2A6FDB',       // spec: синий — pi-phone
  meeting: '#1F8A5B',    // spec: зелёный — pi-calendar
  follow_up: '#E8A317',  // spec: жёлтый — pi-file-check/pi-file-edit (КП/предложение)
  presentation: '#E8A317', // same as follow_up per spec
}

/** Returns the accent color for a given activity kind, or null for neutral (note/task/etc.) */
export function kindColor(kind: ActivityKind): string | null {
  return KIND_COLORS[kind] ?? null
}

export function kindIcon(kind: ActivityKind): string {
  const map: Record<ActivityKind, string> = {
    call: 'pi pi-phone',
    meeting: 'pi pi-calendar', // spec §5/DealCard §11: meeting icon = pi-calendar
    task: 'pi pi-check-square',
    note: 'pi pi-file',
    follow_up: 'pi pi-reply',
    presentation: 'pi pi-desktop',
  }
  return map[kind] ?? 'pi pi-circle'
}

export function statusSeverity(
  status: ActivityStatus,
): 'info' | 'success' | 'danger' | 'secondary' | 'warn' {
  const map: Record<ActivityStatus, 'info' | 'success' | 'danger' | 'secondary' | 'warn'> = {
    new: 'info',
    in_progress: 'info',
    done: 'success',
    rejected: 'danger',
  }
  return map[status] ?? 'secondary'
}

export function prioritySeverity(
  priority: ActivityPriority,
): 'secondary' | 'info' | 'warn' | 'danger' {
  const map: Record<ActivityPriority, 'secondary' | 'info' | 'warn' | 'danger'> = {
    low: 'secondary',
    normal: 'info',
    high: 'warn',
    critical: 'danger',
  }
  return map[priority] ?? 'secondary'
}

export function formatDueDate(dateStr: string | null): string {
  if (!dateStr) return '—'
  const d = new Date(dateStr)
  return d.toLocaleString('ru-RU', {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  })
}

export function isOverdueNow(dateStr: string | null, isClosed: boolean): boolean {
  if (!dateStr || isClosed) return false
  return new Date(dateStr) < new Date()
}
