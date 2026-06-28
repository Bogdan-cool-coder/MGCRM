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
      decrementDigest(item)
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
    // Reset digest so the chips clear immediately
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
   *
   * Only non-actionable feed items are auto-marked on close. Actionable items
   * ("Требует внимания") require explicit user interaction — silently marking
   * them read when the user merely glanced at the panel is surprising and may
   * cause important CTAs to be missed.
   */
  async function onFlyoutClose(): Promise<void> {
    // Exclude actionable items from auto-close mark-read.
    // Items already flipped optimistically by markRead() will have is_read=true
    // and are naturally skipped by the !n.is_read guard.
    const unreadShown = [...feed.value].filter(
      (n) => !n.is_read && !n.is_actionable && shownIds.value.has(n.id),
    )
    if (unreadShown.length === 0) {
      shownIds.value.clear()
      return
    }

    // Optimistic: mark locally before the request completes
    unreadShown.forEach((n) => {
      decrementDigest(n)
      n.is_read = true
    })
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

  /**
   * Decrement digest counters for the given item, clamping at 0.
   * Call whenever a previously-unread item is flipped to read (single or batch).
   * Keeps the digest chips in sync without a round-trip refetch.
   */
  function decrementDigest(item: NotificationDto): void {
    if (!digest.value.by_category) return
    const cat = item.category
    if (cat && digest.value.by_category[cat] !== undefined) {
      digest.value.by_category[cat] = Math.max(0, (digest.value.by_category[cat] ?? 0) - 1)
    }
    if (digest.value.unread_total !== undefined) {
      digest.value.unread_total = Math.max(0, digest.value.unread_total - 1)
    }
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
