/**
 * Automation API — typed axios wrappers for M7 endpoints.
 *
 * Endpoints (from routes/api.php):
 *   GET    /api/automations           → index (pipeline_id, stage_id, trigger_kind, is_active)
 *   POST   /api/automations           → store
 *   GET    /api/automations/{id}      → show
 *   PATCH  /api/automations/{id}      → update (incl. is_active toggle)
 *   DELETE /api/automations/{id}      → destroy
 *   POST   /api/automations/{id}/test    → dry-run (trigger preview)
 *   POST   /api/automations/{id}/execute → execute now (real side-effects)
 *   GET    /api/automation-runs           → runs journal (automation_id, status, action_kind, from, to)
 */

import { apiClient } from '@/api/client'
import type {
  AutomationDto,
  AutomationRunDto,
  DryRunResponse,
  ExecuteResponse,
  CreateAutomationPayload,
  UpdateAutomationPayload,
  AutomationListParams,
  AutomationRunListParams,
} from '@/entities/automation'

// ─── Helper ───────────────────────────────────────────────────────────────────

function buildParams(obj: Record<string, unknown>): Record<string, string> {
  const params: Record<string, string> = {}
  for (const [k, v] of Object.entries(obj)) {
    if (v !== null && v !== undefined) {
      params[k] = String(v)
    }
  }
  return params
}

// ─── automationsApi ───────────────────────────────────────────────────────────

export const automationsApi = {
  /**
   * GET /api/automations
   * Filters: pipeline_id, stage_id, trigger_kind, is_active
   */
  async list(params: AutomationListParams = {}): Promise<AutomationDto[]> {
    const response = await apiClient.get<{ data: AutomationDto[] }>('/api/automations', {
      params: buildParams(params as Record<string, unknown>),
    })
    return response.data.data
  },

  /**
   * GET /api/automations/{id}
   */
  async get(id: number): Promise<AutomationDto> {
    const response = await apiClient.get<{ data: AutomationDto }>(`/api/automations/${id}`)
    return response.data.data
  },

  /**
   * POST /api/automations
   */
  async create(payload: CreateAutomationPayload): Promise<AutomationDto> {
    const response = await apiClient.post<{ data: AutomationDto }>('/api/automations', payload)
    return response.data.data
  },

  /**
   * PATCH /api/automations/{id}
   * Used for both full-update and is_active toggle.
   */
  async update(id: number, payload: UpdateAutomationPayload): Promise<AutomationDto> {
    const response = await apiClient.patch<{ data: AutomationDto }>(
      `/api/automations/${id}`,
      payload,
    )
    return response.data.data
  },

  /**
   * DELETE /api/automations/{id}
   */
  async remove(id: number): Promise<void> {
    await apiClient.delete(`/api/automations/${id}`)
  },

  /**
   * POST /api/automations/{id}/test
   * Dry-run: preview which records would be affected.
   * limit — max matched records to return (default 50).
   */
  async test(
    id: number,
    options: { target_type?: string; target_id?: number; limit?: number } = {},
  ): Promise<DryRunResponse> {
    const response = await apiClient.post<{ data: DryRunResponse }>(
      `/api/automations/${id}/test`,
      options,
    )
    return response.data.data
  },

  /**
   * POST /api/automations/{id}/execute
   * Execute automation immediately with real side-effects.
   * For inline triggers (on_enter_stage, on_create) target_id is required.
   * For cron triggers resolves matching deals up to limit.
   */
  async execute(
    id: number,
    options: { limit?: number; target_id?: number } = {},
  ): Promise<ExecuteResponse> {
    const response = await apiClient.post<{ data: ExecuteResponse }>(
      `/api/automations/${id}/execute`,
      options,
    )
    return response.data.data
  },
}

// ─── automationRunsApi ────────────────────────────────────────────────────────

export const automationRunsApi = {
  /**
   * GET /api/automation-runs
   * Filters: automation_id, status, action_kind, from, to, per_page
   */
  async list(params: AutomationRunListParams = {}): Promise<AutomationRunDto[]> {
    const response = await apiClient.get<{ data: AutomationRunDto[] }>('/api/automation-runs', {
      params: buildParams(params as Record<string, unknown>),
    })
    return response.data.data
  },
}
