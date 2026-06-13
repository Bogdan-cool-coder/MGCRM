/**
 * Document attachments composable — upload/download/delete.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { documentsApi } from '@/api/documents'
import type { DocumentAttachmentDto, AttachmentKind } from '@/entities/document'
import type { Ref } from 'vue'

export const useDocumentAttachments = (docId: Ref<number>) => {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<DocumentAttachmentDto[]>(() => [])
  const attachments = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  async function fetchAttachments() {
    await resource.run(() => documentsApi.getDocumentAttachments(docId.value))
  }

  // ─── Upload dialog ─────────────────────────────────────────────────────────
  const uploadDialogVisible = ref(false)
  const uploading = ref(false)
  const uploadKind = ref<AttachmentKind>('signed_scan')

  async function uploadAttachment(file: File, kind: AttachmentKind) {
    uploading.value = true
    try {
      const att = await documentsApi.uploadAttachment(docId.value, file, kind)
      resource.data.value = [...(resource.data.value ?? []), att]
      uploadDialogVisible.value = false
      toast.add({ severity: 'success', summary: t('documents.attachments.upload'), life: 3000 })
      return att
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    } finally {
      uploading.value = false
    }
  }

  async function deleteAttachment(attachmentId: number) {
    try {
      await documentsApi.deleteAttachment(docId.value, attachmentId)
      resource.data.value = resource.data.value.filter((a) => a.id !== attachmentId)
      toast.add({ severity: 'info', summary: t('common.delete'), life: 2000 })
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  function getDownloadUrl(attachmentId: number): string {
    return documentsApi.getAttachmentDownloadUrl(docId.value, attachmentId)
  }

  const hasSignedScan = computed(() =>
    (resource.data.value ?? []).some((a) => a.kind === 'signed_scan'),
  )

  return {
    attachments,
    loading,
    fetchAttachments,
    uploadDialogVisible,
    uploading,
    uploadKind,
    uploadAttachment,
    deleteAttachment,
    getDownloadUrl,
    hasSignedScan,
  }
}
