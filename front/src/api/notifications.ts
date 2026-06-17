/**
 * Notifications API — typed axios wrappers.
 * Contract: GET  /api/notifications
 *           GET  /api/notifications/count
 *           POST /api/notifications/{id}/read
 *           POST /api/notifications/read-all
 *           POST /api/notifications/read-batch
 */
import { apiClient } from '@/api/client'
import type {
  NotificationsResponse,
  NotificationsCountResponse,
  ReadBatchResponse,
} from '@/entities/notification'

export const notificationsApi = {
  /**
   * Fetch notification feed, digest, actionable items, and unread count.
   * Heavy endpoint — call only when the flyout is opened.
   * @param feedPage - page number for the feed bucket (default 1)
   */
  async getNotifications(feedPage = 1): Promise<NotificationsResponse> {
    const res = await apiClient.get<NotificationsResponse>('/api/notifications', {
      params: { feed_page: feedPage },
    })
    return res.data
  },

  /**
   * Lightweight unread count poll — own unread items only.
   * Use this for badge refresh on mount / polling; never the full grouped endpoint.
   */
  async getUnreadCount(): Promise<NotificationsCountResponse> {
    const res = await apiClient.get<NotificationsCountResponse>('/api/notifications/count')
    return res.data
  },

  /**
   * Mark a single notification as read.
   */
  async markRead(id: number): Promise<void> {
    await apiClient.post(`/api/notifications/${id}/read`)
  },

  /**
   * Batch-mark multiple notifications as read in a single request.
   * Foreign / already-read ids are silently skipped (no 403).
   * @param ids - array of notification ids (1–200 items)
   */
  async markReadBatch(ids: number[]): Promise<ReadBatchResponse> {
    const res = await apiClient.post<ReadBatchResponse>('/api/notifications/read-batch', { ids })
    return res.data
  },

  /**
   * Mark all notifications as read.
   */
  async markAllRead(): Promise<void> {
    await apiClient.post('/api/notifications/read-all')
  },
}
