/**
 * Activity entities — DTOs, enums, payloads for S1.6.
 * Typed manually from Laravel ActivityResource / ActivityCardResource.
 */

// ─── Enums ────────────────────────────────────────────────────────────────────

export type ActivityKind = 'call' | 'meeting' | 'task' | 'note' | 'follow_up' | 'presentation'
export type ActivityStatus = 'new' | 'in_progress' | 'done' | 'rejected'
export type ActivityPriority = 'low' | 'normal' | 'high' | 'critical'
export type ActivityTargetType = 'deal' | 'company' | 'contact'

/**
 * Client-side mirror of ActivityStatus::allowedTransitions()
 * (src/app/Domain/Activity/Enums/ActivityStatus.php).
 *
 * Keep in sync with the backend whenever the server-side state machine changes.
 * Same-status is always a no-op (idempotent) — it is NOT listed here; the UI
 * always includes the current status in the dropdown regardless of this map.
 *
 *   new        → in_progress | rejected
 *   in_progress→ done | rejected | new
 *   done       → in_progress
 *   rejected   → new | in_progress
 */
export const ACTIVITY_STATUS_TRANSITIONS: Record<ActivityStatus, ActivityStatus[]> = {
  new:         ['in_progress', 'rejected'],
  in_progress: ['done', 'rejected', 'new'],
  done:        ['in_progress'],
  rejected:    ['new', 'in_progress'],
}

// ─── User ref (minimal) ───────────────────────────────────────────────────────

export interface ActivityUserRefDto {
  id: number
  full_name: string
  avatar_path: string | null
}

// ─── Deal context (stamped by ActivityService::stampDealContext) ──────────────

export interface ActivityDealContextDto {
  id: number
  title: string
  stage: {
    id: number
    name: string
    color: string | null
    is_won: boolean
    is_lost: boolean
  } | null
  company: {
    id: number
    name: string
  } | null
}

// ─── Target ref ───────────────────────────────────────────────────────────────

export interface ActivityTargetRefDto {
  id: number
  type: ActivityTargetType
  label: string
}

// ─── Full Activity ────────────────────────────────────────────────────────────

export interface ActivityDto {
  id: number
  kind: ActivityKind
  status: ActivityStatus
  priority: ActivityPriority
  title: string
  body: string | null
  result_text: string | null
  due_at: string | null
  is_closed: boolean
  is_pinned: boolean
  is_overdue: boolean
  target_type: ActivityTargetType | null
  target_id: number | null
  target_label: string | null
  responsible: ActivityUserRefDto | null
  creator: ActivityUserRefDto | null
  // Deal context (batch-stamped by ActivityService on list responses; absent on timeline/show)
  deal?: ActivityDealContextDto | null
  // Meeting fields
  is_first_time_meeting: boolean
  ftm_decision_maker_attended: boolean
  ftm_presentation_shown: boolean
  ftm_report_url: string | null
  meeting_report_json: {
    answers: Array<{ question_id: number; answer: string }>
    comment: string | null
  } | null
  department_id: number | null
  created_at: string
  updated_at: string
}

// ─── Light card ───────────────────────────────────────────────────────────────

export interface ActivityCardDto {
  id: number
  kind: ActivityKind
  status: ActivityStatus
  priority: ActivityPriority
  title: string
  due_at: string | null
  is_closed: boolean
  is_pinned: boolean
  is_overdue: boolean
  target_type: ActivityTargetType | null
  target_id: number | null
  target_label: string | null
  responsible: ActivityUserRefDto | null
  created_at: string
}

// ─── Meeting Report ───────────────────────────────────────────────────────────

export interface MeetingReportOptionDto {
  id: number
  text: string
  sort_order: number
}

export interface MeetingReportQuestionDto {
  id: number
  pipeline_id?: number | null
  text: string
  kind: 'text' | 'select'
  is_required: boolean
  is_active?: boolean
  sort_order: number
  options: MeetingReportOptionDto[]
}

// Admin CRUD payload for the question registry (Settings «Справочники»).
export interface SaveMeetingReportQuestionPayload {
  pipeline_id?: number | null
  text: string
  kind: 'text' | 'select'
  is_required?: boolean
  is_active?: boolean
  sort_order?: number
  options?: Array<{ text: string; sort_order?: number }>
}

// ─── Counts ───────────────────────────────────────────────────────────────────

export interface ActivityCountsDto {
  my_tasks: number
  my_orders: number
  overdue: number
  today: number
  this_week: number
  pinned: number
  completed: number
}

// ─── Paginated response ───────────────────────────────────────────────────────

export interface ActivityPaginatedResponse {
  data: ActivityDto[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}

// ─── Payloads ─────────────────────────────────────────────────────────────────

export interface CreateActivityPayload {
  kind: ActivityKind
  title: string
  body?: string | null
  responsible_id?: number | null
  due_at?: string | null
  priority?: ActivityPriority
  target_type?: ActivityTargetType | null
  target_id?: number | null
  // Meeting
  ftm_decision_maker_attended?: boolean
  ftm_presentation_shown?: boolean
  ftm_report_url?: string | null
}

export interface UpdateActivityPayload {
  kind?: ActivityKind
  title?: string
  body?: string | null
  responsible_id?: number | null
  due_at?: string | null
  priority?: ActivityPriority
  is_pinned?: boolean
  result_text?: string | null
  // Meeting
  ftm_decision_maker_attended?: boolean
  ftm_presentation_shown?: boolean
  ftm_report_url?: string | null
}

export interface ActivityListParams {
  target_type?: ActivityTargetType | null
  target_id?: number | null
  kind?: ActivityKind[]
  status?: ActivityStatus[]
  priority?: ActivityPriority[]
  due_from?: string | null
  due_to?: string | null
  q?: string | null
  sort?: string | null
  page?: number
  per_page?: number
}

export interface SaveMeetingReportPayload {
  answers: Array<{ question_id: number; answer: string }>
  comment?: string | null
  activity_id?: number | null
  // FTM (first-time meeting) — captured through the report constructor; feeds the
  // manager KPI cabinet. All four must be satisfied for a counted FTM.
  is_first_time_meeting?: boolean
  ftm_decision_maker_attended?: boolean
  ftm_presentation_shown?: boolean
  ftm_report_url?: string | null
}

// ─── My Board (task board view, view 3) ──────────────────────────────────────

export type MyBoardBucket = 'overdue' | 'today' | 'tomorrow' | 'this_week' | 'next_week'

export interface MyBoardActivityDto {
  id: number
  kind: ActivityKind
  status: ActivityStatus
  priority: ActivityPriority
  title: string | null
  description: string | null
  body: string | null
  due_at: string | null
  is_overdue: boolean
  is_closed: boolean
  is_pinned: boolean
  deal: { id: number; title: string } | null
  // The backend uses `responsible` (ActivityCardResource) not `assigned_to` — keep
  // `assigned_to` as an alias for backward compat with useTaskBoard internals.
  assigned_to: { id: number; full_name: string } | null
  responsible: { id: number; full_name: string } | null
}

export interface MyBoardResponse {
  data: Record<MyBoardBucket, MyBoardActivityDto[]>
}
