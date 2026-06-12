/**
 * Activity UI helpers — kind icons, severity mappers, date formatters.
 */
import type { ActivityKind, ActivityStatus, ActivityPriority } from '@/entities/activity'

export function kindIcon(kind: ActivityKind): string {
  const map: Record<ActivityKind, string> = {
    call: 'pi pi-phone',
    meeting: 'pi pi-users',
    task: 'pi pi-check-square',
    note: 'pi pi-file',
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
