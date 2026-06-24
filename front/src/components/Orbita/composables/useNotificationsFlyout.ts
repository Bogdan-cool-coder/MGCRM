/**
 * useNotificationsFlyout — server-state orchestrator for the notifications flyout.
 *
 * Responsibilities:
 * - Load notifications on open (useAsyncResource)
 * - Load-more (pagination)
 * - Mark single item read → navigate to deep_link
 * - Mark all read
 * - Mark-read-on-close (items shown while flyout was open)
 * - Sync unread count to notificationsStore
 */
import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { notificationsApi } from '@/api/notifications'
import { useNotificationsStore } from '@/stores/notificationsStore'
import type { NotificationDto, NotificationDigestDto, NotificationPage } from '@/entities/notification'

export function useNotificationsFlyout() {
  const router = useRouter()
  const notificationsStore = useNotificationsStore()

  // ─── Server state ──────────────────────────────────────────────────────────
  const actionable = ref<NotificationDto[]>([])
  const feed = ref<NotificationDto[]>([])
  const digest = ref<NotificationDigestDto>({})
  const feedMeta = ref<NotificationPage['meta'] | null>(null)

  const {
    loading: loadingInitial,
    error: loadError,
    run: runLoad,
  } = useAsyncResource<null>(null)

  const {
    loading: loadingMore,
    run: runLoadMore,
  } = useAsyncResource<null>(null)

  // Track IDs shown while flyout was open (for mark-read-on-close)
  const shownIds = ref<Set<number>>(new Set())

  // ─── Computed ──────────────────────────────────────────────────────────────
  const hasMoreFeed = computed(() => {
    if (!feedMeta.value) return false
    return feedMeta.value.current_page < feedMeta.value.last_page
  })

  const isEmpty = computed(
    () =>
      !loadingInitial.value &&
      actionable.value.length === 0 &&
      feed.value.length === 0,
  )

  // ─── Mutations ─────────────────────────────────────────────────────────────
  const markReadMutation = useMutation<void>()
  const markAllReadMutation = useMutation<void>()

  // ─── Load (initial open) ───────────────────────────────────────────────────
  async function load(): Promise<void> {
    await runLoad(async () => {
      const res = await notificationsApi.getNotifications(1)
      actionable.value = res.actionable
      feed.value = res.feed.data
      feedMeta.value = res.feed.meta
      digest.value = res.digest
      notificationsStore.syncCount(res.unread_count)
      // Track all visible unread IDs
      trackShown([...res.actionable, ...res.feed.data])
      return null
    })
  }

  // ─── Load more (pagination) ────────────────────────────────────────────────
  async function loadMore(): Promise<void> {
    if (!hasMoreFeed.value || loadingMore.value) return
    const nextPage = (feedMeta.value?.current_page ?? 1) + 1
    await runLoadMore(async () => {
      const res = await notificationsApi.getNotifications(nextPage)
      const newItems = res.feed.data
      feed.value = [...feed.value, ...newItems]
      feedMeta.value = res.feed.meta
      trackShown(newItems)
      return null
    })
  }

  // ─── Mark single read ──────────────────────────────────────────────────────
  async function markRead(item: NotificationDto): Promise<void> {
    const wasUnread = !item.is_read

    // Optimistic update
    setItemRead(item.id)
    if (wasUnread) {
      notificationsStore.decrement(1)
    }

    await markReadMutation.run(async () => {
      await notificationsApi.markRead(item.id)
    })

    // Navigate to deep link if present
    if (item.deep_link) {
      void router.push(item.deep_link)
    }
  }

  // ─── Mark all read ─────────────────────────────────────────────────────────
  async function markAllRead(): Promise<void> {
    // Optimistic: mark everything locally
    const unreadBefore = notificationsStore.unreadCount
    const digestBefore = { ...digest.value }
    actionable.value.forEach((n) => { n.is_read = true })
    feed.value.forEach((n) => { n.is_read = true })
    // Reset digest so the chip clears immediately (BUG-NOTIF-DIGEST-STALE)
    digest.value = { unread_total: 0, by_category: {} }
    notificationsStore.clearCount()

    await markAllReadMutation.run(async () => {
      // Use the authoritative server count instead of the optimistic 0 — the
      // server may have flipped a different number of rows than we assumed.
      const res = await notificationsApi.markAllRead()
      notificationsStore.syncCount(res.unread_count)
    }, {
      onError: async () => {
        // Revert optimistic on failure
        digest.value = digestBefore
        notificationsStore.syncCount(unreadBefore)
      },
    })
  }

  // ─── Mark-read-on-close ────────────────────────────────────────────────────
  /**
   * Call when the flyout closes.
   * All unread items that were visible get marked read in a single batch request.
   */
  async function onFlyoutClose(): Promise<void> {
    const unreadShown = [...actionable.value, ...feed.value].filter(
      (n) => !n.is_read && shownIds.value.has(n.id),
    )
    if (unreadShown.length === 0) return

    // Optimistic: mark locally before the request completes
    unreadShown.forEach((n) => { n.is_read = true })
    notificationsStore.decrement(unreadShown.length)
    shownIds.value.clear()

    // Single batch request instead of per-item serial calls.
    // Backend silently skips foreign / already-read ids — no 403 risk.
    const ids = unreadShown.map((n) => n.id)
    try {
      const res = await notificationsApi.markReadBatch(ids)
      // Authoritative count from server overrides the optimistic decrement
      notificationsStore.syncCount(res.unread_count)
    } catch {
      // best-effort; badge stays at the optimistic value
    }
  }

  // ─── Helpers ───────────────────────────────────────────────────────────────
  function trackShown(items: NotificationDto[]): void {
    items.forEach((n) => shownIds.value.add(n.id))
  }

  function setItemRead(id: number): void {
    const inActionable = actionable.value.find((n) => n.id === id)
    if (inActionable) inActionable.is_read = true
    const inFeed = feed.value.find((n) => n.id === id)
    if (inFeed) inFeed.is_read = true
  }

  return {
    // State
    actionable,
    feed,
    digest,
    feedMeta,
    // Computed
    hasMoreFeed,
    isEmpty,
    // Loading
    loadingInitial,
    loadingMore,
    loadError,
    markAllPending: markAllReadMutation.isPending,
    // Actions
    load,
    loadMore,
    markRead,
    markAllRead,
    onFlyoutClose,
  }
}
