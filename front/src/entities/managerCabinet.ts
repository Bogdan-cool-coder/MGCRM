export type KpiPeriod = 'current_month' | 'last_month' | string // YYYY-MM

export interface KpiParams {
  period?: KpiPeriod
  user_id?: number
}

export interface ActivityFeedParams {
  period?: KpiPeriod
  kind?: 'all' | 'call' | 'meeting' | 'task' | 'note'
  ftm_only?: boolean
  user_id?: number
  page?: number
}

export interface KpiMeta {
  user: { id: number; full_name: string; department_id: number | null }
  period: { from: string; to: string; label: string }
  base_currency: string
  multi_currency_warning: boolean
  income_source: 'won_deals'
}

export interface PersonalKpi {
  income_fact_kopecks: number
  income_plan_kopecks: number
  score_pct: number
  score_badge: 'success' | 'warning' | 'danger'
  ftm_count_fact: number
  ftm_count_plan: number | null
  has_salary_plan: boolean
}

export interface TeamMember {
  full_name: string
  score_pct: number
  is_viewer: boolean
}

export interface TeamKpi {
  avg_pct: number
  rank: number
  size: number
  members: TeamMember[]
}

export interface KpiResponse {
  meta: KpiMeta
  personal: PersonalKpi
  team: TeamKpi
}

export interface MeProfile {
  id: number
  full_name: string
  email: string
  role: string
  job_title: string | null
  department_id: number | null
  department_name: string | null
  manager_id: number | null
  manager_name: string | null
  subordinates_count: number
  avatar_path: string | null
}

export interface ProfileResponse {
  data: MeProfile
}

export interface ActivityFeedItem {
  id: number
  kind: 'call' | 'meeting' | 'task' | 'note'
  title: string
  target_type: string | null
  target_id: number | null
  due_at: string | null
  completed_at: string | null
  is_first_time_meeting: boolean
  ftm_counted: boolean
  created_at: string
}

export interface ActivityFeedMeta {
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface ActivityFeedResponse {
  data: ActivityFeedItem[]
  meta: ActivityFeedMeta
}
