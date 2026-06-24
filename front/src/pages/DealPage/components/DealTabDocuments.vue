<template>
  <div class="deal-tab-docs">
    <!-- ── Loading skeleton ─────────────────────────────────────────────────── -->
    <template v-if="loading">
      <Skeleton height="120px" class="mb-3" />
      <Skeleton height="80px" class="mb-3" />
      <Skeleton height="60px" />
    </template>

    <template v-else>
      <!-- ── Document list ─────────────────────────────────────────────────── -->
      <div v-if="documents.length > 0" class="deal-tab-docs__doc-list">
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

      <!-- ── Empty state ─────────────────────────────────────────────────────── -->
      <div v-else class="deal-tab-docs__empty">
        <i class="pi pi-file-edit deal-tab-docs__empty-icon" />
        <p class="deal-tab-docs__empty-title">{{ t('sales.deal.documents.empty.title') }}</p>
        <p class="deal-tab-docs__empty-sub">{{ t('sales.deal.documents.empty.subtitle') }}</p>
      </div>

      <!-- ── Section 1: Create document ───────────────────────────────────── -->
      <div class="deal-tab-docs__section">
        <!-- Template SearchPicker -->
        <div class="deal-tab-docs__field-row">
          <label class="deal-tab-docs__label">{{ t('sales.deal.documents.templateLabel') }}</label>
          <SearchPicker
            v-model="generateForm.template_id"
            :options="templateOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('sales.deal.documents.templatePlaceholder')"
            class="w-100"
          />
          <small v-if="generateErrors.template_id" class="p-error">
            {{ generateErrors.template_id }}
          </small>
        </div>

        <div class="d-flex gap-2 mt-3">
          <Button
            icon="pi pi-file-pdf"
            :label="t('sales.deal.documents.generate')"
            size="small"
            :loading="generating"
            :disabled="!generateForm.template_id"
            @click="generateDoc"
          />
          <Button
            icon="pi pi-download"
            :label="t('sales.deal.documents.downloadDocx')"
            severity="secondary"
            outlined
            size="small"
            :disabled="!activeDoc?.docx_path"
            @click="downloadDocx"
          />
        </div>
      </div>

      <!-- ── Section 2: Approval ───────────────────────────────────────────── -->
      <div v-if="activeDoc" class="deal-tab-docs__section">
        <p class="deal-tab-docs__section-label">
          <i class="pi pi-send me-1" />
          {{ t('sales.deal.documents.approval') }}
        </p>

        <template v-if="loadingApproval">
          <Skeleton height="80px" />
        </template>

        <template v-else-if="approval">
          <!-- Approvers list -->
          <div class="deal-tab-docs__approvers">
            <div
              v-for="vote in flatVotes"
              :key="vote.user_id"
              class="deal-tab-docs__approver-row"
            >
              <div class="deal-tab-docs__approver-avatar">
                {{ initials(vote.user_name) }}
              </div>
              <span class="deal-tab-docs__approver-name">{{ vote.user_name }}</span>
              <span
                class="deal-tab-docs__approver-badge"
                :class="`deal-tab-docs__approver-badge--${vote.decision}`"
              >{{ voteLabel(vote.decision) }}</span>
            </div>
          </div>

          <!-- Rejected reason plate -->
          <div
            v-if="approval.decision === 'rejected' && approval.comment"
            class="deal-tab-docs__reject-plate"
          >
            <i class="pi pi-times-circle me-1" />
            {{ approval.comment }}
          </div>

          <!-- Reject inline form -->
          <div v-if="showRejectForm" class="deal-tab-docs__reject-form">
            <label class="deal-tab-docs__label">{{ t('sales.deal.documents.rejectReason') }}</label>
            <textarea
              v-model="rejectComment"
              class="deal-tab-docs__reject-textarea"
              :placeholder="t('sales.deal.documents.rejectReasonPlaceholder')"
              rows="3"
            />
            <div class="d-flex gap-2 mt-2">
              <Button
                :label="t('common.cancel')"
                severity="secondary"
                outlined
                size="small"
                @click="showRejectForm = false; rejectComment = ''"
              />
              <Button
                :label="t('sales.deal.documents.rejectBtn')"
                severity="danger"
                size="small"
                :loading="deciding"
                :disabled="!rejectComment.trim()"
                @click="handleReject"
              />
            </div>
          </div>

          <!-- Decision buttons -->
          <div
            v-else-if="approval.is_current_user_approver && !showRejectForm"
            class="d-flex gap-2 mt-3 flex-wrap"
          >
            <Button
              icon="pi pi-check"
              :label="t('sales.deal.documents.approveBtn')"
              severity="success"
              size="small"
              :loading="deciding"
              @click="handleApprove"
            />
            <Button
              icon="pi pi-times"
              :label="t('documents.approval.decide.reject')"
              severity="danger"
              outlined
              size="small"
              @click="showRejectForm = true"
            />
          </div>

          <!-- Resubmit -->
          <div v-if="canResubmit" class="mt-3">
            <Button
              icon="pi pi-refresh"
              :label="t('sales.deal.documents.resubmit')"
              severity="secondary"
              outlined
              size="small"
              :loading="submitting"
              @click="handleSubmit"
            />
          </div>
        </template>

        <!-- No approval yet — submit flow -->
        <div v-else class="deal-tab-docs__no-approval">
          <p class="deal-tab-docs__no-approval-hint">{{ t('documents.approval.noApproval') }}</p>
        </div>

        <!-- Submit -->
        <div v-if="canSubmit" class="mt-3">
          <Button
            icon="pi pi-send"
            :label="t('documents.card.actions.submit')"
            size="small"
            :loading="submitting"
            @click="handleSubmit"
          />
        </div>
      </div>

      <!-- ── Section 3: Final documents ────────────────────────────────────── -->
      <div v-if="activeDoc" class="deal-tab-docs__section">
        <p class="deal-tab-docs__section-label">
          <i class="pi pi-file-check me-1" />
          {{ t('sales.deal.documents.finalDocs') }}
        </p>

        <!-- Upload signed scan -->
        <div class="deal-tab-docs__field-row">
          <FileUpload
            mode="basic"
            accept=".pdf,.jpg,.jpeg,.png,.webp"
            :max-file-size="15728640"
            :auto="true"
            custom-upload
            :choose-label="t('sales.deal.documents.uploadScan')"
            choose-icon="pi pi-upload"
            severity="secondary"
            outlined
            size="small"
            :disabled="uploading"
            @uploader="uploadScan"
          />
        </div>

        <!-- Signed at DateField + Save -->
        <div class="deal-tab-docs__field-row mt-2">
          <label class="deal-tab-docs__label">{{ t('sales.deal.documents.contractFactDate') }}</label>
          <div class="d-flex gap-2 align-items-center mt-1">
            <DateField
              v-model="signedAtIso"
              placeholder="ДД.ММ.ГГГГ"
            />
            <Button
              :label="t('common.save')"
              size="small"
              :loading="savingSignedAt"
              :disabled="!signedAtIso"
              @click="saveSignedAt"
            />
          </div>
        </div>

        <!-- Attachments -->
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
              @click="downloadAttachment(att)"
            />
            <Button
              icon="pi pi-times"
              text
              severity="danger"
              size="small"
              :loading="deletingAttId === att.id"
              @click="deleteAtt(att.id)"
            />
          </div>
        </div>
      </div>
    </template>

    <Toast position="top-right" />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import FileUpload, { type FileUploadUploaderEvent } from 'primevue/fileupload'
import Toast from 'primevue/toast'
import { useToast } from 'primevue/usetoast'
import SearchPicker from '@/components/crm/SearchPicker.vue'
import DateField from '@/components/crm/DateField.vue'
import DocumentStatusTag from '@/components/shared/DocumentStatusTag.vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { documentsApi } from '@/api/documents'
import { templatesApi } from '@/api/templates'
import type {
  DocumentListItemDto,
  DocumentDto,
  DocumentAttachmentDto,
  ApprovalSummaryDto,
  ApprovalDecision,
} from '@/entities/document'
import type { GenerateFromContextResponse } from '@/api/documents'

const props = defineProps<{
  dealId: number
  activitiesCount?: number
}>()

const emit = defineEmits<{
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
    const templates = await templatesApi.getTemplates({ kind: 'docx' })
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

const generateMutation = useMutation<GenerateFromContextResponse>()
const generating = computed(() => generateMutation.isPending.value)

async function generateDoc() {
  generateErrors.value = {}
  if (!generateForm.value.template_id) {
    generateErrors.value.template_id = t('errors.required', 'Обязательное поле')
    return
  }
  try {
    const result = await generateMutation.run(() =>
      documentsApi.generateFromDeal(props.dealId, {
        kind: 'contract',
        template_id: generateForm.value.template_id!,
      }),
    )
    // Reload the documents list to get the full DocumentListItemDto (generation
    // returns a GenerateResultResource with document_id, not a full DocumentDto).
    const resp = await documentsApi.getDocuments({ deal_id: props.dealId, per_page: 50 })
    documents.value = resp.data
    activeDocId.value = result.document_id
    emit('docsCountChanged', documents.value.length)
    toast.add({ severity: 'success', summary: t('documents.create.title'), life: 3000 })
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
const showRejectForm = ref(false)
const rejectComment = ref('')

// Flatten all stage votes for rendering
const flatVotes = computed(() => {
  if (!approval.value) return []
  return approval.value.stages.flatMap((s) => s.approvals)
})

async function loadApproval() {
  if (!activeDocId.value) return
  loadingApproval.value = true
  try {
    await approvalResource.run(() => documentsApi.getApprovalSummary(activeDocId.value!))
    approval.value = approvalResource.data.value
  } catch {
    approval.value = null
  } finally {
    loadingApproval.value = false
  }
}

const canSubmit = computed(
  () => activeDoc.value?.status === 'draft' && !!activeDoc.value?.docx_path,
)

const canResubmit = computed(
  () =>
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
    const idx = documents.value.findIndex((d) => d.id === updated.id)
    if (idx >= 0) documents.value[idx] = updated
    await loadApproval()
    toast.add({ severity: 'success', summary: t('documents.card.actions.submit'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

const decideMutation = useMutation<DocumentListItemDto>()
const deciding = computed(() => decideMutation.isPending.value)

async function handleApprove() {
  if (!activeDocId.value) return
  try {
    await decideMutation.run(() =>
      documentsApi.decideDocument(activeDocId.value!, { decision: 'approved' }),
    )
    await loadApproval()
    toast.add({
      severity: 'success',
      summary: t('documents.approval.decide.approve'),
      life: 2000,
    })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

async function handleReject() {
  if (!activeDocId.value || !rejectComment.value.trim()) return
  try {
    await decideMutation.run(() =>
      documentsApi.decideDocument(activeDocId.value!, {
        decision: 'rejected',
        comment: rejectComment.value,
      }),
    )
    showRejectForm.value = false
    rejectComment.value = ''
    await loadApproval()
    toast.add({ severity: 'info', summary: t('documents.approval.title'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

function voteLabel(decision: ApprovalDecision): string {
  const map: Record<ApprovalDecision, string> = {
    approved: t('documents.approval.approved'),
    rejected: t('documents.approval.rejected'),
    needs_rework: t('documents.approval.needs_rework'),
    pending: t('documents.approval.pending'),
  }
  return map[decision] ?? decision
}

function initials(name: string): string {
  return name
    .split(' ')
    .slice(0, 2)
    .map((w) => w[0] ?? '')
    .join('')
    .toUpperCase()
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
    const att = await documentsApi.uploadAttachment(activeDocId.value, file, 'signed_scan')
    attachments.value.push(att)
    toast.add({
      severity: 'success',
      summary: t('sales.deal.documents.uploadScan'),
      life: 2000,
    })
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

// ── Signed date (DateField — ISO string) ──────────────────────────────────────

const signedAtIso = ref<string | null>(null)
const savingSignedAt = ref(false)

async function saveSignedAt() {
  if (!activeDocId.value || !signedAtIso.value) return
  savingSignedAt.value = true
  try {
    await documentsApi.patchDocument(activeDocId.value, {
      signed_at: signedAtIso.value,
    })
    toast.add({
      severity: 'success',
      summary: t('sales.deal.documents.contractFactDate'),
      life: 2000,
    })
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
    showRejectForm.value = false
    rejectComment.value = ''
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
  display: flex;
  flex-direction: column;
  gap: $space-3;

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
    transition: background var(--app-transition-fast);

    &:hover {
      background: var(--p-surface-100);

      .app-dark & {
        background: var(--p-surface-100);
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
    color: var(--p-text-muted-color);
    font-size: $font-size-xs;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-2;
    padding: $space-6 $space-4;
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
    color: var(--p-text-color);
  }

  &__empty-sub {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
    margin: 0;
  }

  &__section {
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    padding: $space-3;

    .app-dark & {
      border-color: var(--p-surface-200);
    }
  }

  &__section-label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    margin: 0 0 $space-2;
  }

  &__field-row {
    display: flex;
    flex-direction: column;
  }

  &__label {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    margin-bottom: $space-1;
  }

  // Approvers list
  &__approvers {
    display: flex;
    flex-direction: column;
    gap: $space-2;
    margin-bottom: $space-3;
  }

  &__approver-row {
    display: flex;
    align-items: center;
    gap: $space-2;
    font-size: $font-size-sm;
  }

  &__approver-avatar {
    width: 24px;
    height: 24px;
    border-radius: $radius-circle;
    background: $primary-900;
    color: $surface-0;
    font-size: $font-size-2xs;
    font-weight: $font-weight-semibold;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }

  &__approver-name {
    flex: 1;
    font-weight: $font-weight-medium;
  }

  &__approver-badge {
    font-size: $font-size-xs;
    padding: 2px $space-1;
    border-radius: $radius-sm;

    &--approved {
      background: var(--p-green-100);
      color: var(--p-green-700);

      .app-dark & {
        background: var(--p-green-900);
        color: var(--p-green-300);
      }
    }

    &--rejected {
      background: var(--p-red-100);
      color: var(--p-red-700);

      .app-dark & {
        background: var(--p-red-900);
        color: var(--p-red-300);
      }
    }

    &--needs_rework {
      background: var(--p-orange-100);
      color: var(--p-orange-700);

      .app-dark & {
        background: var(--p-orange-900);
        color: var(--p-orange-300);
      }
    }

    &--pending {
      background: var(--p-surface-100);
      color: var(--p-text-muted-color);

      .app-dark & {
        background: var(--p-surface-100);
      }
    }
  }

  // Rejected reason plate
  &__reject-plate {
    background: var(--p-red-50);
    border: 1px solid var(--p-red-200);
    border-radius: $radius-sm;
    padding: $space-2 $space-3;
    font-size: $font-size-sm;
    color: var(--p-red-700);
    margin-bottom: $space-2;

    .app-dark & {
      background: transparent;
      border-color: var(--p-red-500);
      color: var(--p-red-400);
    }
  }

  // Inline reject form
  &__reject-form {
    display: flex;
    flex-direction: column;
    gap: $space-2;
    margin-top: $space-2;
  }

  &__reject-textarea {
    width: 100%;
    min-height: 72px;
    padding: $space-2 $space-2;
    border: 1px solid var(--p-surface-300);
    border-radius: $radius-sm;
    background: var(--p-card-background);
    font-size: $font-size-sm;
    color: var(--p-text-color);
    resize: vertical;
    outline: none;
    font-family: inherit;

    &:focus {
      border-color: var(--p-primary-color);
    }

    .app-dark & {
      border-color: var(--p-surface-600);
    }
  }

  &__no-approval {
    padding: $space-2 0;
  }

  &__no-approval-hint {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
    margin: 0;
  }

  // Attachments
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
      background: var(--p-surface-50);
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
    color: var(--p-text-muted-color);
    font-size: $font-size-xs;
    white-space: nowrap;
  }
}
</style>
