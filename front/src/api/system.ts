import { apiClient } from '@/api/client'

export interface SystemResetResponse {
  reset: boolean
  requires_relogin: boolean
  message: string
}

/** Phrase the backend validates via Rule::in — must be sent verbatim. */
export const SYSTEM_RESET_CONFIRMATION = 'СБРОСИТЬ НАСТРОЙКИ'

export const systemApi = {
  /**
   * POST /api/system/reset
   * Admin-only + config-флаг allow_reset должен быть true.
   * Тело запроса: { confirmation: 'СБРОСИТЬ НАСТРОЙКИ' } — backend валидирует через Rule::in.
   * Ответ: { data: { reset: true, requires_relogin: true, message: string } }.
   */
  async resetDatabase(): Promise<SystemResetResponse> {
    const response = await apiClient.post<{ data: SystemResetResponse }>('/api/system/reset', {
      confirmation: SYSTEM_RESET_CONFIRMATION,
    })
    return response.data.data
  },
}
