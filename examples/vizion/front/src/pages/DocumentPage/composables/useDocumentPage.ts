import { computed, ref, watch, watchEffect, onUnmounted } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useDocumentContextStore } from '@/stores/documentContext'
import { useDocumentGenerationModalStore } from '@/stores/documentGenerationModal'
import { canManageDocuments } from '@/shared/auth/capabilities'
import type { DocumentTemplate } from '@/entities/document'
import type { DocumentGenerateParams, GeneratedDocumentFormat } from '@/api/types/documents'
import { useDocumentPageData } from './useDocumentPageData'
import { useDocumentPageActions } from './useDocumentPageActions'
import { useDocumentDocx } from './useDocumentDocx'
import { useDocumentFieldsProposal } from './useDocumentFieldsProposal'

export const useDocumentPage = () => {
  const data = useDocumentPageData()
  const router = useRouter()
  const userStore = useUserStore()
  const documentContextStore = useDocumentContextStore()
  const documentGenerationModal = useDocumentGenerationModalStore()

  // docx flow = template type 'docx'; html flow keeps `isHtml`.
  const isDocx = computed(() => data.template.value?.type === 'docx')
  // Upload / placeholder-mapping / reference-editing gate. Viewer (and any role
  // without canManageDocuments) only consumes a preset template: object +
  // discount + generate + download. System templates are read-only too.
  const canManage = computed(
    () =>
      canManageDocuments(userStore.getUserRole) &&
      data.template.value?.isSystem !== true,
  )

  const docx = useDocumentDocx({
    t: data.t,
    locale: data.locale,
    documentId: data.documentId,
    template: data.template,
    isDocx,
    canManage,
  })

  // ── AI auto-mapping (docx, scope=document) ─────────────────────────────────
  const fieldsProposal = useDocumentFieldsProposal({
    t: data.t,
    documentId: data.documentId,
    canManage,
  })

  /** Accept one proposed mapping → merge into the draft and persist. */
  const acceptProposal = async (token: string) => {
    const proposal = fieldsProposal.proposals.value.find((p) => p.token === token)
    if (!proposal) return
    docx.applyMappings({ [proposal.token]: proposal.suggested_field })
    fieldsProposal.dismissProposal(token)
    await docx.saveMapping()
  }

  /** Accept all proposed mappings in one persist. */
  const acceptAllProposals = async () => {
    const entries: Record<string, string> = {}
    for (const p of fieldsProposal.proposals.value) {
      entries[p.token] = p.suggested_field
    }
    if (Object.keys(entries).length === 0) return
    docx.applyMappings(entries)
    fieldsProposal.clearProposals()
    await docx.saveMapping()
  }

  // Generation uses the same selectors as the live preview (object / promotion
  // / discount). A null discount when no promotion is picked means "no discount
  // block" on the backend.
  const generateParams = (): DocumentGenerateParams => ({
    estate_sell_id: data.selectedEstateSellId.value ?? undefined,
    promotion_id: data.selectedPromotionId.value,
    discount: data.selectedPromotionId.value !== null ? data.discount.value : null,
  })

  // HTML auto-downloads PDF on ready (one-click UX); docx shows explicit
  // DOCX / PDF buttons instead of auto-downloading.
  const autoDownloadFormat = computed<GeneratedDocumentFormat | null>(() =>
    isDocx.value ? null : 'pdf',
  )

  const actions = useDocumentPageActions({
    t: data.t,
    documentId: data.documentId,
    generateParams,
    autoDownloadFormat,
  })

  // ── Actions menu (info / publish / delete / edit) ──────────────────────────
  const editModalVisible = ref(false)

  const openEditModal = () => {
    editModalVisible.value = true
  }

  /** Publish / unpublish DTO from the menu — swap the template in place. */
  const onTemplateUpdated = (updated: DocumentTemplate) => {
    data.setTemplate(updated)
  }

  /** Edit modal saved — swap the template + re-render the HTML preview. */
  const onTemplateEdited = (updated: DocumentTemplate) => {
    data.setTemplate(updated)
    void data.reloadTemplate()
  }

  /** Delete from the menu — leave the now-orphaned page for the library. */
  const onTemplateDeleted = () => {
    void router.push('/documents')
  }

  // After an AI edit-turn settles for THIS template, refetch so the page
  // reflects the AI's content changes (mirror of ReportPage ↔ reportUpdatedTick).
  watch(
    () => documentGenerationModal.documentUpdatedTick,
    () => {
      if (documentGenerationModal.lastUpdatedDocumentId === data.documentId.value) {
        void data.reloadTemplate()
      }
    },
  )

  // ── Mini-chat document context (mirror of ReportPage → reportContext) ──
  watchEffect(() => {
    const template = data.template.value
    if (!template) {
      documentContextStore.clear()
      return
    }
    documentContextStore.set({
      documentId: template.id,
      type: template.type,
      title: data.templateName.value,
      placeholderCount: docx.placeholders.value.length,
      mappedCount: docx.mappedCount.value,
    })
  })

  onUnmounted(() => {
    documentContextStore.clear()
    fieldsProposal.reset()
  })

  return {
    ...data,
    ...docx,
    ...actions,
    isDocx,
    canManageDocuments: canManage,
    // actions menu + edit modal
    editModalVisible,
    openEditModal,
    onTemplateUpdated,
    onTemplateEdited,
    onTemplateDeleted,
    // AI auto-mapping
    aiProposals: fieldsProposal.proposals,
    aiHasProposals: fieldsProposal.hasProposals,
    aiRunning: fieldsProposal.isRunning,
    proposeFields: fieldsProposal.propose,
    dismissProposal: fieldsProposal.dismissProposal,
    clearProposals: fieldsProposal.clearProposals,
    acceptProposal,
    acceptAllProposals,
  }
}
