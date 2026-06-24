<template>
  <div class="attachments-tab">
    <div class="d-flex align-items-center justify-content-between mb-3">
      <span />
      <Button
        v-if="canUpload"
        icon="pi pi-upload"
        :label="t('documents.attachments.upload')"
        severity="secondary"
        outlined
        size="small"
        @click="uploadDialogVisible = true"
      />
    </div>

    <!-- Loading -->
    <div v-if="loading">
      <Skeleton height="40px" class="mb-2" v-for="i in 2" :key="i" />
    </div>

    <DataTable
      v-else
      :value="attachments"
      size="small"
      row-hover
    >
      <Column :header="t('documents.attachments.columns.type')" style="width: 160px">
        <template #body="{ data }">
          <Tag
            :severity="kindSeverity(data.kind)"
            :value="t(`documents.attachments.kinds.${data.kind}`, data.kind)"
          />
        </template>
      </Column>
      <Column :header="t('documents.attachments.columns.file')">
        <template #body="{ data }">{{ data.original_name }}</template>
      </Column>
      <Column :header="t('documents.attachments.columns.uploader')" style="width: 160px">
        <template #body="{ data }">
          {{ data.uploaded_by_name ?? '—' }}
          <span class="text-secondary ms-1">{{ formatDate(data.created_at) }}</span>
        </template>
      </Column>
      <Column style="width: 80px">
        <template #body="{ data }">
          <span class="d-flex gap-1">
            <Button
              icon="pi pi-download"
              text
              severity="secondary"
              size="small"
              :title="t('common.download')"
              @click="download(data.id)"
            />
            <Button
              v-if="canEdit"
              icon="pi pi-trash"
              text
              severity="danger"
              size="small"
              :title="t('common.delete')"
              @click="removeAttachment(data.id)"
            />
          </span>
        </template>
      </Column>

      <template #empty>
        <div class="attachments-tab__empty">
          <i class="pi pi-file" />
          <span>{{ t('documents.attachments.empty') }}</span>
          <Button
            v-if="canUpload"
            :label="t('documents.attachments.upload')"
            icon="pi pi-upload"
            severity="secondary"
            outlined
            size="small"
            @click="uploadDialogVisible = true"
          />
        </div>
      </template>
    </DataTable>

    <!-- Upload dialog -->
    <Dialog
      v-model:visible="uploadDialogVisible"
      :header="t('documents.attachments.upload')"
      modal
      :style="{ width: '28rem' }"
    >
      <div class="mb-3">
        <label class="attachments-tab__label">
          {{ t('documents.card.tabs.attachments') }} Тип *
        </label>
        <SelectButton
          v-model="uploadKind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
          class="mt-1 w-100"
        />
      </div>
      <div class="mb-3">
        <FileUpload
          mode="basic"
          :accept="'.pdf,.docx,.jpg,.jpeg,.png'"
          :max-file-size="20 * 1024 * 1024"
          :choose-label="t('documents.attachments.upload')"
          :auto="false"
          custom-upload
          @select="onFileSelect"
        />
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          @click="uploadDialogVisible = false"
        />
        <Button
          :label="t('common.upload', 'Загрузить')"
          :loading="uploading"
          :disabled="!selectedFile"
          @click="doUpload"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Dialog from 'primevue/dialog'
import SelectButton from 'primevue/selectbutton'
import FileUpload from 'primevue/fileupload'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { documentsApi } from '@/api/documents'
import type { DocumentAttachmentDto, AttachmentKind } from '@/entities/document'

type TagSeverity = 'secondary' | 'info' | 'success' | 'warn' | 'danger' | 'contrast'

const props = defineProps<{
  docId: number
  canEdit: boolean
  status: string
}>()

// Upload is allowed in draft/rejected/needs_rework/in_review (backend S2.8 + BUG-ATTACH-1)
const canUpload = computed(() =>
  props.canEdit || props.status === 'in_review' || props.status === 'needs_rework',
)

const emit = defineEmits<{
  hasScanChange: [value: boolean]
}>()

const { t } = useI18n()
const toast = useToast()

const resource = useAsyncResource<DocumentAttachmentDto[]>(() => [])
const attachments = computed(() => resource.data.value)
const loading = computed(() => resource.loading.value)

watch(() => props.docId, () => {
  void resource.run(() => documentsApi.getDocumentAttachments(props.docId))
}, { immediate: true })

// Emit hasScan when attachments change
watch(attachments, (list) => {
  const hasScan = list.some((a) => a.kind === 'signed_scan')
  emit('hasScanChange', hasScan)
}, { immediate: true })

// ─── Upload ────────────────────────────────────────────────────────────────
const uploadDialogVisible = ref(false)
const uploadKind = ref<AttachmentKind>('signed_scan')
const uploading = ref(false)
const selectedFile = ref<File | null>(null)

const kindOptions = computed(() => [
  { label: t('documents.attachments.kinds.signed_scan'), value: 'signed_scan' as AttachmentKind },
  { label: t('documents.attachments.kinds.payment'), value: 'payment' as AttachmentKind },
  { label: t('documents.attachments.kinds.other'), value: 'other' as AttachmentKind },
])

function onFileSelect(event: { files: File[] }) {
  selectedFile.value = event.files[0] ?? null
}

async function doUpload() {
  if (!selectedFile.value) return
  uploading.value = true
  try {
    const att = await documentsApi.uploadAttachment(props.docId, selectedFile.value, uploadKind.value)
    resource.data.value = [...resource.data.value, att]
    uploadDialogVisible.value = false
    selectedFile.value = null
    toast.add({ severity: 'success', summary: t('documents.attachments.upload'), life: 3000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    uploading.value = false
  }
}

async function removeAttachment(id: number) {
  try {
    await documentsApi.deleteAttachment(props.docId, id)
    resource.data.value = resource.data.value.filter((a) => a.id !== id)
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

function download(attachmentId: number) {
  const url = documentsApi.getAttachmentDownloadUrl(props.docId, attachmentId)
  window.open(url, '_blank')
}

function kindSeverity(kind: AttachmentKind): TagSeverity {
  const map: Record<AttachmentKind, TagSeverity> = {
    signed_scan: 'success',
    payment: 'info',
    other: 'secondary',
  }
  return map[kind] ?? 'secondary'
}

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}
</script>

<style lang="scss" scoped>
.attachments-tab {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 0.75rem;
    padding: 2rem;
    color: var(--p-text-muted-color);
  }
}
</style>
