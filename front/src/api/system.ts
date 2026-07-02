import { apiClient } from '@/api/client'

export interface SystemResetResponse {
  reset: boolean
  requires_relogin: boolean
  message: string
}

/** Phrase the backend validates via Rule::in — must be sent verbatim. */
export const SYSTEM_RESET_CONFIRMATION = 'СБРОСИТЬ НАСТРОЙКИ'

/**
 * Selective reset confirmation phrase (hi-fi redesign — per
 * docs/contracts/system-reset-api-contract.md §6.2). Sent verbatim; the preview
 * response also carries the authoritative phrase in `confirmation_phrase`.
 */
export const SELECTIVE_RESET_CONFIRMATION = 'СБРОСИТЬ'

/** The 9 selective-reset category keys (contract §1). */
export const RESET_CATEGORY_KEYS = [
  'deals',
  'contacts',
  'companies',
  'tasks',
  'docs',
  'finance',
  'logs',
  'automations',
  'directories',
] as const

export type ResetCategoryKey = (typeof RESET_CATEGORY_KEYS)[number]

/** One category as returned by GET /api/system/reset/preview (contract §6.1). */
export interface ResetPreviewCategory {
  key: string
  label: string
  count: number
  /** Prerequisite category keys — must also be selected or the write path 422s (§3). */
  requires: string[]
}

export interface ResetPreviewResponse {
  enabled: boolean
  confirmation_phrase: string
  categories: ResetPreviewCategory[]
}

/** Success payload from POST /api/system/reset (selective form, contract §6.2). */
/** One failed category in a partial-success reset (backend SystemResetResource). */
export interface ResetFailure {
  category: string
  error: string
}

export interface SelectiveResetResponse {
  reset: boolean
  deleted: Record<string, number>
  total_deleted: number
  failed: ResetFailure[]
  requires_relogin: boolean
}

export interface SelectiveResetPayload {
  categories: string[]
  confirmation: string
}

export const systemApi = {
  /**
   * POST /api/system/reset (legacy full-wipe).
   * Admin-only + config-флаг allow_reset должен быть true.
   * Тело запроса: { confirmation: 'СБРОСИТЬ НАСТРОЙКИ' } — backend валидирует через Rule::in.
   * Ответ: { data: { reset: true, requires_relogin: true, message: string } }.
   *
   * NOTE: kept for backward compatibility; the redesigned Settings → «Сброс системы»
   * uses the selective preview/reset methods below.
   */
  async resetDatabase(): Promise<SystemResetResponse> {
    const response = await apiClient.post<{ data: SystemResetResponse }>('/api/system/reset', {
      confirmation: SYSTEM_RESET_CONFIRMATION,
    })
    return response.data.data
  },

  /**
   * GET /api/system/reset/preview — live per-category row counts + prerequisite matrix.
   * Admin-only; 403 if reset disabled for the environment.
   * Ответ: { data: { enabled, confirmation_phrase, categories: [{key,label,count,requires}] } }.
   */
  async resetPreview(): Promise<ResetPreviewResponse> {
    const response = await apiClient.get<{ data: ResetPreviewResponse }>(
      '/api/system/reset/preview',
    )
    return response.data.data
  },

  /**
   * POST /api/system/reset — selective per-category wipe (contract §6.2).
   * Body: { categories: string[], confirmation: 'СБРОСИТЬ' }.
   * Ответ: { data: { reset, deleted, total_deleted, failed, requires_relogin } }.
   */
  async resetSelective(payload: SelectiveResetPayload): Promise<SelectiveResetResponse> {
    const response = await apiClient.post<{ data: SelectiveResetResponse }>(
      '/api/system/reset',
      payload,
    )
    return response.data.data
  },
}
