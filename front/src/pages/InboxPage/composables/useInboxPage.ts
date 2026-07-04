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
import { localDateString } from '@/utils/activity'
import type { InboundMessage, ChannelKind, InboxCounts, InboxDraft } from '@/api/inbox'

// ─── Filter state ─────────────────────────────────────────────────────────────

/**
 * Folder selection (mutually exclusive, radio-like). Each maps to a param set on
 * GET /api/inbox (СРЕЗ B — no server `folder` enum; the composable builds params):
 * - `all`       → no status/has_deal filter (Inbox; server hides active-snoozed)
 * - `starred`   → starred=1 (Помеченные)
 * - `important` → important=1 (Важные)
 * - `failed`    → routing_status='failed' (Не разобрано)
 * - `deals`     → has_deal=true (В сделках)
 * - `snoozed`   → snoozed=1 (Отложенные)
 * - `drafts`    → SEPARATE endpoint /api/inbox/drafts + draft editor render
 */
export type InboxFolder = 'all' | 'starred' | 'important' | 'failed' | 'deals' | 'snoozed' | 'drafts'

export interface InboxFilters {
  unreadOnly: boolean
  folder: InboxFolder
  channel: ChannelKind | null
  q: string
  /** [from, to] received-date range; nulls = no bound. */
  dateRange: [Date | null, Date | null] | null
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

  // ─── Reading-pane selection (declared early so filter actions can clear it) ────
  const selectedId = ref<number | null>(null)
  const selectedMessage = ref<InboundMessage | null>(null)

  // ─── Filter state ─────────────────────────────────────────────────────────────
  const filters = ref<InboxFilters>({
    unreadOnly: true,
    folder: 'all',
    channel: null,
    q: '',
    dateRange: null,
  })

  /** Drafts is a distinct mode: separate endpoint + editor render, no message list. */
  const isDraftsFolder = computed(() => filters.value.folder === 'drafts')

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

  const hasDateRange = computed(() => {
    const r = filters.value.dateRange
    return !!r && (r[0] !== null || r[1] !== null)
  })

  const hasActiveFilters = computed(() => {
    return (
      !filters.value.unreadOnly ||
      filters.value.folder !== 'all' ||
      filters.value.channel !== null ||
      filters.value.q.trim() !== '' ||
      hasDateRange.value
    )
  })

  function resetFilters() {
    if (searchDebounceTimer) clearTimeout(searchDebounceTimer)
    filters.value = {
      unreadOnly: true,
      folder: 'all',
      channel: null,
      q: '',
      dateRange: null,
    }
    debouncedQ.value = ''
    currentPage.value = 1
  }

  function setFolder(folder: InboxFolder) {
    // Guard against silently discarding a half-typed draft when navigating away
    // from the Drafts folder. Entering Drafts (or staying) needs no guard.
    const leavingDraftsDirty =
      filters.value.folder === 'drafts' && folder !== 'drafts' && draftDirty.value
    const apply = () => {
      filters.value.folder = folder
      currentPage.value = 1
      // Leaving a message-list folder for drafts (or back) resets selection focus.
      if (folder === 'drafts') {
        selectedId.value = null
        selectedMessage.value = null
      }
    }
    if (leavingDraftsDirty) {
      guardDraftDirty(apply)
    } else {
      apply()
    }
  }

  function setChannel(channel: ChannelKind | null) {
    filters.value.channel = channel
    currentPage.value = 1
  }

  function setUnreadOnly(value: boolean) {
    filters.value.unreadOnly = value
    currentPage.value = 1
  }

  function setDateRange(range: [Date | null, Date | null] | null) {
    filters.value.dateRange = range
    currentPage.value = 1
  }

  // ─── Pagination state ─────────────────────────────────────────────────────────
  const currentPage = ref(1)
  const perPage = ref(DEFAULT_PER_PAGE)
  const totalRecords = ref(0)

  // ─── List resource ─────────────────────────────────────────────────────────────
  const listResource = useAsyncResource<InboundMessage[]>([])

  // ─── Counts (folder + channel badges) ──────────────────────────────────────────
  const counts = ref<InboxCounts | null>(null)

  /** Cheap single call; refetched after list load + any triage/read mutation. */
  async function fetchCounts() {
    try {
      counts.value = await inboxApi.counts()
    } catch {
      // non-critical — badges just stay stale
    }
  }

  async function fetchMessages() {
    // Drafts is served by a separate endpoint (see fetchDrafts).
    if (isDraftsFolder.value) {
      void fetchDrafts()
      return
    }

    const params: Parameters<typeof inboxApi.list>[0] = {
      page: currentPage.value,
      per_page: perPage.value,
    }

    if (filters.value.unreadOnly) params.unread = true
    switch (filters.value.folder) {
      case 'starred':
        params.starred = true
        break
      case 'important':
        params.important = true
        break
      case 'failed':
        params.routing_status = 'failed'
        break
      case 'deals':
        params.has_deal = true
        break
      case 'snoozed':
        params.snoozed = true
        break
    }
    if (filters.value.channel) params.channel = filters.value.channel
    if (debouncedQ.value.trim()) params.q = debouncedQ.value.trim()

    const range = filters.value.dateRange
    if (range) {
      if (range[0]) params.date_from = localDateString(range[0])
      if (range[1]) params.date_to = localDateString(range[1])
    }

    // The loader returns the rows (the resource's T = InboundMessage[]); the
    // page total is carried out-of-band and applied in the commit phase, which
    // only runs for the current request token. Writing totalRecords inside the
    // loader (as before) let a late stale response desync the paginator
    // ("3 rows, of 200, 7 pages") even though the gate protected the rows.
    let pendingTotal = 0
    await listResource.run(
      async () => {
        const result = await inboxApi.list(params)
        pendingTotal = result.meta.total
        return result.data
      },
      {
        commit: (rows) => {
          listResource.data.value = rows
          totalRecords.value = pendingTotal
        },
      },
    )

    // Refresh the sidebar badge + folder/channel counts after each list load.
    void inboxStore.fetchUnreadCount()
    void fetchCounts()
  }

  // When a search commits we reset to page 1. If the page actually changes, the
  // main watcher below already refetches — so we suppress the search watcher's
  // own fetch to avoid two identical requests (each of which also pulls counts).
  let suppressSearchFetch = false

  // Refetch on filter changes (watch debounced q separately)
  watch(
    [
      () => filters.value.unreadOnly,
      () => filters.value.folder,
      () => filters.value.channel,
      () => filters.value.dateRange,
      currentPage,
      perPage,
    ],
    () => {
      void fetchMessages()
    },
    { deep: true },
  )

  watch(debouncedQ, () => {
    if (currentPage.value !== 1) {
      // The page change triggers the main watcher's fetch; don't double-fetch.
      suppressSearchFetch = true
      currentPage.value = 1
    }
    if (suppressSearchFetch) {
      suppressSearchFetch = false
      return
    }
    void fetchMessages()
  })

  // Initial load
  void fetchMessages()
  void fetchCounts()

  // ─── Reading pane (selection + detail) ─────────────────────────────────────────
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
      void fetchCounts()
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
      void fetchCounts()
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

  /** Drop a row from the visible list (e.g. it left the current folder's scope). */
  function _removeRowFromList(id: number) {
    const idx = listResource.data.value.findIndex((m) => m.id === id)
    if (idx >= 0) {
      listResource.data.value.splice(idx, 1)
      totalRecords.value = Math.max(0, totalRecords.value - 1)
    }
  }

  // ─── Star / Important / Snooze (СРЕЗ B triage flags — shared mailbox) ──────────
  const starMutation = useMutation<InboundMessage>()
  const importantMutation = useMutation<InboundMessage>()
  const snoozeMutation = useMutation<InboundMessage>()

  /** Toggle star on a message. Optimistic + idempotent; refetches counts. */
  async function toggleStar(id: number) {
    const current = _findMessage(id)
    const nextStarred = !current?.starred_at
    const optimistic = nextStarred ? new Date().toISOString() : null
    _applyFlag(id, { starred_at: optimistic })

    try {
      const updated = await starMutation.run(() =>
        nextStarred ? inboxApi.star(id) : inboxApi.unstar(id),
      )
      _applyFlag(id, updated)
      // Un-starring inside the "Помеченные" folder removes the row.
      if (!nextStarred && filters.value.folder === 'starred') _removeRowFromList(id)
      void fetchCounts()
    } catch {
      _applyFlag(id, { starred_at: current?.starred_at ?? null })
    }
  }

  /** Toggle "important" on a message. Optimistic + idempotent; refetches counts. */
  async function toggleImportant(id: number) {
    const current = _findMessage(id)
    const next = !current?.important
    _applyFlag(id, { important: next })

    try {
      const updated = await importantMutation.run(() =>
        next ? inboxApi.markImportant(id) : inboxApi.unmarkImportant(id),
      )
      _applyFlag(id, updated)
      if (!next && filters.value.folder === 'important') _removeRowFromList(id)
      void fetchCounts()
    } catch {
      _applyFlag(id, { important: current?.important ?? false })
    }
  }

  /** Toggle important flag of the currently-selected message (reading-pane toolbar). */
  function toggleSelectedImportant() {
    if (selectedMessage.value) void toggleImportant(selectedMessage.value.id)
  }

  /** Snooze the given message until an ISO-8601 timestamp. Optimistic; refetches counts. */
  async function snooze(id: number, until: string) {
    const current = _findMessage(id)
    _applyFlag(id, { snoozed_until: until })
    // In "Входящие" a newly-snoozed message disappears from the flow.
    if (filters.value.folder === 'all') _removeRowFromList(id)

    try {
      const updated = await snoozeMutation.run(() => inboxApi.snooze(id, until))
      _applyFlag(id, updated)
      void fetchCounts()
      void inboxStore.fetchUnreadCount()
      toast.add({ severity: 'success', summary: t('inbox.snooze.toastSnoozed'), life: 3000 })
    } catch {
      _applyFlag(id, { snoozed_until: current?.snoozed_until ?? null })
      // Snooze failed → refetch to restore the row we optimistically hid.
      if (filters.value.folder === 'all') void fetchMessages()
      toast.add({ severity: 'error', summary: t('inbox.snooze.toastFailed'), life: 4000 })
    }
  }

  /** Un-snooze (early return). Optimistic; refetches counts. */
  async function unsnooze(id: number) {
    const current = _findMessage(id)
    _applyFlag(id, { snoozed_until: null })
    if (filters.value.folder === 'snoozed') _removeRowFromList(id)

    try {
      const updated = await snoozeMutation.run(() => inboxApi.unsnooze(id))
      _applyFlag(id, updated)
      void fetchCounts()
      void inboxStore.fetchUnreadCount()
    } catch {
      _applyFlag(id, { snoozed_until: current?.snoozed_until ?? null })
      if (filters.value.folder === 'snoozed') void fetchMessages()
    }
  }

  function _findMessage(id: number): InboundMessage | undefined {
    if (selectedMessage.value?.id === id) return selectedMessage.value
    return listResource.data.value.find((m) => m.id === id)
  }

  /** Apply a triage-flag partial to both the list row and the open reading pane. */
  function _applyFlag(id: number, partial: Partial<InboundMessage>) {
    _updateRowInList(id, partial)
    if (selectedMessage.value?.id === id) {
      selectedMessage.value = { ...selectedMessage.value, ...partial }
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
        void fetchCounts()
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

  // ─── Drafts (per-author saved reply notes; no send in СРЕЗ B) ──────────────────
  const draftsResource = useAsyncResource<InboxDraft[]>([])
  const selectedDraftId = ref<number | null>(null)
  const draftDirty = ref(false)
  /** Editor buffer for the currently-open / new draft. */
  const draftForm = ref<{ subject: string; body: string; related_message_id: number | null }>({
    subject: '',
    body: '',
    related_message_id: null,
  })
  const draftSaveMutation = useMutation<InboxDraft>()
  const draftDeleteMutation = useMutation<void>()

  async function fetchDrafts() {
    // Honour the current page/perPage (was hard-coded to page 1, so the paginator
    // could never reach drafts beyond the first page). Total is applied in the
    // commit phase so a stale response can't desync the paginator.
    let pendingTotal = 0
    await draftsResource.run(
      async () => {
        const result = await inboxApi.listDrafts(currentPage.value, perPage.value)
        pendingTotal = result.meta.total
        return result.data
      },
      {
        commit: (rows) => {
          draftsResource.data.value = rows
          totalRecords.value = pendingTotal
        },
      },
    )
    void fetchCounts()
  }

  /**
   * Run `action` immediately, unless the current draft has unsaved edits — then
   * ask for confirmation first so switching drafts / starting a new one / leaving
   * the folder can't silently discard a half-typed reply.
   */
  function guardDraftDirty(action: () => void) {
    if (!draftDirty.value) {
      action()
      return
    }
    confirm.require({
      header: t('inbox.drafts.discardTitle'),
      message: t('inbox.drafts.discardBody'),
      icon: 'pi pi-exclamation-triangle',
      acceptLabel: t('inbox.drafts.discardAccept'),
      rejectLabel: t('common.cancel'),
      acceptProps: { severity: 'danger' },
      accept: () => {
        draftDirty.value = false
        action()
      },
    })
  }

  /** Open an existing draft into the editor (guards unsaved edits). */
  function openDraft(draft: InboxDraft) {
    guardDraftDirty(() => {
      selectedDraftId.value = draft.id
      draftForm.value = {
        subject: draft.subject ?? '',
        body: draft.body ?? '',
        related_message_id: draft.related_message_id,
      }
      draftDirty.value = false
      mobileView.value = 'detail'
    })
  }

  /** Start a fresh blank draft (from the "Черновики" pane) — guards unsaved edits. */
  function startNewDraft() {
    guardDraftDirty(() => {
      selectedDraftId.value = null
      draftForm.value = { subject: '', body: '', related_message_id: null }
      draftDirty.value = true // new blank is "dirty" so Save is offered
      mobileView.value = 'detail'
    })
  }

  function markDraftDirty() {
    draftDirty.value = true
  }

  /** Create a reply draft from the open message ("Черновик" button in reader). */
  async function draftReplyToSelected() {
    const msg = selectedMessage.value
    if (!msg) return
    const subject = msg.subject ? `Re: ${msg.subject}` : ''
    try {
      const created = await draftSaveMutation.run(() =>
        inboxApi.createDraft({ related_message_id: msg.id, subject, body: '' }),
      )
      // Jump into the Drafts folder with the new draft open in the editor.
      setFolder('drafts')
      draftsResource.data.value = [created, ...draftsResource.data.value]
      openDraft(created)
      void fetchCounts()
      toast.add({ severity: 'success', summary: t('inbox.drafts.toastCreated'), life: 3000 })
    } catch {
      toast.add({ severity: 'error', summary: t('inbox.drafts.toastSaveFailed'), life: 4000 })
    }
  }

  /** Persist the editor buffer (create or update). */
  async function saveDraft() {
    const payload = {
      subject: draftForm.value.subject.trim() || null,
      body: draftForm.value.body.trim() || null,
      related_message_id: draftForm.value.related_message_id,
    }
    try {
      if (selectedDraftId.value === null) {
        const created = await draftSaveMutation.run(() => inboxApi.createDraft(payload))
        draftsResource.data.value = [created, ...draftsResource.data.value]
        selectedDraftId.value = created.id
      } else {
        const id = selectedDraftId.value
        const updated = await draftSaveMutation.run(() => inboxApi.updateDraft(id, payload))
        const idx = draftsResource.data.value.findIndex((d) => d.id === id)
        if (idx >= 0) draftsResource.data.value[idx] = updated
      }
      draftDirty.value = false
      void fetchCounts()
      toast.add({ severity: 'success', summary: t('inbox.drafts.toastSaved'), life: 2500 })
    } catch {
      toast.add({ severity: 'error', summary: t('inbox.drafts.toastSaveFailed'), life: 4000 })
    }
  }

  /** Delete the currently-open draft. */
  async function deleteDraft() {
    const id = selectedDraftId.value
    if (id === null) {
      // Unsaved blank — just discard locally.
      selectedDraftId.value = null
      draftDirty.value = false
      mobileView.value = 'list'
      return
    }
    try {
      await draftDeleteMutation.run(() => inboxApi.deleteDraft(id))
      const idx = draftsResource.data.value.findIndex((d) => d.id === id)
      if (idx >= 0) {
        draftsResource.data.value.splice(idx, 1)
        totalRecords.value = Math.max(0, totalRecords.value - 1)
      }
      selectedDraftId.value = null
      draftDirty.value = false
      mobileView.value = 'list'
      void fetchCounts()
      toast.add({ severity: 'success', summary: t('inbox.drafts.toastDeleted'), life: 2500 })
    } catch {
      toast.add({ severity: 'error', summary: t('inbox.drafts.toastDeleteFailed'), life: 4000 })
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

    // Counts (folder + channel badges)
    counts,

    // Filters
    filters,
    hasActiveFilters,
    isDraftsFolder,
    onSearchInput,
    resetFilters,
    setFolder,
    setChannel,
    setUnreadOnly,
    setDateRange,

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

    // Star / important / snooze
    toggleStar,
    toggleImportant,
    toggleSelectedImportant,
    snooze,
    unsnooze,
    starPending: starMutation.isPending,
    importantPending: importantMutation.isPending,
    snoozePending: snoozeMutation.isPending,

    // Reprocess
    reprocessPending: reprocessMutation.isPending,
    currentReprocessId,
    confirmReprocess,

    // Drafts
    drafts: draftsResource.data,
    draftsLoading: draftsResource.loading,
    draftsError: draftsResource.error,
    selectedDraftId,
    draftForm,
    draftDirty,
    draftSavePending: draftSaveMutation.isPending,
    openDraft,
    startNewDraft,
    markDraftDirty,
    draftReplyToSelected,
    saveDraft,
    deleteDraft,

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
