/**
 * Notifications API — typed axios wrappers.
 * Contract: GET /api/notifications
 *           POST /api/notifications/{id}/read
 *           POST /api/notifications/read-all
 */
import { apiClient } from '@/api/client'
import type { NotificationsResponse } from '@/entities/notification'

export const notificationsApi = {
  /**
   * Fetch notification feed, digest, actionable items, and unread count.
   * @param feedPage - page number for the feed bucket (default 1)
   */
  async getNotifications(feedPage = 1): Promise<NotificationsResponse> {
    const res = await apiClient.get<NotificationsResponse>('/api/notifications', {
      params: { feed_page: feedPage },
    })
    return res.data
  },

  /**
   * Mark a single notification as read.
   */
  async markRead(id: number): Promise<void> {
    await apiClient.post(`/api/notifications/${id}/read`)
  },

  /**
   * Mark all notifications as read.
   */
  async markAllRead(): Promise<void> {
    await apiClient.post('/api/notifications/read-all')
  },
}
