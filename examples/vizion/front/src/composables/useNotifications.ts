import { normalizeApiError } from '@/utils/errors'
import { notificationCenter } from '@/application'

export const useNotifications = () => {
  // Optional `life` (ms) is forwarded to the notification center so callers can
  // shorten the default auto-dismiss window when a flow can produce a burst of
  // toasts in quick succession (e.g. edit → edit → delete on the same row).
  // Passing `undefined` falls back to the consumer's default (see
  // `AppNotifications.vue`).
  const notifySuccess = (message: string, summary?: string, life?: number) => {
    notificationCenter.success(message, { summary, life })
  }

  const notifyInfo = (message: string, summary?: string, life?: number) => {
    notificationCenter.info(message, { summary, life })
  }

  const notifyWarning = (message: string, summary?: string, life?: number) => {
    notificationCenter.warn(message, { summary, life })
  }

  const notifyError = (message: string, summary?: string, life?: number) => {
    notificationCenter.error(message, { summary, life })
  }

  const notifyApiError = (
    error: unknown,
    fallback: string,
    summary?: string,
    life?: number,
  ) => {
    const normalized = normalizeApiError(error, fallback)

    if (normalized.isAxiosError) {
      console.error('[API error]', {
        message: normalized.message,
        status: normalized.status,
        validationErrors: normalized.validationErrors,
        error,
      })
    } else {
      console.error('[API error]', error)
    }

    notifyError(normalized.message, summary, life)
  }

  return {
    notifySuccess,
    notifyInfo,
    notifyWarning,
    notifyError,
    notifyApiError,
  }
}
