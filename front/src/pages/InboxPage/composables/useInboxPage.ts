/**
 * useInboxPage — orchestrates filters, list fetch, reading-pane detail,
 * mark-read/unread, reprocess, and unread-count management for the two-pane
 * "Mail" triage screen (Inbox v2, СРЕЗ A).
 */
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { inboxApi } from '@/api/inbox'
import { useInboxStore } from '@/stores/inboxStore'
import { useUserStore } from '@/stores/user'
import type { InboundMessage, ChannelKind } from '@/api/inbox'

// ─── Filter state ─────────────────────────────────────────────────────────────

/**
 * Folder selection (mutually exclusive, radio-like):
 * - `all`    → no status/has_deal filter (Inbox)
 * - `failed` → routing_status='failed' (Unrouted)
 * - `deals`  → has_deal=true (In deals)
 */
export type InboxFolder = 'all' | 'failed' | 'deals'

export interface InboxFilters {
  unreadOnly: boolean
  folder: InboxFolder
  channel: ChannelKind | null
  q: string
}

/** Two-pane view mode on narrow screens (< lg): only one pane is visible. */
export type InboxMobileView = 'list' | 'detail'

// ─── Per-page default ─────────────────────────────────────────────────────────
const DEFAULT_PER_PAGE = 30

export const useInboxPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const inboxStore = useInboxStore()
  const userStore = useUserStore()

  // ─── Role gate ───────────────────────────────────────────────────────────────
  const role = userStore.getUserRole
  const isAdmin = role === 'admin' || role === 'director'
  const canViewRawPayload = isAdmin

  // ─── Filter state ─────────────────────────────────────────────────────────────
  const filters = ref<InboxFilters>({
    unreadOnly: true,
    folder: 'all',
    channel: null,
    q: '',
  })

  // Debounce timer for search input
  let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null
  const debouncedQ = ref('')

  function onSearchInput(value: string) {
    filters.value.q = value
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    searchDebounceTimer = setTimeout(() => {
      debouncedQ.value = value
    }, 300)
  }

  const hasActiveFilters = computed(() => {
    return (
      !filters.value.unreadOnly ||
      filters.value.folder !== 'all' ||
      filters.value.channel !== null ||
      filters.value.q.trim() !== ''
    )
  })

  function resetFilters() {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    filters.value = {
      unreadOnly: true,
      folder: 'all',
      channel: null,
      q: '',
    }
    debouncedQ.value = ''
    currentPage.value = 1
  }

  function setFolder(folder: InboxFolder) {
    filters.value.folder = folder
    currentPage.value = 1
  }

  function setChannel(channel: ChannelKind | null) {
    filters.value.channel = channel
    currentPage.value = 1
  }

  function setUnreadOnly(value: boolean) {
    filters.value.unreadOnly = value
    currentPage.value = 1
  }

  // ─── Pagination state ─────────────────────────────────────────────────────────
  const currentPage = ref(1)
  const perPage = ref(DEFAULT_PER_PAGE)
  const totalRecords = ref(0)

  // ─── List resource ─────────────────────────────────────────────────────────────
  const listResource = useAsyncResource<InboundMessage[]>([])

  async function fetchMessages() {
    const params: Parameters<typeof inboxApi.list>[0] = {
      page: currentPage.value,
      per_page: perPage.value,
    }

    if (filters.value.unreadOnly) params.unread = true
    if (filters.value.folder === 'failed') {
      params.routing_status = 'failed'
    } else if (filters.value.folder === 'deals') {
      params.has_deal = true
    }
    if (filters.value.channel) params.channel = filters.value.channel
    if (debouncedQ.value.trim()) params.q = debouncedQ.value.trim()

    await listResource.run(async () => {
      const result = await inboxApi.list(params)
      totalRecords.value = result.meta.total
      return result.data
    })

    // Refresh the sidebar badge after each list load
    void inboxStore.fetchUnreadCount()
  }

  // Refetch on filter changes (watch debounced q separately)
  watch(
    [
      () => filters.value.unreadOnly,
      () => filters.value.folder,
      () => filters.value.channel,
      currentPage,
      perPage,
    ],
    () => {
      void fetchMessages()
    },
  )

  watch(debouncedQ, () => {
    currentPage.value = 1
    void fetchMessages()
  })

  // Initial load
  void fetchMessages()

  // ─── Reading pane (selection + detail) ─────────────────────────────────────────
  const selectedId = ref<number | null>(null)
  const selectedMessage = ref<InboundMessage | null>(null)
  const mobileView = ref<InboxMobileView>('list')
  const detailResource = useAsyncResource<InboundMessage | null>(null)

  /**
   * Select a message → load its fresh detail into the reading pane.
   * Does NOT auto-mark read (spec: read status changes only via explicit toggle).
   * On narrow screens, switches the visible pane to 'detail'.
   */
  async function openMessage(msg: InboundMessage) {
    selectedId.value = msg.id
    selectedMessage.value = msg
    mobileView.value = 'detail'

    await detailResource.run(async () => {
      const fresh = await inboxApi.detail(msg.id)
      // Guard against a race if the user clicked another row meanwhile.
      if (selectedId.value === fresh.id) selectedMessage.value = fresh
      return fresh
    })
  }

  /** Back to the list pane (mobile single-pane mode). */
  function backToList() {
    mobileView.value = 'list'
  }

  // ─── Mark read / unread ────────────────────────────────────────────────────────
  const markReadMutation = useMutation<InboundMessage>()

  async function markRead(id: number) {
    // Optimistic: update the row in the list immediately
    _updateRowInList(id, { read_at: new Date().toISOString() })
    if (selectedMessage.value?.id === id) {
      selectedMessage.value = { ...selectedMessage.value, read_at: new Date().toISOString() }
    }
    inboxStore.decrement()

    try {
      const updated = await markReadMutation.run(() => inboxApi.markRead(id))
      _updateRowInList(id, updated)
      if (selectedMessage.value?.id === id) selectedMessage.value = updated
    } catch {
      // Revert optimistic update on failure
      _updateRowInList(id, { read_at: null })
      if (selectedMessage.value?.id === id) {
        selectedMessage.value = { ...selectedMessage.value, read_at: null }
      }
      inboxStore.increment()
    }
  }

  async function markUnread(id: number) {
    // Optimistic
    _updateRowInList(id, { read_at: null })
    if (selectedMessage.value?.id === id) {
      selectedMessage.value = { ...selectedMessage.value, read_at: null }
    }
    inboxStore.increment()

    try {
      const updated = await markReadMutation.run(() => inboxApi.markUnread(id))
      _updateRowInList(id, updated)
      if (selectedMessage.value?.id === id) selectedMessage.value = updated
    } catch {
      // Revert
      const revertedAt = new Date().toISOString()
      _updateRowInList(id, { read_at: revertedAt })
      if (selectedMessage.value?.id === id) {
        selectedMessage.value = { ...selectedMessage.value, read_at: revertedAt }
      }
      inboxStore.decrement()
    }
  }

  /** Toggle read state of the currently selected message (reading-pane toolbar). */
  function toggleSelectedRead() {
    const msg = selectedMessage.value
    if (!msg) return
    if (msg.read_at) {
      void markUnread(msg.id)
    } else {
      void markRead(msg.id)
    }
  }

  function _updateRowInList(id: number, partial: Partial<InboundMessage>) {
    const idx = listResource.data.value.findIndex((m) => m.id === id)
    if (idx >= 0) {
      listResource.data.value[idx] = { ...listResource.data.value[idx]!, ...partial }
    }
  }

  // ─── Reprocess (reroute failed message) ───────────────────────────────────────
  const reprocessMutation = useMutation<InboundMessage>()
  // Tracks which row's spinner is active — cleared when the mutation settles.
  const currentReprocessId = ref<number | null>(null)

  function confirmReprocess(id: number) {
    confirm.require({
      header: t('inbox.reprocess.confirmTitle'),
      message: t('inbox.reprocess.confirmBody'),
      icon: 'pi pi-refresh',
      acceptLabel: t('inbox.reprocess.confirmAccept'),
      rejectLabel: t('inbox.reprocess.confirmReject'),
      accept: () => {
        void reprocess(id)
      },
    })
  }

  async function reprocess(id: number) {
    currentReprocessId.value = id
    try {
      const updated = await reprocessMutation.run(() => inboxApi.reroute(id))
      _updateRowInList(id, updated)
      if (selectedMessage.value?.id === id) selectedMessage.value = updated

      if (updated.routing_status !== 'failed') {
        // Success: routed or dedup
        const action = updated.target_deal_created
          ? t('inbox.reprocess.successCreated')
          : t('inbox.reprocess.successLinked')
        const dealId = updated.target_deal_id ?? 0
        toast.add({
          severity: 'success',
          summary: t('inbox.reprocess.successToast', { dealId, action }),
          life: 4000,
        })
      } else {
        // Still failed — informational, not an error
        toast.add({
          severity: 'warn',
          summary: t('inbox.reprocess.errorToast'),
          life: 5000,
        })
      }
    } catch {
      toast.add({
        severity: 'error',
        summary: t('inbox.reprocess.errorToast'),
        life: 5000,
      })
    } finally {
      currentReprocessId.value = null
    }
  }

  // ─── Pagination handler ────────────────────────────────────────────────────────
  function onPageChange(event: { page: number; rows: number }) {
    currentPage.value = event.page + 1 // PrimeVue Paginator is 0-based
    perPage.value = event.rows
  }

  return {
    // Data
    messages: listResource.data,
    listLoading: listResource.loading,
    listError: listResource.error,
    totalRecords,
    currentPage,
    perPage,

    // Filters
    filters,
    hasActiveFilters,
    onSearchInput,
    resetFilters,
    setFolder,
    setChannel,
    setUnreadOnly,

    // Reading pane
    selectedId,
    selectedMessage,
    mobileView,
    detailLoading: detailResource.loading,
    detailError: detailResource.error,
    openMessage,
    backToList,

    // Mark read/unread
    markRead,
    markUnread,
    toggleSelectedRead,
    markReadPending: markReadMutation.isPending,

    // Reprocess
    reprocessPending: reprocessMutation.isPending,
    currentReprocessId,
    confirmReprocess,

    // Pagination
    onPageChange,

    // Refresh
    fetchMessages,

    // Role
    canViewRawPayload,

    // Inbox store (for badge binding in template)
    inboxUnreadCount: computed(() => inboxStore.unreadCount),
  }
}
