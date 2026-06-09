import { reactive } from 'vue'

export type NotificationSeverity = 'success' | 'info' | 'warn' | 'error'

export interface NotificationMessage {
  id: number
  severity: NotificationSeverity
  summary?: string
  detail: string
  life?: number
}

const state = reactive({
  queue: [] as NotificationMessage[],
})

let nextNotificationId = 1

const push = (
  severity: NotificationSeverity,
  detail: string,
  options?: Omit<NotificationMessage, 'id' | 'severity' | 'detail'>,
) => {
  state.queue.push({
    id: nextNotificationId++,
    severity,
    detail,
    summary: options?.summary,
    life: options?.life,
  })
}

export const notificationCenter = {
  state,
  success(detail: string, options?: Omit<NotificationMessage, 'id' | 'severity' | 'detail'>) {
    push('success', detail, options)
  },
  info(detail: string, options?: Omit<NotificationMessage, 'id' | 'severity' | 'detail'>) {
    push('info', detail, options)
  },
  warn(detail: string, options?: Omit<NotificationMessage, 'id' | 'severity' | 'detail'>) {
    push('warn', detail, options)
  },
  error(detail: string, options?: Omit<NotificationMessage, 'id' | 'severity' | 'detail'>) {
    push('error', detail, options)
  },
  drain(): NotificationMessage[] {
    return state.queue.splice(0, state.queue.length)
  },
}
