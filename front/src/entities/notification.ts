/**
 * Notification domain entities.
 * Matches GET /api/notifications response contract.
 */

/** Digest summary chip — high-level count aggregation.
 *  Backend shape: { unread_total: N, by_category: { task: X, approval: Y, ... } }
 */
export interface NotificationDigestDto {
  unread_total?: number
  by_category?: Record<string, number>
}

/** Single notification item — both actionable and feed share this shape */
export interface NotificationDto {
  id: number
  /** Enum-string category (e.g. "task", "approval", "mention"). */
  category: string
  title: string
  body: string | null
  /** Whether the notification has an associated CTA button. */
  is_actionable: boolean
  deep_link: string | null
  action_label: string | null
  /** Arbitrary domain payload from the backend (renamed from `data` to avoid collision with JsonResource wrapper). */
  payload: unknown
  /** ISO datetime — null until the notification is read. */
  read_at: string | null
  is_read: boolean
  created_at: string
}

/** Paginated wrapper for feed bucket */
export interface NotificationPage {
  data: NotificationDto[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
  }
}

/** Top-level response from GET /api/notifications */
export interface NotificationsResponse {
  actionable: NotificationDto[]
  feed: NotificationPage
  digest: NotificationDigestDto
  unread_count: number
}
