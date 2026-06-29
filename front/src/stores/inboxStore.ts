/**
 * Inbox Pinia store — client state only.
 * Holds unread_count for the sidebar badge.
 * Server-state (list / detail) lives in useInboxPage composable via useAsyncResource.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { inboxApi } from '@/api/inbox'

/** Badge poll cadence — refresh the unread count while the app is open. */
const POLL_INTERVAL_MS = 60_000

export const useInboxStore = defineStore('inbox', () => {
  // ─── Unread count (shown as badge in sidebar nav) ──────────────────────────
  const unreadCount = ref<number>(0)

  // ─── Polling state (module-private; never serialized) ─────────────────────
  let pollTimer: ReturnType<typeof setInterval> | null = null

  // ─── Actions ──────────────────────────────────────────────────────────────

  /** Fetch unread count from the lightweight /unread-count endpoint (badge only). */
  async function fetchUnreadCount(): Promise<void> {
    try {
      const res = await inboxApi.unreadCount()
      unreadCount.value = res.count
    } catch {
      // non-critical; leave count unchanged
    }
  }

  /**
   * Start polling the unread count so the sidebar badge stays fresh even when
   * the InboxPage is never opened. Idempotent — safe to call from AppSidebar mount.
   * Skips the poll while the tab is hidden.
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

  /** Sync count from a fresh API response (called by InboxPage after list refresh). */
  function syncCount(count: number): void {
    unreadCount.value = count
  }

  /** Decrement by n (optimistic after mark-read). */
  function decrement(n = 1): void {
    unreadCount.value = Math.max(0, unreadCount.value - n)
  }

  /** Increment by n (optimistic after mark-unread). */
  function increment(n = 1): void {
    unreadCount.value = unreadCount.value + n
  }

  return {
    unreadCount,
    fetchUnreadCount,
    startPolling,
    stopPolling,
    syncCount,
    decrement,
    increment,
  }
})
