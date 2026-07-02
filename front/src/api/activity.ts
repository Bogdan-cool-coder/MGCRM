/**
 * Activity API — all typed axios functions for S1.6.
 */
import { apiClient } from '@/api/client'
import type {
  ActivityDto,
  ActivityPaginatedResponse,
  ActivityCountsDto,
  MeetingReportQuestionDto,
  CreateActivityPayload,
  UpdateActivityPayload,
  ActivityListParams,
  SaveMeetingReportPayload,
  SaveMeetingReportQuestionPayload,
  MyBoardResponse,
} from '@/entities/activity'
import type { BulkCreateActivityPayload } from '@/entities/sales'

export type ActivityPreset = 'my_tasks' | 'my_orders' | 'today' | 'overdue' | 'this_week' | 'pinned' | 'completed'

/** Quick-reschedule relative shortcuts (resolved server-side in the operational TZ). */
export type ReschedulePreset = 'tomorrow' | '+1d' | '+1w' | 'next_monday' | 'next_week' | 'next_month'

export const activityApi = {
  // ── List ───────────────────────────────────────────────────────────────────

  async getActivities(params: ActivityListParams = {}): Promise<ActivityPaginatedResponse> {
    const clean: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') {
        clean[k] = v
      }
    }
    const res = await apiClient.get<ActivityPaginatedResponse>('/api/activities', { params: clean })
    return res.data
  },

  async getPresetActivities(
    preset: ActivityPreset,
    params: Omit<ActivityListParams, 'target_type' | 'target_id'> = {},
  ): Promise<ActivityPaginatedResponse> {
    const clean: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') {
        clean[k] = v
      }
    }
    const res = await apiClient.get<ActivityPaginatedResponse>(
      `/api/activities/presets/${preset}`,
      { params: clean },
    )
    return res.data
  },

  // ── Counts ─────────────────────────────────────────────────────────────────

  async getCountsByPreset(): Promise<ActivityCountsDto> {
    const res = await apiClient.get<{ data: ActivityCountsDto }>('/api/activities/counts-by-preset')
    return res.data.data
  },

  async getMyOpenCount(): Promise<number> {
    const res = await apiClient.get<{ data: { count: number } }>('/api/activities/my-open-count')
    return res.data.data.count
  },

  // ── Single ─────────────────────────────────────────────────────────────────

  async getActivity(id: number): Promise<ActivityDto> {
    const res = await apiClient.get<{ data: ActivityDto }>(`/api/activities/${id}`)
    return res.data.data
  },

  // ── CRUD ───────────────────────────────────────────────────────────────────

  async createActivity(data: CreateActivityPayload): Promise<ActivityDto> {
    const res = await apiClient.post<{ data: ActivityDto }>('/api/activities', data)
    return res.data.data
  },

  async updateActivity(id: number, data: UpdateActivityPayload): Promise<ActivityDto> {
    const res = await apiClient.patch<{ data: ActivityDto }>(`/api/activities/${id}`, data)
    return res.data.data
  },

  async deleteActivity(id: number): Promise<void> {
    await apiClient.delete(`/api/activities/${id}`)
  },

  // ── Status transitions ─────────────────────────────────────────────────────

  async completeActivity(id: number, resultText?: string | null): Promise<ActivityDto> {
    const body: Record<string, unknown> = {}
    if (resultText != null) body.result_text = resultText
    const res = await apiClient.post<{ data: ActivityDto }>(`/api/activities/${id}/complete`, body)
    return res.data.data
  },

  async reopenActivity(id: number): Promise<ActivityDto> {
    const res = await apiClient.post<{ data: ActivityDto }>(`/api/activities/${id}/reopen`)
    return res.data.data
  },

  // ── Quick reschedule (POST /api/activities/{id}/reschedule) ──────────────────
  // Moves ONLY due_at (status/engagement untouched), gated by the same authz as
  // update. Pass EXACTLY ONE of:
  //  - a preset → due_at is computed server-side in the operational timezone
  //    (start of the target day), so the shortcut means the same thing regardless
  //    of the client clock — prefer this over a client-side PATCH of due_at;
  //  - an explicit ISO `due_at` from the date picker.

  async rescheduleActivity(
    id: number,
    arg: { preset: ReschedulePreset } | { dueAt: string },
  ): Promise<ActivityDto> {
    const body = 'preset' in arg ? { preset: arg.preset } : { due_at: arg.dueAt }
    const res = await apiClient.post<{ data: ActivityDto }>(
      `/api/activities/${id}/reschedule`,
      body,
    )
    return res.data.data
  },

  // ── Meeting Report ─────────────────────────────────────────────────────────

  async getMeetingReportQuestions(pipelineId?: number | null): Promise<MeetingReportQuestionDto[]> {
    const params: Record<string, unknown> = {}
    if (pipelineId != null) params.pipeline_id = pipelineId
    const res = await apiClient.get<{ data: MeetingReportQuestionDto[] }>(
      '/api/meeting-report/questions',
      { params },
    )
    return res.data.data
  },

  async saveMeetingReport(dealId: number, data: SaveMeetingReportPayload): Promise<void> {
    await apiClient.post(`/api/deals/${dealId}/meeting-report`, data)
  },

  // ── Meeting-report question registry — admin CRUD (admin/director) ───────────
  // Backs the Settings «Справочники» editor for the meeting-report constructor.

  async listMeetingReportQuestions(pipelineId?: number | null): Promise<MeetingReportQuestionDto[]> {
    const params: Record<string, unknown> = {}
    if (pipelineId != null) params.pipeline_id = pipelineId
    const res = await apiClient.get<{ data: MeetingReportQuestionDto[] }>(
      '/api/meeting-report-questions',
      { params },
    )
    return res.data.data
  },

  async createMeetingReportQuestion(
    data: SaveMeetingReportQuestionPayload,
  ): Promise<MeetingReportQuestionDto> {
    const res = await apiClient.post<{ data: MeetingReportQuestionDto }>(
      '/api/meeting-report-questions',
      data,
    )
    return res.data.data
  },

  async updateMeetingReportQuestion(
    id: number,
    data: SaveMeetingReportQuestionPayload,
  ): Promise<MeetingReportQuestionDto> {
    const res = await apiClient.patch<{ data: MeetingReportQuestionDto }>(
      `/api/meeting-report-questions/${id}`,
      data,
    )
    return res.data.data
  },

  async deleteMeetingReportQuestion(id: number): Promise<void> {
    await apiClient.delete(`/api/meeting-report-questions/${id}`)
  },

  // ── My Board (view 3 — personal task kanban) ───────────────────────────────

  async getMyBoard(): Promise<MyBoardResponse> {
    const res = await apiClient.get<MyBoardResponse>('/api/activities/my-board')
    return res.data
  },

  // ── Team Board (department task board — admin/director/manager only) ─────────

  async getTeamBoard(params: { responsible_id?: number; q?: string } = {}): Promise<MyBoardResponse> {
    const clean: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') {
        clean[k] = v
      }
    }
    const res = await apiClient.get<MyBoardResponse>('/api/activities/team-board', { params: clean })
    return res.data
  },

  // ── Inline status change (PATCH /api/activities/{id}/status) ─────────────

  async changeStatus(
    id: number,
    status: import('@/entities/activity').ActivityStatus,
    resultText?: string | null,
  ): Promise<ActivityDto> {
    const res = await apiClient.patch<{ data: ActivityDto }>(`/api/activities/${id}/status`, {
      status,
      result_text: resultText ?? null,
    })
    return res.data.data
  },

  // ── Bulk create (POST /api/activities/bulk) ────────────────────────────────

  async bulkCreateActivities(payload: BulkCreateActivityPayload): Promise<void> {
    await apiClient.post('/api/activities/bulk', payload)
  },
}
