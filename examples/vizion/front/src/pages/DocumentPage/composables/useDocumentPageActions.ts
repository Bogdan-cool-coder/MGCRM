import { computed, ref, onUnmounted, type Ref } from 'vue'
import { useRouter } from 'vue-router'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { downloadBlob } from '@/utils/fileDownload'
import { useUserStore } from '@/stores/user'
import { canManagePromotions } from '@/shared/auth/capabilities'
import type {
  DocumentGenerateParams,
  GeneratedDocumentFormat,
} from '@/api/types/documents'

/** Poll interval for the generation status endpoint. */
const POLL_INTERVAL_MS = 1500
/** Hard cap on polling before surfacing a timeout error. */
const POLL_TIMEOUT_MS = 120_000

type Translate = (key: string) => string

interface UseDocumentPageActionsArgs {
  t: Translate
  documentId: Ref<number>
  generateParams: () => DocumentGenerateParams
  /**
   * Format to auto-download when generation finishes, or `null` to skip the
   * auto-download and let the UI offer explicit format buttons (docx flow:
   * DOCX + PDF). HTML flow passes `'pdf'` to preserve its one-click UX.
   */
  autoDownloadFormat: Ref<GeneratedDocumentFormat | null>
}

/** UI state of the generate → poll → download flow. */
type GenerationPhase = 'idle' | 'generating' | 'ready' | 'error'

export const useDocumentPageActions = (args: UseDocumentPageActionsArgs) => {
  const { t, documentId, generateParams, autoDownloadFormat } = args
  const router = useRouter()
  const userStore = useUserStore()
  const { notifyApiError, notifySuccess } = useNotifications()
  const { documentService } = useServices()

  const phase = ref<GenerationPhase>('idle')
  const lastGeneratedId = ref<number | null>(null)

  let pollTimer: ReturnType<typeof setTimeout> | null = null
  let pollDeadline = 0

  const isGenerating = computed(() => phase.value === 'generating')

  // The gear shortcut to the promotions settings is only meaningful for users
  // who can manage promotions (admin / superadmin) — same gate as the CRUD UI.
  const canOpenPromotionSettings = computed(() =>
    canManagePromotions(userStore.getUserRole),
  )

  const clearPoll = () => {
    if (pollTimer !== null) {
      clearTimeout(pollTimer)
      pollTimer = null
    }
  }

  const downloadReady = async (
    generatedId: number,
    format: GeneratedDocumentFormat = 'pdf',
  ) => {
    try {
      const blob = await documentService.downloadGenerated(generatedId, format)
      await downloadBlob(blob, `document-${generatedId}.${format}`)
      notifySuccess(
        format === 'docx' ? t('generation.downloadedDocx') : t('generation.downloaded'),
      )
    } catch (error: unknown) {
      // A failed download must not flip the ready generation back to "error":
      // the file exists and can be retried via the explicit buttons.
      notifyApiError(error, t('errors.download'))
    }
  }

  const pollStatus = async (generatedId: number) => {
    try {
      const generated = await documentService.fetchGeneratedStatus(generatedId)

      if (generated.status === 'done') {
        clearPoll()
        phase.value = 'ready'
        // HTML flow auto-downloads PDF; docx flow leaves it to the explicit
        // DOCX / PDF buttons (autoDownloadFormat === null).
        if (autoDownloadFormat.value !== null) {
          await downloadReady(generatedId, autoDownloadFormat.value)
        }
        return
      }

      if (generated.status === 'error') {
        clearPoll()
        phase.value = 'error'
        notifyApiError(
          new Error(generated.error ?? t('errors.generate')),
          t('errors.generate'),
        )
        return
      }

      // still pending / processing — keep polling until the deadline.
      if (Date.now() >= pollDeadline) {
        clearPoll()
        phase.value = 'error'
        notifyApiError(new Error(t('errors.timeout')), t('errors.timeout'))
        return
      }
      pollTimer = setTimeout(() => void pollStatus(generatedId), POLL_INTERVAL_MS)
    } catch (error: unknown) {
      clearPoll()
      phase.value = 'error'
      notifyApiError(error, t('errors.generate'))
    }
  }

  /** Kick off async generation, then poll until done → download the PDF. */
  const generate = async () => {
    const id = documentId.value
    if (!id || id <= 0 || isGenerating.value) return

    clearPoll()
    phase.value = 'generating'
    lastGeneratedId.value = null

    try {
      const generatedId = await documentService.generate(id, generateParams())
      lastGeneratedId.value = generatedId
      pollDeadline = Date.now() + POLL_TIMEOUT_MS
      pollTimer = setTimeout(() => void pollStatus(generatedId), POLL_INTERVAL_MS)
    } catch (error: unknown) {
      phase.value = 'error'
      notifyApiError(error, t('errors.generate'))
    }
  }

  /**
   * Re-download an already-finished generation without regenerating. Format
   * defaults to PDF; the docx flow passes `'docx'` for its second button.
   */
  const downloadAgain = async (format: GeneratedDocumentFormat = 'pdf') => {
    if (lastGeneratedId.value !== null) {
      await downloadReady(lastGeneratedId.value, format)
    }
  }

  /** Gear → company settings, Promotions sub-section. */
  const openPromotionSettings = () => {
    void router.push({ path: '/company', query: { tab: 'promotions' } })
  }

  onUnmounted(clearPoll)

  return {
    phase,
    isGenerating,
    canOpenPromotionSettings,
    generate,
    downloadAgain,
    openPromotionSettings,
  }
}
