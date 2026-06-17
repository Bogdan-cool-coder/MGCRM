import { apiClient } from '@/api/client'
import type {
  KpiParams,
  KpiResponse,
  ActivityFeedParams,
  ActivityFeedResponse,
  MeProfile,
  ProfileResponse,
} from '@/entities/managerCabinet'

export const getProfile = (userId?: number): Promise<MeProfile> => {
  const params: Record<string, unknown> = {}
  if (userId != null) params.user_id = userId
  return apiClient
    .get<ProfileResponse>('/api/me/profile', { params })
    .then((r) => r.data.data)
}

export const getKpiData = (params: KpiParams): Promise<KpiResponse> => {
  const p: Record<string, unknown> = {}
  if (params.period) p.period = params.period
  if (params.user_id != null) p.user_id = params.user_id
  return apiClient.get<KpiResponse>('/api/me/kpi', { params: p }).then((r) => r.data)
}

export const getActivityFeed = (params: ActivityFeedParams): Promise<ActivityFeedResponse> => {
  const p: Record<string, unknown> = {}
  if (params.period) p.period = params.period
  if (params.kind && params.kind !== 'all') p.kind = params.kind
  if (params.ftm_only) p.ftm_only = params.ftm_only
  if (params.user_id != null) p.user_id = params.user_id
  if (params.page) p.page = params.page
  return apiClient
    .get<ActivityFeedResponse>('/api/me/activity-feed', { params: p })
    .then((r) => r.data)
}
