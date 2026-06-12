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
} from '@/entities/activity'

export type ActivityPreset = 'my_tasks' | 'my_orders' | 'today' | 'overdue' | 'this_week' | 'pinned'

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

  async completeActivity(id: number): Promise<ActivityDto> {
    const res = await apiClient.post<{ data: ActivityDto }>(`/api/activities/${id}/complete`)
    return res.data.data
  },

  async reopenActivity(id: number): Promise<ActivityDto> {
    const res = await apiClient.post<{ data: ActivityDto }>(`/api/activities/${id}/reopen`)
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
}
