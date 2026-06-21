<template>
  <div class="deal-tab-docs">
    <!-- ── Empty state (no documents yet) ──────────────────────────────────── -->
    <template v-if="!loading && documents.length === 0">
      <div class="deal-tab-docs__empty">
        <i class="pi pi-file-edit deal-tab-docs__empty-icon" />
        <p class="deal-tab-docs__empty-title">{{ t('sales.deal.documents.empty.title') }}</p>
        <p class="deal-tab-docs__empty-sub">{{ t('sales.deal.documents.empty.subtitle') }}</p>
      </div>
    </template>

    <!-- ── Loading skeleton ─────────────────────────────────────────────────── -->
    <template v-else-if="loading">
      <Skeleton height="120px" class="mb-3" />
      <Skeleton height="80px" class="mb-3" />
      <Skeleton height="60px" />
    </template>

    <!-- ── Main content ─────────────────────────────────────────────────────── -->
    <template v-else>
      <!-- Document selector (compact list) -->
      <div class="deal-tab-docs__doc-list mb-3">
        <div
          v-for="doc in documents"
          :key="doc.id"
          class="deal-tab-docs__doc-item"
          :class="{ 'deal-tab-docs__doc-item--active': activeDocId === doc.id }"
          @click="selectDoc(doc)"
        >
          <DocumentStatusTag :status="doc.status" />
          <span class="deal-tab-docs__doc-num">
            {{ doc.number ?? `#draft-${doc.id}` }}
          </span>
          <span class="deal-tab-docs__doc-date">{{ formatDate(doc.created_at) }}</span>
        </div>
      </div>
    </template>

    <!-- ── Section 1: Create document (inline form, always visible) ──────── -->
    <div class="deal-tab-docs__section">
      <p class="deal-tab-docs__section-title">
        <i class="pi pi-file-plus me-1" />
        {{ t('documents.create.title') }}
      </p>

      <!-- Template selector -->
      <div class="mb-2">
        <label class="deal-tab-docs__label">{{ t('sales.deal.documents.templateLabel') }}</label>
        <Select
          v-model="generateForm.template_id"
          :options="templateOptions"
          option-label="label"
          option-value="value"
          :loading="loadingTemplates"
          :placeholder="t('sales.deal.documents.templateLabel')"
          :invalid="!!generateErrors.template_id"
          class="w-100 mt-1"
          size="small"
        />
        <small v-if="generateErrors.template_id" class="p-error">
          {{ generateErrors.template_id }}
        </small>
      </div>

      <div class="d-flex gap-2 mt-2">
        <Button
          icon="pi pi-file-pdf"
          :label="t('sales.deal.documents.generate')"
          size="small"
          :loading="generating"
          :disabled="!generateForm.template_id"
          @click="generateDoc"
        />
        <Button
          v-if="activeDoc?.docx_path"
          icon="pi pi-download"
          :label="t('sales.deal.documents.downloadDocx')"
          severity="secondary"
          outlined
          size="small"
          @click="downloadDocx"
        />
      </div>
    </div>

    <!-- ── Section 2: Contract fields (gen-fields from template metadata) ─── -->
    <!-- Hidden until template binding exposes editable fields (future sprint) -->
    <div v-if="false" class="deal-tab-docs__section">
      <p class="deal-tab-docs__section-title">
        <i class="pi pi-list me-1" />
        {{ t('sales.deal.documents.contractFields') }}
      </p>
    </div>

    <!-- ── Section 3: Approval ──────────────────────────────────────────────── -->
    <div v-if="activeDoc" class="deal-tab-docs__section">
      <p class="deal-tab-docs__section-title">
        <i class="pi pi-send me-1" />
        {{ t('sales.deal.documents.approval') }}
      </p>

      <ApprovalPanel
        :approval="approval"
        :loading="loadingApproval"
        :deciding="deciding"
        @approve="handleApprove"
        @open-decide="openDecide"
      />

      <div class="d-flex gap-2 mt-2">
        <Button
          v-if="canSubmit"
          icon="pi pi-send"
          :label="t('documents.card.actions.submit')"
          size="small"
          :loading="submitting"
          @click="handleSubmit"
        />
        <Button
          v-if="canResubmit"
          icon="pi pi-refresh"
          :label="t('documents.card.actions.resubmit')"
          size="small"
          severity="secondary"
          outlined
          :loading="submitting"
          @click="handleSubmit"
        />
      </div>
    </div>

    <!-- ── Section 4: Final documents ──────────────────────────────────────── -->
    <div v-if="activeDoc" class="deal-tab-docs__section">
      <p class="deal-tab-docs__section-title">
        <i class="pi pi-file-check me-1" />
        {{ t('sales.deal.documents.finalDocs') }}
      </p>

      <!-- Upload scan -->
      <div class="mb-3">
        <label class="deal-tab-docs__label">{{ t('sales.deal.documents.uploadScan') }}</label>
        <FileUpload
          mode="basic"
          accept=".pdf,.jpg,.jpeg,.png,.webp"
          :max-file-size="15728640"
          :auto="true"
          custom-upload
          :choose-label="t('sales.deal.documents.uploadScan')"
          choose-icon="pi pi-upload"
          class="mt-1"
          :disabled="uploading"
          @uploader="uploadScan"
        />
      </div>

      <!-- Signed date -->
      <div class="mb-3">
        <label class="deal-tab-docs__label">{{ t('sales.deal.documents.signedAt') }}</label>
        <div class="d-flex gap-2 align-items-center mt-1">
          <DatePicker
            v-model="signedAt"
            show-icon
            date-format="dd.mm.yy"
            :placeholder="t('sales.deal.documents.signedAt')"
            size="small"
          />
          <Button
            icon="pi pi-check"
            :label="t('common.save', 'Сохранить')"
            size="small"
            :loading="savingSignedAt"
            :disabled="!signedAt"
            @click="saveSignedAt"
          />
        </div>
      </div>

      <!-- Attachments list -->
      <div v-if="attachments.length > 0" class="deal-tab-docs__attachments">
        <div
          v-for="att in attachments"
          :key="att.id"
          class="deal-tab-docs__att-item"
        >
          <i class="pi pi-file deal-tab-docs__att-icon" />
          <span class="deal-tab-docs__att-name">{{ att.original_name }}</span>
          <span class="deal-tab-docs__att-size">{{ formatSize(att.size) }}</span>
          <Button
            icon="pi pi-download"
            text
            severity="secondary"
            size="small"
            :title="t('common.download', 'Скачать')"
            @click="downloadAttachment(att)"
          />
          <Button
            icon="pi pi-times"
            text
            severity="danger"
            size="small"
            :title="t('common.delete', 'Удалить')"
            :loading="deletingAttId === att.id"
            @click="deleteAtt(att.id)"
          />
        </div>
      </div>
    </div>

    <!-- ── DecideDialog ─────────────────────────────────────────────────────── -->
    <DecideDialog
      v-model="decideDialogOpen"
      :loading="deciding"
      :required="decideAction !== 'approved'"
      @confirm="(comment) => submitDecision(comment)"
    />

    <Toast position="top-right" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import Skeleton from 'primevue/skeleton'
import FileUpload, { type FileUploadUploaderEvent } from 'primevue/fileupload'
import DatePicker from 'primevue/datepicker'
import Toast from 'primevue/toast'
import { useToast } from 'primevue/usetoast'
import ApprovalPanel from '@/pages/DocumentPage/components/ApprovalPanel.vue'
import DecideDialog from '@/components/shared/DecideDialog.vue'
import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { documentsApi } from '@/api/documents'
import { templatesApi } from '@/api/templates'
import type {
  DocumentListItemDto,
  DocumentAttachmentDto,
  ApprovalSummaryDto,
} from '@/entities/document'

const props = defineProps<{
  dealId: number
  /** Activities count — passed from parent for stats tab */
  activitiesCount?: number
}>()

const emit = defineEmits<{
  /** Notifies parent of current document count (for DealTabStats) */
  docsCountChanged: [count: number]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Documents list ─────────────────────────────────────────────────────────────

const docsResource = useAsyncResource<DocumentListItemDto[]>(() => [])
const documents = ref<DocumentListItemDto[]>([])
const loading = ref(false)
const activeDocId = ref<number | null>(null)

const activeDoc = computed<DocumentListItemDto | null>(
  () => documents.value.find((d) => d.id === activeDocId.value) ?? null,
)

async function loadDocuments() {
  loading.value = true
  await docsResource.run(() =>
    documentsApi.getDocuments({ deal_id: props.dealId, per_page: 50 }).then((r) => r.data),
  )
  documents.value = docsResource.data.value
  loading.value = false
  emit('docsCountChanged', documents.value.length)
  // Auto-select first doc
  const firstDoc = documents.value[0]
  if (firstDoc !== undefined && activeDocId.value === null) {
    activeDocId.value = firstDoc.id
  }
}

function selectDoc(doc: DocumentListItemDto) {
  activeDocId.value = doc.id
}

// ── Templates ──────────────────────────────────────────────────────────────────

const templateOptions = ref<{ label: string; value: number }[]>([])
const loadingTemplates = ref(false)
const generateForm = ref<{ template_id: number | null }>({ template_id: null })
const generateErrors = ref<Record<string, string>>({})

async function loadTemplates() {
  loadingTemplates.value = true
  try {
    const templates = await templatesApi.getTemplates({ kind: 'contract' })
    templateOptions.value = templates.map((tpl) => ({
      label: `${tpl.title}${tpl.current_version ? ` (v${tpl.current_version.version_number})` : ''}`,
      value: tpl.id,
    }))
  } catch {
    // non-critical
  } finally {
    loadingTemplates.value = false
  }
}

// ── Generate ───────────────────────────────────────────────────────────────────

const generateMutation = useMutation<DocumentListItemDto>()
const generating = computed(() => generateMutation.isPending.value)

async function generateDoc() {
  generateErrors.value = {}
  if (!generateForm.value.template_id) {
    generateErrors.value.template_id = t('errors.required', 'Обязательное поле')
    return
  }
  try {
    const doc = await generateMutation.run(() =>
      documentsApi.generateFromDeal(props.dealId, {
        kind: 'contract',
        template_id: generateForm.value.template_id!,
      }),
    )
    // Prepend to list and select
    documents.value = [doc, ...documents.value.filter((d) => d.id !== doc.id)]
    activeDocId.value = doc.id
    emit('docsCountChanged', documents.value.length)
    toast.add({
      severity: 'success',
      summary: t('documents.create.title'),
      life: 3000,
    })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

function downloadDocx() {
  if (!activeDocId.value) return
  window.open(documentsApi.getDownloadDocxUrl(activeDocId.value), '_blank')
}

// ── Approval ───────────────────────────────────────────────────────────────────

const approvalResource = useAsyncResource<ApprovalSummaryDto | null>(() => null)
const approval = ref<ApprovalSummaryDto | null>(null)
const loadingApproval = ref(false)

async function loadApproval() {
  if (!activeDocId.value) return
  loadingApproval.value = true
  try {
    await approvalResource.run(() =>
      documentsApi.getApprovalSummary(activeDocId.value!),
    )
    approval.value = approvalResource.data.value
  } catch {
    approval.value = null
  } finally {
    loadingApproval.value = false
  }
}

const canSubmit = computed(() =>
  activeDoc.value?.status === 'draft' && !!activeDoc.value?.docx_path,
)

const canResubmit = computed(() =>
  (activeDoc.value?.status === 'rejected' || activeDoc.value?.status === 'needs_rework') &&
  !!activeDoc.value?.docx_path,
)

const submitMutation = useMutation<DocumentListItemDto>()
const submitting = computed(() => submitMutation.isPending.value)

async function handleSubmit() {
  if (!activeDocId.value) return
  try {
    const updated = await submitMutation.run(() =>
      documentsApi.submitDocument(activeDocId.value!),
    )
    // Update local doc status
    const idx = documents.value.findIndex((d) => d.id === updated.id)
    if (idx >= 0) documents.value[idx] = updated
    await loadApproval()
    toast.add({ severity: 'success', summary: t('documents.card.actions.submit'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

// ── Decide ─────────────────────────────────────────────────────────────────────

const decideDialogOpen = ref(false)
const decideAction = ref<'approved' | 'rejected' | 'needs_rework'>('approved')
const decideMutation = useMutation<DocumentListItemDto>()
const deciding = computed(() => decideMutation.isPending.value)

function openDecide(action: 'rejected' | 'needs_rework') {
  decideAction.value = action
  decideDialogOpen.value = true
}

async function handleApprove() {
  if (!activeDocId.value) return
  try {
    await decideMutation.run(() =>
      documentsApi.decideDocument(activeDocId.value!, { decision: 'approved' }),
    )
    await loadApproval()
    toast.add({ severity: 'success', summary: t('documents.approval.decide.approve'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

async function submitDecision(comment: string) {
  if (!activeDocId.value) return
  try {
    await decideMutation.run(() =>
      documentsApi.decideDocument(activeDocId.value!, {
        decision: decideAction.value,
        comment: comment || null,
      }),
    )
    decideDialogOpen.value = false
    await loadApproval()
    toast.add({ severity: 'success', summary: t('documents.approval.title'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

// ── Attachments ────────────────────────────────────────────────────────────────

const attachments = ref<DocumentAttachmentDto[]>([])
const uploading = ref(false)
const deletingAttId = ref<number | null>(null)

async function loadAttachments() {
  if (!activeDocId.value) return
  try {
    attachments.value = await documentsApi.getDocumentAttachments(activeDocId.value)
  } catch {
    attachments.value = []
  }
}

async function uploadScan(event: FileUploadUploaderEvent) {
  const files = Array.isArray(event.files) ? event.files : [event.files]
  const file = files[0]
  if (!activeDocId.value || !file) return
  uploading.value = true
  try {
    const att = await documentsApi.uploadAttachment(
      activeDocId.value,
      file,
      'signed_scan',
    )
    attachments.value.push(att)
    toast.add({ severity: 'success', summary: t('sales.deal.documents.uploadScan'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    uploading.value = false
  }
}

function downloadAttachment(att: DocumentAttachmentDto) {
  if (!activeDocId.value) return
  window.open(documentsApi.getAttachmentDownloadUrl(activeDocId.value, att.id), '_blank')
}

async function deleteAtt(attId: number) {
  if (!activeDocId.value) return
  deletingAttId.value = attId
  try {
    await documentsApi.deleteAttachment(activeDocId.value, attId)
    attachments.value = attachments.value.filter((a) => a.id !== attId)
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    deletingAttId.value = null
  }
}

// ── Signed date ────────────────────────────────────────────────────────────────

const signedAt = ref<Date | null>(null)
const savingSignedAt = ref(false)

async function saveSignedAt() {
  if (!activeDocId.value || !signedAt.value) return
  savingSignedAt.value = true
  try {
    await documentsApi.patchDocument(activeDocId.value, {
      signed_at: signedAt.value.toISOString(),
    })
    toast.add({ severity: 'success', summary: t('sales.deal.documents.signedAt'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    savingSignedAt.value = false
  }
}

// ── Helpers ────────────────────────────────────────────────────────────────────

function formatDate(dateStr: string): string {
  return new Date(dateStr).toLocaleDateString('ru-RU', { day: '2-digit', month: 'short' })
}

function formatSize(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${(bytes / 1024).toFixed(1)} KB`
  return `${(bytes / (1024 * 1024)).toFixed(1)} MB`
}

// ── Watchers ───────────────────────────────────────────────────────────────────

watch(activeDocId, async (id) => {
  if (id !== null) {
    await Promise.all([loadApproval(), loadAttachments()])
  }
})

// ── Bootstrap ──────────────────────────────────────────────────────────────────

onMounted(async () => {
  await Promise.all([loadDocuments(), loadTemplates()])
})
</script>

<style lang="scss" scoped>
.deal-tab-docs {
  padding: $space-3;

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-2;
    padding: $space-8 $space-4;
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-xl;
    color: var(--p-text-muted-color);
    opacity: 0.35;
  }

  &__empty-title {
    font-weight: $font-weight-semibold;
    margin: 0;
    color: $surface-700;
  }

  &__empty-sub {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
  }

  &__doc-list {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__doc-item {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-1 $space-2;
    border-radius: $radius-sm;
    cursor: pointer;
    font-size: $font-size-sm;
    transition: background 0.15s;

    &:hover {
      background: var(--p-surface-100);

      .app-dark & {
        background: var(--p-surface-800);
      }
    }

    &--active {
      background: var(--p-primary-50);
      border: 1px solid var(--p-primary-200);

      .app-dark & {
        background: var(--p-primary-950);
        border-color: var(--p-primary-800);
      }
    }
  }

  &__doc-num {
    font-weight: $font-weight-medium;
    flex: 1;
  }

  &__doc-date {
    color: $surface-500;
    font-size: $font-size-xs;
  }

  &__section {
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    padding: $space-3;
    margin-bottom: $space-3;

    .app-dark & {
      border-color: var(--p-surface-700);
    }
  }

  &__section-title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    margin: 0 0 $space-2;

    .app-dark & {
      color: $surface-200;
    }
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__attachments {
    display: flex;
    flex-direction: column;
    gap: $space-1;
    margin-top: $space-2;
  }

  &__att-item {
    display: flex;
    align-items: center;
    gap: $space-2;
    padding: $space-1 $space-2;
    border-radius: $radius-sm;
    background: var(--p-surface-50);
    font-size: $font-size-sm;

    .app-dark & {
      background: var(--p-surface-800);
    }
  }

  &__att-icon {
    color: var(--p-primary-400);
    flex-shrink: 0;
  }

  &__att-name {
    flex: 1;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__att-size {
    color: $surface-500;
    font-size: $font-size-xs;
    white-space: nowrap;
  }
}
</style>
