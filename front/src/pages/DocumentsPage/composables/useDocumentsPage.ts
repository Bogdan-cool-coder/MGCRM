/**
 * DocumentsPage composable — filters, pagination, data, actions.
 * Server-state via useAsyncResource; no raw axios in components.
 */
import { ref, computed, watch } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { documentsApi } from '@/api/documents'
import { useUserStore } from '@/stores/user'
import type {
  DocumentListItemDto,
  DocumentListParams,
  ContractStatus,
  DocumentKind,
} from '@/entities/document'

export const useDocumentsPage = () => {
  const { t } = useI18n()
  const router = useRouter()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()

  // ─── Filter state ──────────────────────────────────────────────────────────
  const filter = ref<{
    status: ContractStatus | null
    kind: DocumentKind | null
    product_code: string | null
    country_code: string | null
    author_user_id: number | null
    search: string
    archived: boolean
  }>({
    status: null,
    kind: null,
    product_code: null,
    country_code: null,
    author_user_id: null,
    search: '',
    archived: false,
  })

  const page = ref(1)
  const perPage = 25

  // ─── Server-state ─────────────────────────────────────────────────────────
  const documentsResource = useAsyncResource<{
    items: DocumentListItemDto[]
    total: number
  }>(() => ({ items: [], total: 0 }))

  const documents = computed(() => documentsResource.data.value.items)
  const total = computed(() => documentsResource.data.value.total)
  const loading = computed(() => documentsResource.loading.value)

  async function fetchDocuments() {
    const params: DocumentListParams = {
      per_page: perPage,
      page: page.value,
    }
    if (filter.value.status) params.status = filter.value.status
    if (filter.value.kind) params.kind = filter.value.kind
    if (filter.value.product_code) params.product_code = filter.value.product_code
    if (filter.value.country_code) params.country_code = filter.value.country_code
    if (filter.value.author_user_id) params.author_user_id = filter.value.author_user_id
    if (filter.value.search) params.search = filter.value.search
    if (filter.value.archived) params.archived = true

    await documentsResource.run(async () => {
      const resp = await documentsApi.getDocuments(params)
      return { items: resp.data, total: resp.meta.total }
    })
  }

  // Reset to page 1 without double-triggering the page watcher below. Only arm
  // suppression when the page actually changes, otherwise the flag would linger
  // and swallow the next genuine page navigation.
  let suppressPageWatch = false
  function resetPage() {
    if (page.value !== 1) {
      suppressPageWatch = true
      page.value = 1
    }
  }

  // Filter changes MUST reset the page to 1 — otherwise switching to a filter
  // with fewer results while on e.g. page 3 requests an out-of-range page and
  // returns an empty table ("nothing found") even though matches exist.
  // The search field is debounced so typing doesn't fire a request per keystroke.
  let searchDebounce: ReturnType<typeof setTimeout> | null = null
  watch(
    () => filter.value.search,
    () => {
      if (searchDebounce) clearTimeout(searchDebounce)
      searchDebounce = setTimeout(() => {
        searchDebounce = null
        resetPage()
        void fetchDocuments()
      }, 300)
    },
  )

  watch(
    [
      () => filter.value.status,
      () => filter.value.kind,
      () => filter.value.product_code,
      () => filter.value.country_code,
      () => filter.value.author_user_id,
      () => filter.value.archived,
    ],
    () => {
      resetPage()
      void fetchDocuments()
    },
  )

  watch(page, () => {
    if (suppressPageWatch) {
      suppressPageWatch = false
      return
    }
    void fetchDocuments()
  })

  // Initial load
  void fetchDocuments()

  function resetFilters() {
    // Only the filter changes here — the filter watchers reset the page to 1 and
    // refetch, so we don't touch `page` directly (that would double-fetch).
    filter.value = {
      status: null,
      kind: null,
      product_code: null,
      country_code: null,
      author_user_id: null,
      search: '',
      archived: false,
    }
  }

  const hasActiveFilters = computed(() => {
    const f = filter.value
    return !!(f.status || f.kind || f.product_code || f.country_code || f.author_user_id || f.search || f.archived)
  })

  // ─── Row actions ──────────────────────────────────────────────────────────

  function onRowClick(docId: number) {
    void router.push({ name: 'DocumentDetail', params: { id: docId } })
  }

  // ─── Create dialog ────────────────────────────────────────────────────────
  const createDialogVisible = ref(false)

  function openCreateDialog() {
    createDialogVisible.value = true
  }

  function onDocumentCreated(docId: number) {
    createDialogVisible.value = false
    void router.push({ name: 'DocumentDetail', params: { id: docId } })
  }

  // ─── Archive action ───────────────────────────────────────────────────────
  const archiveMutation = useMutation<void>()

  function archiveDoc(doc: DocumentListItemDto) {
    confirm.require({
      message: t('documents.list.archiveConfirm', 'Архивировать документ?'),
      header: t('common.confirm'),
      icon: 'pi pi-box',
      accept: async () => {
        await archiveMutation.run(async () => {
          await documentsApi.archiveDocument(doc.id)
          toast.add({ severity: 'info', summary: t('common.archive'), life: 3000 })
          void fetchDocuments()
        })
      },
    })
  }

  // ─── Role checks ──────────────────────────────────────────────────────────
  const canCreate = computed(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'lawyer' || role === 'manager'
  })

  const canSeeAuthorFilter = computed(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'lawyer' || role === 'director'
  })

  // ─── Pagination ───────────────────────────────────────────────────────────
  function onPageChange(event: { page: number }) {
    page.value = event.page + 1
  }

  return {
    t,
    filter,
    page,
    perPage,
    documents,
    total,
    loading,
    hasActiveFilters,
    resetFilters,
    onRowClick,
    createDialogVisible,
    openCreateDialog,
    onDocumentCreated,
    archiveDoc,
    canCreate,
    canSeeAuthorFilter,
    onPageChange,
    fetchDocuments,
  }
}
