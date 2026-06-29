/**
 * useInboxPage — orchestrates filters, list fetch, detail, mark-read/unread,
 * reprocess, and unread-count management for the Inbox triage screen.
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
import { localDateString } from '@/utils/activity'
import type { InboundMessage, RoutingStatus, ChannelKind } from '@/api/inbox'

// ─── Filter state ─────────────────────────────────────────────────────────────

export interface InboxFilters {
  unreadOnly: boolean
  failedQuick: boolean
  channel: ChannelKind | null
  routingStatus: RoutingStatus | null
  dateRange: [Date, Date] | null
  q: string
}

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
    failedQuick: false,
    channel: null,
    routingStatus: null,
    dateRange: null,
    q: '',
  })

  // Debounce timer for search input
  let searchDebounceTimer: ReturnType<typeof setTimeout> | null = null
  const debouncedQ = ref('')

  function onSearchInput(value: string) {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    searchDebounceTimer = setTimeout(() => {
      debouncedQ.value = value
    }, 300)
  }

  const hasActiveFilters = computed(() => {
    return (
      !filters.value.unreadOnly ||
      filters.value.failedQuick ||
      filters.value.channel !== null ||
      filters.value.routingStatus !== null ||
      filters.value.dateRange !== null ||
      filters.value.q.trim() !== ''
    )
  })

  function resetFilters() {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    filters.value = {
      unreadOnly: true,
      failedQuick: false,
      channel: null,
      routingStatus: null,
      dateRange: null,
      q: '',
    }
    debouncedQ.value = ''
    currentPage.value = 1
  }

  function toggleFailedQuick() {
    filters.value.failedQuick = !filters.value.failedQuick
    // When enabling the failed quick-filter, hide the status dropdown filter
    if (filters.value.failedQuick) {
      filters.value.routingStatus = null
    }
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
    if (filters.value.failedQuick) {
      params.routing_status = 'failed'
    } else if (filters.value.routingStatus) {
      params.routing_status = filters.value.routingStatus
    }
    if (filters.value.channel) params.channel = filters.value.channel
    if (filters.value.dateRange?.[0]) {
      params.date_from = localDateString(filters.value.dateRange[0])
    }
    if (filters.value.dateRange?.[1]) {
      params.date_to = localDateString(filters.value.dateRange[1])
    }
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
      () => filters.value.failedQuick,
      () => filters.value.channel,
      () => filters.value.routingStatus,
      () => filters.value.dateRange,
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

  // ─── Detail dialog ─────────────────────────────────────────────────────────────
  const selectedMessage = ref<InboundMessage | null>(null)
  const detailVisible = ref(false)
  const detailResource = useAsyncResource<InboundMessage | null>(null)

  async function openDetail(msg: InboundMessage) {
    selectedMessage.value = msg
    detailVisible.value = true

    // Fetch fresh detail (spec: GET /api/inbox/{id} does NOT auto-mark read)
    await detailResource.run(async () => {
      const fresh = await inboxApi.detail(msg.id)
      selectedMessage.value = fresh
      return fresh
    })
    // Read status is changed ONLY by explicit user action (mark-read / mark-unread buttons).
    // Spec: opening the detail view must NOT auto-mark as read.
  }

  function closeDetail() {
    detailVisible.value = false
    selectedMessage.value = null
    detailResource.reset(null)
  }

  // ─── Mark read / unread ────────────────────────────────────────────────────────
  const markReadMutation = useMutation<InboundMessage>()

  async function markRead(id: number) {
    // Optimistic: update the row in the list immediately
    _updateRowInList(id, { read_at: new Date().toISOString() })
    inboxStore.decrement()

    try {
      const updated = await markReadMutation.run(() => inboxApi.markRead(id))
      _updateRowInList(id, updated)
      if (selectedMessage.value?.id === id) selectedMessage.value = updated
    } catch {
      // Revert optimistic update on failure
      _updateRowInList(id, { read_at: null })
      inboxStore.increment()
    }
  }

  async function markUnread(id: number) {
    // Optimistic
    _updateRowInList(id, { read_at: null })
    inboxStore.increment()

    try {
      const updated = await markReadMutation.run(() => inboxApi.markUnread(id))
      _updateRowInList(id, updated)
      if (selectedMessage.value?.id === id) selectedMessage.value = updated
    } catch {
      // Revert
      _updateRowInList(id, { read_at: new Date().toISOString() })
      inboxStore.decrement()
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

  function confirmReprocess(id: number, onConfirm: () => void) {
    confirm.require({
      header: t('inbox.reprocess.confirmTitle'),
      message: t('inbox.reprocess.confirmBody'),
      icon: 'pi pi-refresh',
      acceptLabel: t('inbox.reprocess.confirmAccept'),
      rejectLabel: t('inbox.reprocess.confirmReject'),
      accept: onConfirm,
    })
  }

  async function reprocess(id: number) {
    try {
      const updated = await reprocessMutation.run(() => inboxApi.reroute(id))
      _updateRowInList(id, updated)
      if (selectedMessage.value?.id === id) selectedMessage.value = updated

      if (updated.routing_status !== 'failed') {
        // Success: routed or dedup
        const action =
          updated.target_deal_created
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
    debouncedQ,
    onSearchInput,
    resetFilters,
    toggleFailedQuick,

    // Detail dialog
    selectedMessage,
    detailVisible,
    detailLoading: detailResource.loading,
    detailError: detailResource.error,
    openDetail,
    closeDetail,

    // Mark read/unread
    markRead,
    markUnread,
    markReadPending: markReadMutation.isPending,

    // Reprocess
    reprocessMutation,
    confirmReprocess,
    reprocess,

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
