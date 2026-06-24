/**
 * DocumentPage orchestrator composable.
 * Loads document + coordinates autosave, actions, sub-resource loading.
 */
import { ref, computed, watch, onUnmounted } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { documentsApi } from '@/api/documents'
import { useUserStore } from '@/stores/user'
import type { DocumentDto, ContractStatus } from '@/entities/document'

export const useDocumentPage = () => {
  const route = useRoute()
  const router = useRouter()
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()

  const docId = computed(() => Number(route.params.id))

  // ─── Document data ─────────────────────────────────────────────────────────
  const documentResource = useAsyncResource<DocumentDto | null>(() => null)
  const document = computed(() => documentResource.data.value)
  const loading = computed(() => documentResource.loading.value)
  const loadError = computed(() => documentResource.error.value)

  async function fetchDocument() {
    await documentResource.run(() => documentsApi.getDocument(docId.value))
  }

  watch(docId, () => void fetchDocument(), { immediate: true })

  // ─── Autosave context ──────────────────────────────────────────────────────
  type AutosaveState = 'idle' | 'saving' | 'saved' | 'error'
  const autosaveState = ref<AutosaveState>('idle')
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  function triggerAutosave(context: Record<string, unknown>) {
    autosaveState.value = 'saving'
    if (debounceTimer) clearTimeout(debounceTimer)
    debounceTimer = setTimeout(async () => {
      try {
        await documentsApi.patchDocument(docId.value, { context })
        autosaveState.value = 'saved'
        setTimeout(() => { autosaveState.value = 'idle' }, 2000)
      } catch {
        autosaveState.value = 'error'
      }
    }, 1500)
  }

  onUnmounted(() => {
    if (debounceTimer) clearTimeout(debounceTimer)
  })

  // ─── Generate ──────────────────────────────────────────────────────────────
  const generating = ref(false)

  async function generateDoc() {
    generating.value = true
    try {
      const result = await documentsApi.generateDocument(docId.value)
      await fetchDocument()
      toast.add({
        severity: 'success',
        summary: t('documents.card.actions.generate'),
        detail: t('documents.create.title'),
        life: 3000,
      })
      if (result.warnings?.includes('template_not_checked')) {
        toast.add({
          severity: 'warn',
          summary: t('documents.card.generateWarning.summary'),
          detail: t('documents.card.generateWarning.detail'),
          life: 7000,
        })
      }
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    } finally {
      generating.value = false
    }
  }

  // ─── Submit for approval ──────────────────────────────────────────────────
  const submitting = ref(false)

  function submitForApproval() {
    confirm.require({
      message: t('documents.approval.decide.approve', 'Отправить на согласование?'),
      header: t('common.confirm'),
      icon: 'pi pi-send',
      accept: async () => {
        submitting.value = true
        try {
          const doc = await documentsApi.submitDocument(docId.value)
          documentResource.data.value = doc
          toast.add({ severity: 'success', summary: t('documents.statuses.submitted'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        } finally {
          submitting.value = false
        }
      },
    })
  }

  // ─── Sign ──────────────────────────────────────────────────────────────────
  const signing = ref(false)

  function signDoc() {
    confirm.require({
      message: t('documents.card.actions.sign', 'Подписать документ?'),
      header: t('common.confirm'),
      icon: 'pi pi-pen-to-square',
      accept: async () => {
        signing.value = true
        try {
          const doc = await documentsApi.signDocument(docId.value)
          documentResource.data.value = doc
          toast.add({ severity: 'success', summary: t('documents.statuses.signed'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        } finally {
          signing.value = false
        }
      },
    })
  }

  // ─── Unsign ────────────────────────────────────────────────────────────────
  function unsignDoc() {
    confirm.require({
      message: t('documents.card.actions.unsign', 'Отозвать подпись?'),
      header: t('common.confirm'),
      icon: 'pi pi-undo',
      accept: async () => {
        try {
          const doc = await documentsApi.unsignDocument(docId.value)
          documentResource.data.value = doc
          toast.add({ severity: 'warn', summary: t('documents.card.actions.unsign'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        }
      },
    })
  }

  // ─── Archive / Unarchive ──────────────────────────────────────────────────
  function archiveDoc() {
    confirm.require({
      message: t('documents.card.actions.archive', 'Архивировать документ?'),
      header: t('common.confirm'),
      icon: 'pi pi-box',
      accept: async () => {
        try {
          const doc = await documentsApi.archiveDocument(docId.value)
          documentResource.data.value = doc
          toast.add({ severity: 'info', summary: t('documents.statuses.archived'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        }
      },
    })
  }

  async function unarchiveDoc() {
    try {
      const doc = await documentsApi.unarchiveDocument(docId.value)
      documentResource.data.value = doc
      toast.add({ severity: 'info', summary: t('documents.card.actions.unarchive'), life: 3000 })
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Decide (approval) ────────────────────────────────────────────────────
  const decideMutation = useMutation<void>()
  const decideDialogVisible = ref(false)
  const decideAction = ref<'rejected' | 'needs_rework'>('rejected')

  function openDecideDialog(action: 'rejected' | 'needs_rework') {
    decideAction.value = action
    decideDialogVisible.value = true
  }

  async function approve() {
    await decideMutation.run(async () => {
      const doc = await documentsApi.decideDocument(docId.value, { decision: 'approved' })
      documentResource.data.value = doc
      toast.add({ severity: 'success', summary: t('documents.approval.approved'), life: 3000 })
    })
  }

  async function confirmDecide(comment: string) {
    await decideMutation.run(
      async () => {
        const doc = await documentsApi.decideDocument(docId.value, {
          decision: decideAction.value,
          comment,
        })
        documentResource.data.value = doc
        decideDialogVisible.value = false
        const msg = decideAction.value === 'rejected'
          ? t('documents.approval.rejected')
          : t('documents.approval.needs_rework')
        toast.add({ severity: 'warn', summary: msg, life: 3000 })
      },
    )
  }

  // ─── Download helpers ──────────────────────────────────────────────────────
  function downloadDocx() {
    if (!document.value) return
    const url = documentsApi.getDownloadDocxUrl(docId.value)
    window.open(url, '_blank')
  }

  function downloadPdf() {
    if (!document.value) return
    const url = documentsApi.getDownloadPdfUrl(docId.value)
    window.open(url, '_blank')
  }

  // ─── Role / permission checks ──────────────────────────────────────────────
  const isEditable = computed<boolean>(() => {
    const s = document.value?.status as ContractStatus | undefined
    return s === 'draft' || s === 'rejected' || s === 'needs_rework'
  })

  const isAuthorOrPrivileged = computed<boolean>(() => {
    const role = userStore.getUserRole
    if (role === 'admin' || role === 'lawyer') return true
    const user = userStore.getUser
    return user?.id === document.value?.author_user_id
  })

  /** Unsign is restricted to admin/lawyer only (BE DocumentPolicy.unsign). */
  const canUnsign = computed<boolean>(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'lawyer'
  })

  const canEdit = computed(() => isEditable.value && isAuthorOrPrivileged.value)

  const hasSignedScan = ref(false)
  function setHasSignedScan(v: boolean) { hasSignedScan.value = v }

  return {
    t,
    router,
    docId,
    document,
    loading,
    loadError,
    fetchDocument,
    autosaveState,
    triggerAutosave,
    generating,
    generateDoc,
    submitting,
    submitForApproval,
    signing,
    signDoc,
    unsignDoc,
    archiveDoc,
    unarchiveDoc,
    decideMutation,
    decideDialogVisible,
    decideAction,
    openDecideDialog,
    approve,
    confirmDecide,
    downloadDocx,
    downloadPdf,
    isEditable,
    isAuthorOrPrivileged,
    canUnsign,
    canEdit,
    hasSignedScan,
    setHasSignedScan,
    userStore,
  }
}
