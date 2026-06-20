<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 520px"
    :modal="true"
    :pt="{ header: { class: 'termination-drawer__header' } }"
  >
    <template #header>
      <span class="termination-drawer__title">
        {{ t('crm.termination.drawerTitle') }} — {{ companyName }}
      </span>
      <Button
        icon="pi pi-times"
        text
        severity="secondary"
        size="small"
        class="ms-auto"
        @click="visible = false"
      />
    </template>

    <div class="termination-drawer__body">
      <!-- ── Step 1: Generation ───────────────────────────────────────────────── -->
      <div class="termination-drawer__step">
        <div class="termination-drawer__step-header">
          <span class="termination-drawer__step-number">1</span>
          <span class="termination-drawer__step-label">{{ t('crm.termination.step1') }}</span>
          <Tag
            v-if="generateDone"
            severity="success"
            size="small"
            :value="t('common.done', 'Готово')"
            class="ms-auto"
          />
        </div>

        <!-- Not yet generated -->
        <div v-if="!generateDone && !generating && !generateError" class="termination-drawer__generate-block">
          <p class="termination-drawer__step-hint">{{ t('crm.termination.generateHint') }}</p>
          <Button
            icon="pi pi-file-pdf"
            :label="t('crm.termination.generateBtn')"
            @click="onGenerate"
          />
        </div>

        <!-- Generating spinner -->
        <div v-else-if="generating" class="termination-drawer__spinner-block">
          <ProgressSpinner style="width: 36px; height: 36px" stroke-width="4" />
          <span class="termination-drawer__spinner-label">{{ t('crm.termination.generating') }}</span>
        </div>

        <!-- Generation error -->
        <div v-else-if="generateError" class="termination-drawer__error-block">
          <Message severity="error" :closable="false">{{ generateError }}</Message>
          <Button
            icon="pi pi-refresh"
            :label="t('common.retry')"
            severity="secondary"
            size="small"
            class="mt-2"
            @click="onGenerate"
          />
        </div>

        <!-- Generated: PDF / DOCX links -->
        <div v-else-if="generateDone" class="termination-drawer__pdf-block">
          <Button
            v-if="pdfUrl"
            icon="pi pi-external-link"
            :label="t('crm.termination.openPdf')"
            severity="secondary"
            outlined
            size="small"
            @click="openPdf"
          />
          <Button
            v-if="docxUrl"
            icon="pi pi-download"
            :label="t('crm.termination.downloadDocx')"
            severity="secondary"
            text
            size="small"
            @click="openDocx"
          />
          <div v-if="generateWarnings.length" class="termination-drawer__warnings">
            <Message severity="warn" :closable="false">
              {{ generateWarnings.join('; ') }}
            </Message>
          </div>
        </div>
      </div>

      <Divider />

      <!-- ── Step 2: Upload signed scan ──────────────────────────────────────── -->
      <div
        class="termination-drawer__step"
        :class="{ 'termination-drawer__step--disabled': !generateDone }"
      >
        <div class="termination-drawer__step-header">
          <span class="termination-drawer__step-number">2</span>
          <span class="termination-drawer__step-label">{{ t('crm.termination.step2') }}</span>
          <Tag
            v-if="scanUploaded"
            severity="success"
            size="small"
            :value="t('crm.termination.scanUploaded')"
            class="ms-auto"
          />
        </div>

        <p class="termination-drawer__step-hint">{{ t('crm.termination.scanHint') }}</p>

        <!-- Uploaded confirmation -->
        <div v-if="scanUploaded" class="termination-drawer__scan-done">
          <i class="pi pi-check-circle termination-drawer__scan-check" />
          <span class="termination-drawer__scan-name">{{ uploadedFileName }}</span>
        </div>

        <!-- Upload area -->
        <div v-else-if="generateDone" class="termination-drawer__upload-block">
          <FileUpload
            mode="basic"
            accept=".pdf,.jpg,.jpeg,.png"
            :max-file-size="20971520"
            :auto="true"
            custom-upload
            :choose-label="t('crm.termination.chooseFile')"
            choose-icon="pi pi-upload"
            :disabled="uploading"
            @uploader="onUploadScan"
          />
          <small class="termination-drawer__upload-hint">{{ t('crm.termination.uploadFormats') }}</small>
          <div v-if="uploadError" class="mt-2">
            <Message severity="error" :closable="false">{{ uploadError }}</Message>
          </div>
          <ProgressSpinner v-if="uploading" style="width: 24px; height: 24px" stroke-width="4" />
        </div>

        <div v-else class="termination-drawer__step-locked">
          <i class="pi pi-lock termination-drawer__lock-icon" />
          <span>{{ t('crm.termination.stepLockedHint') }}</span>
        </div>
      </div>

      <Divider />

      <!-- ── Status footer ───────────────────────────────────────────────────── -->
      <div class="termination-drawer__status-row">
        <span class="termination-drawer__status-label">{{ t('crm.termination.clientStatusLabel') }}</span>
        <Tag
          :severity="scanUploaded ? 'danger' : 'warning'"
          size="small"
          :value="scanUploaded ? t('crm.termination.statusDisconnected') : t('crm.termination.waiting')"
        />
      </div>
    </div>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Divider from 'primevue/divider'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import FileUpload, { type FileUploadUploaderEvent } from 'primevue/fileupload'
import { generateDocument, uploadAttachment } from '@/api/documents'

const props = defineProps<{
  companyName: string
  /** document_id from POST /companies/{id}/disconnect response */
  documentId: number
}>()

const emit = defineEmits<{
  (e: 'company-updated'): void
}>()

const visible = defineModel<boolean>({ default: false })

const { t } = useI18n()

const generating = ref(false)
const generateDone = ref(false)
const generateError = ref('')
const pdfUrl = ref<string | null>(null)
const docxUrl = ref<string | null>(null)
const generateWarnings = ref<string[]>([])

const uploading = ref(false)
const uploadError = ref('')
const scanUploaded = ref(false)
const uploadedFileName = ref('')

// Reset state each time drawer opens
watch(visible, (val) => {
  if (val) {
    generating.value = false
    generateDone.value = false
    generateError.value = ''
    pdfUrl.value = null
    docxUrl.value = null
    generateWarnings.value = []
    uploading.value = false
    uploadError.value = ''
    scanUploaded.value = false
    uploadedFileName.value = ''
  }
})

async function onGenerate() {
  generating.value = true
  generateError.value = ''
  try {
    const result = await generateDocument(props.documentId)
    pdfUrl.value = result.data.pdf_url ?? null
    docxUrl.value = result.data.docx_url ?? null
    generateWarnings.value = result.warnings ?? []
    generateDone.value = true
  } catch {
    generateError.value = t('crm.termination.generateError')
  } finally {
    generating.value = false
  }
}

function openPdf() {
  if (pdfUrl.value) globalThis.window.open(pdfUrl.value, '_blank')
}

function openDocx() {
  if (docxUrl.value) globalThis.window.open(docxUrl.value, '_blank')
}

async function onUploadScan(event: FileUploadUploaderEvent) {
  const files = Array.isArray(event.files) ? event.files : [event.files]
  const file = files[0]
  if (!file) return

  uploading.value = true
  uploadError.value = ''
  try {
    await uploadAttachment(props.documentId, file, 'signed_scan')
    uploadedFileName.value = file.name
    scanUploaded.value = true
    // Backend fires TerminationAgreementSigned event → company status → 'disconnected'
    emit('company-updated')
  } catch {
    uploadError.value = t('crm.termination.uploadError')
  } finally {
    uploading.value = false
  }
}
</script>

<style lang="scss" scoped>
.termination-drawer__header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.termination-drawer__title {
  font-weight: $font-weight-semibold;
  font-size: $font-size-base;
  color: $surface-900;
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.termination-drawer__body {
  display: flex;
  flex-direction: column;
}

// ── Step layout ────────────────────────────────────────────────────────────────

.termination-drawer__step {
  padding: $space-4 0;

  &--disabled {
    opacity: 0.5;
    pointer-events: none;
  }
}

.termination-drawer__step-header {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-bottom: $space-3;
}

.termination-drawer__step-number {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: 50%;
  background: var(--p-primary-color);
  color: #fff;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  flex-shrink: 0;
}

.termination-drawer__step-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.termination-drawer__step-hint {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0 0 $space-3;
  line-height: 1.5;
}

// ── Generate block ────────────────────────────────────────────────────────────

.termination-drawer__generate-block {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.termination-drawer__spinner-block {
  display: flex;
  align-items: center;
  gap: $space-3;
}

.termination-drawer__spinner-label {
  font-size: $font-size-sm;
  color: $surface-600;
}

.termination-drawer__error-block {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.termination-drawer__pdf-block {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-2;
}

.termination-drawer__warnings {
  width: 100%;
  margin-top: $space-2;
}

// ── Upload block ──────────────────────────────────────────────────────────────

.termination-drawer__upload-block {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.termination-drawer__upload-hint {
  font-size: $font-size-xs;
  color: $surface-400;
}

.termination-drawer__scan-done {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.termination-drawer__scan-check {
  color: var(--p-green-500);
  font-size: 1.25rem;
}

.termination-drawer__scan-name {
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.termination-drawer__step-locked {
  display: flex;
  align-items: center;
  gap: $space-2;
  color: $surface-400;
  font-size: $font-size-sm;
}

.termination-drawer__lock-icon {
  font-size: $font-size-sm;
}

// ── Status row ────────────────────────────────────────────────────────────────

.termination-drawer__status-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 0 0;
}

.termination-drawer__status-label {
  font-size: $font-size-sm;
  color: $surface-600;
  font-weight: $font-weight-medium;
}
</style>
