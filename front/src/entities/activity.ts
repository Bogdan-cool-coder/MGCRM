/**
 * Activity entities — DTOs, enums, payloads for S1.6.
 * Typed manually from Laravel ActivityResource / ActivityCardResource.
 */

// ─── Enums ────────────────────────────────────────────────────────────────────

export type ActivityKind = 'call' | 'meeting' | 'task' | 'note' | 'follow_up'
export type ActivityStatus = 'new' | 'in_progress' | 'done' | 'rejected'
export type ActivityPriority = 'low' | 'normal' | 'high' | 'critical'
export type ActivityTargetType = 'deal' | 'company' | 'contact'

// ─── User ref (minimal) ───────────────────────────────────────────────────────

export interface ActivityUserRefDto {
  id: number
  full_name: string
  avatar_path: string | null
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
  // Meeting fields
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
  text: string
  kind: 'text' | 'select'
  is_required: boolean
  sort_order: number
  options: MeetingReportOptionDto[]
}

// ─── Counts ───────────────────────────────────────────────────────────────────

export interface ActivityCountsDto {
  my_tasks: number
  my_orders: number
  overdue: number
  today: number
  this_week: number
  pinned: number
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
}

// ─── My Board (task board view, view 3) ──────────────────────────────────────

export type MyBoardBucket = 'overdue' | 'today' | 'tomorrow' | 'this_week' | 'next_week'

export interface MyBoardActivityDto {
  id: number
  kind: ActivityKind
  title: string | null
  description: string | null
  due_at: string | null
  is_overdue: boolean
  deal: { id: number; title: string } | null
  assigned_to: { id: number; full_name: string } | null
}

export interface MyBoardResponse {
  data: Record<MyBoardBucket, MyBoardActivityDto[]>
}
