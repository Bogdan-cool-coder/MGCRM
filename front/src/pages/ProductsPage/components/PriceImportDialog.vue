<template>
  <Dialog
    v-model:visible="visible"
    modal
    style="width: 680px"
    :closable="!importing"
    :header="t('catalog.import.dialog.title')"
    @hide="onHide"
  >
    <div class="import-dialog">
      <!-- Step 1: File Upload -->
      <div class="import-dialog__step">
        <p class="import-dialog__step-label">{{ t('catalog.import.dialog.step1') }}</p>
        <div class="import-dialog__dropzone">
          <i class="pi pi-cloud-upload import-dialog__upload-icon" />
          <p class="import-dialog__upload-hint">{{ t('catalog.import.dialog.uploadHint') }}</p>
          <p class="import-dialog__upload-max">{{ t('catalog.import.dialog.uploadMax') }}</p>
          <FileUpload
            mode="basic"
            accept=".xlsx,.xls"
            :max-file-size="10485760"
            :auto="false"
            custom-upload
            :choose-label="$t('catalog.import.dialog.step1')"
            class="import-dialog__file-btn"
            @select="onFileSelected"
          />
        </div>
        <Button
          icon="pi pi-download"
          :label="t('catalog.import.dialog.downloadTemplate')"
          severity="secondary"
          text
          size="small"
          class="mt-2"
          @click="downloadTemplate"
        />
      </div>

      <!-- Previewing spinner -->
      <div v-if="state === 'previewing'" class="import-dialog__spinner">
        <ProgressSpinner style="width: 40px; height: 40px" />
        <p>{{ t('catalog.import.dialog.previewing') }}</p>
      </div>

      <!-- Preview result -->
      <div v-if="preview && (state === 'preview_ok' || state === 'preview_err')" class="import-dialog__preview">
        <Panel :header="t('catalog.import.dialog.preview.title')">
          <div class="import-dialog__preview-tags">
            <Tag
              :value="t('catalog.import.dialog.preview.wouldInsert', { n: preview.would_insert })"
              severity="success"
            />
            <Tag
              :value="t('catalog.import.dialog.preview.wouldUpdate', { n: preview.would_update })"
              severity="info"
            />
            <Tag
              :value="t('catalog.import.dialog.preview.skipped', { n: preview.skipped })"
              severity="secondary"
            />
            <Tag
              v-if="preview.errors.length > 0"
              :value="t('catalog.import.dialog.preview.errors', { n: preview.errors.length })"
              severity="danger"
            />
          </div>

          <!-- Error table -->
          <DataTable
            v-if="preview.errors.length > 0"
            :value="preview.errors"
            size="small"
            class="import-dialog__error-table mt-3"
            scroll-height="160px"
            scrollable
          >
            <Column
              field="row"
              :header="t('catalog.import.dialog.preview.errorsColumns.row')"
              style="width: 80px"
            />
            <Column
              field="message"
              :header="t('catalog.import.dialog.preview.errorsColumns.message')"
            />
          </DataTable>
        </Panel>

        <Message
          v-if="preview.errors.length > 0"
          severity="warn"
          class="mt-3"
        >
          {{ t('catalog.import.dialog.preview.warningWithErrors') }}
        </Message>
      </div>

      <!-- Importing spinner -->
      <div v-if="state === 'importing'" class="import-dialog__spinner">
        <ProgressSpinner style="width: 40px; height: 40px" />
        <p>{{ t('catalog.import.dialog.importing') }}</p>
      </div>
    </div>

    <template #footer>
      <div class="import-dialog__footer">
        <Button
          :label="t('catalog.import.dialog.cancel')"
          severity="secondary"
          text
          :disabled="importing"
          @click="onCancel"
        />
        <Button
          v-if="preview"
          icon="pi pi-undo"
          :label="t('catalog.import.dialog.changeFile')"
          severity="secondary"
          outlined
          :disabled="importing"
          @click="resetPreview"
        />
        <Button
          icon="pi pi-check"
          :label="importButtonLabel"
          :loading="importing"
          :disabled="!hasPreview"
          @click="onImport"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import FileUpload from 'primevue/fileupload'
import Button from 'primevue/button'
import ProgressSpinner from 'primevue/progressspinner'
import Panel from 'primevue/panel'
import Tag from 'primevue/tag'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Message from 'primevue/message'
import { catalogApi } from '@/api/catalog'
import { getApiErrorMessage } from '@/utils/errors'
import type { ImportResultDto } from '@/entities/catalog'

const props = defineProps<{
  modelValue: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  imported: []
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

type DialogState = 'idle' | 'previewing' | 'preview_ok' | 'preview_err' | 'importing' | 'done' | 'error'

const state = ref<DialogState>('idle')
const preview = ref<ImportResultDto | null>(null)
const selectedFile = ref<File | null>(null)

const importing = computed(() => state.value === 'importing')
const hasPreview = computed(
  () => state.value === 'preview_ok' || state.value === 'preview_err',
)

const importButtonLabel = computed(() => {
  if (!hasPreview.value) return t('catalog.import.dialog.submit')
  if (state.value === 'preview_err') return t('catalog.import.dialog.submitWithErrors')
  return t('catalog.import.dialog.submit')
})

async function onFileSelected(event: { files: File[] }) {
  const file = event.files[0]
  if (!file) return
  selectedFile.value = file
  preview.value = null
  state.value = 'previewing'

  try {
    const result = await catalogApi.importPreview(file)
    preview.value = result
    state.value = result.errors.length > 0 ? 'preview_err' : 'preview_ok'
  } catch (err) {
    state.value = 'error'
    toast.add({
      severity: 'error',
      summary: t('catalog.import.dialog.errorToast'),
      detail: getApiErrorMessage(err, t('catalog.import.dialog.errorToast')),
      life: 5000,
    })
  }
}

async function onImport() {
  if (!selectedFile.value) return
  state.value = 'importing'

  try {
    const result = await catalogApi.importConfirm(selectedFile.value)
    state.value = 'done'
    toast.add({
      severity: 'success',
      summary: t('catalog.import.dialog.successToast', {
        inserted: result.inserted,
        updated: result.updated,
      }),
      life: 5000,
    })
    emit('imported')
    visible.value = false
  } catch (err) {
    state.value = 'preview_err'
    toast.add({
      severity: 'error',
      summary: t('catalog.import.dialog.errorToast'),
      detail: getApiErrorMessage(err, t('catalog.import.dialog.errorToast')),
      life: 5000,
    })
  }
}

function resetPreview() {
  preview.value = null
  selectedFile.value = null
  state.value = 'idle'
}

function onCancel() {
  visible.value = false
}

function onHide() {
  resetPreview()
}

function downloadTemplate() {
  const url = catalogApi.downloadTemplateUrl()
  const link = document.createElement('a')
  link.href = url
  link.download = 'price_template.xlsx'
  document.body.appendChild(link)
  link.click()
  document.body.removeChild(link)
}
</script>

<style lang="scss" scoped>
.import-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;

  &__step-label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    margin: 0 0 $space-3;
  }

  &__dropzone {
    border: 2px dashed $surface-300;
    border-radius: $radius-lg;
    padding: $space-6;
    text-align: center;
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-2;
    background: $surface-50;
  }

  &__upload-icon {
    font-size: 2.5rem;
    color: $surface-400;
  }

  &__upload-hint {
    font-size: $font-size-sm;
    color: $surface-600;
    margin: 0;
  }

  &__upload-max {
    font-size: $font-size-xs;
    color: $surface-400;
    margin: 0;
  }

  &__file-btn {
    margin-top: $space-2;
  }

  &__spinner {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-4;

    p {
      font-size: $font-size-sm;
      color: $surface-600;
      margin: 0;
    }
  }

  &__preview-tags {
    display: flex;
    flex-wrap: wrap;
    gap: $space-2;
    margin-bottom: $space-2;
  }

  &__error-table {
    max-height: 160px;
    overflow-y: auto;
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
    width: 100%;
  }
}

.mt-2 {
  margin-top: $space-2;
}

.mt-3 {
  margin-top: $space-3;
}

// Dark mode
:global(.app-dark) .import-dialog__dropzone {
  background: var(--p-surface-900);
  border-color: var(--p-surface-700);
}

:global(.app-dark) .p-dialog {
  background: var(--p-surface-card);
  color: var(--p-text-color);
}
</style>
