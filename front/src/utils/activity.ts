/**
 * Activity UI helpers — kind icons, severity mappers, date formatters.
 */
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
