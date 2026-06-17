/**
 * Notifications Pinia store — client state only.
 * Holds unread_count for badge display.
 * Server-state (lists/feed) lives in useNotificationsFlyout composable via useAsyncResource.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { notificationsApi } from '@/api/notifications'

export const useNotificationsStore = defineStore('notifications', () => {
  // ─── Unread count (shown as badge in Orbita + sidebar) ────────────────────
  const unreadCount = ref<number>(0)

  // ─── Actions ──────────────────────────────────────────────────────────────

  /** Bootstrap: fetch unread count from the lightweight /count endpoint (badge only). */
  async function fetchUnreadCount(): Promise<void> {
    try {
      const res = await notificationsApi.getUnreadCount()
      unreadCount.value = res.unread_count
    } catch {
      // non-critical; leave count at 0
    }
  }

  /** Sync count from a fresh API response (called by flyout composable) */
  function syncCount(count: number): void {
    unreadCount.value = count
  }

  /** Decrement by n (optimistic after mark-read) */
  function decrement(n = 1): void {
    unreadCount.value = Math.max(0, unreadCount.value - n)
  }

  /** Set to 0 (after mark-all-read) */
  function clearCount(): void {
    unreadCount.value = 0
  }

  return {
    unreadCount,
    fetchUnreadCount,
    syncCount,
    decrement,
    clearCount,
  }
})
