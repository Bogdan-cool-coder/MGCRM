/**
 * Notifications Pinia store — client state only.
 * Holds unread_count for badge display.
 * Server-state (lists/feed) lives in useNotificationsFlyout composable via useAsyncResource.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { notificationsApi } from '@/api/notifications'

/** Badge poll cadence — refresh the unread count while the app is open. */
const POLL_INTERVAL_MS = 60_000

export const useNotificationsStore = defineStore('notifications', () => {
  // ─── Unread count (shown as badge in Orbita + sidebar) ────────────────────
  const unreadCount = ref<number>(0)

  // ─── Polling state (module-private; never serialized) ─────────────────────
  let pollTimer: ReturnType<typeof setInterval> | null = null

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

  /**
   * Start polling the lightweight /count endpoint so the badge stays fresh even
   * when the flyout is never opened. Idempotent — safe to call from any nav-mode
   * shell mount. Pauses polling while the tab is hidden to avoid wasted requests.
   */
  function startPolling(): void {
    if (pollTimer !== null) return
    // Immediate first read so the badge is correct on mount.
    void fetchUnreadCount()
    pollTimer = setInterval(() => {
      if (typeof document !== 'undefined' && document.hidden) return
      void fetchUnreadCount()
    }, POLL_INTERVAL_MS)
  }

  /** Stop polling (shell unmount / logout). */
  function stopPolling(): void {
    if (pollTimer !== null) {
      clearInterval(pollTimer)
      pollTimer = null
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
    startPolling,
    stopPolling,
    syncCount,
    decrement,
    clearCount,
  }
})
