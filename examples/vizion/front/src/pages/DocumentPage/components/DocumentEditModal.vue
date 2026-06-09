<template>
  <Dialog
    :visible="visible"
    modal
    :header="t('edit.title')"
    :closable="!saving && !uploading"
    :breakpoints="{ '1199px': '80vw', '575px': '95vw' }"
    :style="{ width: '52rem' }"
    @update:visible="onVisibleChange"
  >
    <div class="document-edit">
      <!-- AI shortcut — opens the document-generation modal in edit-mode. The
           manual flow below stays the default; this is an escape hatch. -->
      <div class="document-edit__ai">
        <Button
          :label="t('edit.editWithAi')"
          icon="pi pi-sparkles"
          severity="help"
          outlined
          size="small"
          :disabled="saving || uploading"
          @click="onEditWithAi"
        />
        <small class="document-edit__ai-hint">{{ t('edit.editWithAiHint') }}</small>
      </div>

      <!-- Name (ru / en) -->
      <div class="form-group">
        <label for="de-name-ru" class="form-label">
          {{ t('edit.name_ru_label') }} <span class="required">*</span>
        </label>
        <InputText
          id="de-name-ru"
          v-model="nameRu"
          class="w-full"
          :placeholder="t('edit.name_ru_placeholder')"
          :class="{ 'p-invalid': errors.nameRu }"
          @input="errors.nameRu = ''"
        />
        <small v-if="errors.nameRu" class="p-error">{{ errors.nameRu }}</small>
      </div>

      <div class="form-group">
        <label for="de-name-en" class="form-label">{{ t('edit.name_en_label') }}</label>
        <InputText
          id="de-name-en"
          v-model="nameEn"
          class="w-full"
          :placeholder="t('edit.name_en_placeholder')"
        />
      </div>

      <!-- Description (ru / en) -->
      <div class="form-group">
        <label for="de-desc-ru" class="form-label">{{ t('edit.description_ru_label') }}</label>
        <Textarea
          id="de-desc-ru"
          v-model="descriptionRu"
          class="w-full"
          rows="2"
          auto-resize
          :placeholder="t('edit.description_ru_placeholder')"
        />
      </div>

      <div class="form-group">
        <label for="de-desc-en" class="form-label">{{ t('edit.description_en_label') }}</label>
        <Textarea
          id="de-desc-en"
          v-model="descriptionEn"
          class="w-full"
          rows="2"
          auto-resize
          :placeholder="t('edit.description_en_placeholder')"
        />
      </div>

      <!-- ─── Source-file upload (manual editing = upload) ────────────────── -->
      <div class="form-group source-block">
        <div class="form-label-row">
          <label class="form-label">{{ t('edit.source_label') }}</label>
          <Button
            v-tooltip.left="t('docx.catalog.title')"
            icon="pi pi-question-circle"
            severity="secondary"
            text
            rounded
            size="small"
            :aria-label="t('docx.catalog.title')"
            :disabled="saving"
            @click="openCatalog"
          />
        </div>

        <p class="form-help source-hint">
          <i :class="['pi', isHtml ? 'pi-file' : 'pi-file-word']" aria-hidden="true" />
          <span v-if="hasSource">{{ isHtml ? t('edit.source_has_html') : t('edit.source_has_docx') }}</span>
          <span v-else>{{ isHtml ? t('edit.source_none_html') : t('edit.source_none_docx') }}</span>
        </p>

        <FileUpload
          mode="basic"
          :auto="false"
          :accept="acceptExtensions"
          :max-file-size="MAX_FILE_SIZE"
          :choose-label="uploading ? t('edit.source_uploading') : uploadLabel"
          custom-upload
          :disabled="uploading || saving"
          @select="onSourceSelect"
        />
        <small class="form-help">{{ isHtml ? t('edit.source_limit_html') : t('edit.source_limit_docx') }}</small>

        <!-- Extracted placeholders, read-only, known / unknown markers. -->
        <div v-if="hasSource" class="placeholders">
          <LoadingState v-if="placeholdersLoading && placeholderRows.length === 0" />

          <EmptyState
            v-else-if="placeholderRows.length === 0"
            :message="t('edit.placeholders_none')"
            icon="pi pi-inbox"
          />

          <template v-else>
            <span class="placeholders__title">{{ t('edit.placeholders_found') }}</span>
            <ul class="placeholder-list">
              <li
                v-for="row in placeholderRows"
                :key="row.token"
                class="placeholder-row"
                :class="{ 'is-unknown': !row.known }"
              >
                <i
                  :class="['pi', row.known ? 'pi-check-circle' : 'pi-exclamation-circle', 'placeholder-icon']"
                  aria-hidden="true"
                />
                <code class="placeholder-token">{{ tokenDisplay(row.token) }}</code>
                <span class="placeholder-status">
                  {{ row.known ? t('edit.placeholder_known') : t('edit.placeholder_unknown') }}
                </span>
              </li>
            </ul>
          </template>
        </div>
      </div>
    </div>

    <template #footer>
      <Button :label="t('edit.cancel')" text :disabled="saving || uploading" @click="close" />
      <Button :label="t('edit.save')" icon="pi pi-check" :loading="saving" :disabled="uploading" @click="submit" />
    </template>

    <FieldCatalogModal
      :visible="catalogVisible"
      :loading="catalogLoading"
      :catalog="catalog"
      :t="t"
      :locale="locale"
      @update:visible="onCatalogVisibleChange"
    />
  </Dialog>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Button from 'primevue/button'
import FileUpload, { type FileUploadSelectEvent } from 'primevue/fileupload'
import Tooltip from 'primevue/tooltip'
import LoadingState from '@/components/states/LoadingState.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import FieldCatalogModal from './FieldCatalogModal.vue'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useDocumentGenerationModalStore } from '@/stores/documentGenerationModal'
import { getApiErrorStatus } from '@/utils/errors'
import { isKnownPlaceholder } from '@/entities/document'
import type { DocumentTemplate, DocumentFieldCatalog } from '@/entities/document'
import type { UpdateDocumentTemplateRequest } from '@/api/types/documents'
import type { LocalizedText } from '@/shared/types'

const vTooltip = Tooltip

/** 10 MB — mirrors the backend `max:10240` (KB) validation. */
const MAX_FILE_SIZE = 10 * 1024 * 1024

interface Props {
  visible: boolean
  template: DocumentTemplate | null
  /** Locale function from the page (shares the DocumentPage locale namespace). */
  t: (_key: string) => string
  /** Active locale string ('ru' / 'en') for resolving catalog labels. */
  locale: string
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  /** Emitted after a successful save / upload — payload is the fresh template. */
  saved: [template: DocumentTemplate]
}>()

const { documentService } = useServices()
const { notifySuccess, notifyApiError } = useNotifications()
const documentGenerationModal = useDocumentGenerationModalStore()

const isHtml = computed(() => props.template?.type === 'html')
const acceptExtensions = computed(() => (isHtml.value ? '.html,.htm' : '.docx'))

// ─── Name / description form state ────────────────────────────────────────────
const nameRu = ref('')
const nameEn = ref('')
const descriptionRu = ref('')
const descriptionEn = ref('')
const saving = ref(false)
const errors = reactive<{ nameRu: string }>({ nameRu: '' })

// ─── Source + placeholders ────────────────────────────────────────────────────
const hasSource = ref(false)
const uploading = ref(false)
const placeholders = ref<string[]>([])
const placeholdersLoading = ref(false)

const uploadLabel = computed(() =>
  hasSource.value ? props.t('edit.source_replace') : props.t('edit.source_choose'),
)

const placeholderRows = computed(() =>
  placeholders.value.map((token) => ({
    token,
    known: catalog.value !== null && isKnownPlaceholder(token, catalog.value),
  })),
)

const tokenDisplay = (token: string): string =>
  isHtml.value ? `{{${token}}}` : `\${${token}}`

// ─── Field catalogue (lazy) ───────────────────────────────────────────────────
const catalog = ref<DocumentFieldCatalog | null>(null)
const catalogLoading = ref(false)
const catalogVisible = ref(false)

const loadCatalog = async () => {
  if (catalog.value !== null || catalogLoading.value) return
  catalogLoading.value = true
  try {
    catalog.value = await documentService.fetchFieldCatalog()
  } catch (error: unknown) {
    notifyApiError(error, props.t('edit.errors.loadCatalog'))
  } finally {
    catalogLoading.value = false
  }
}

const openCatalog = () => {
  void loadCatalog()
  catalogVisible.value = true
}

const onCatalogVisibleChange = (value: boolean) => {
  catalogVisible.value = value
}

const loadPlaceholders = async () => {
  const id = props.template?.id
  if (!id || id <= 0 || !hasSource.value) {
    placeholders.value = []
    return
  }
  placeholdersLoading.value = true
  try {
    placeholders.value = await documentService.fetchPlaceholders(id)
  } catch (error: unknown) {
    // 422 = no source / unreadable; treat as "no placeholders" silently.
    if (getApiErrorStatus(error) === 422) {
      placeholders.value = []
    } else {
      notifyApiError(error, props.t('edit.errors.loadPlaceholders'))
    }
  } finally {
    placeholdersLoading.value = false
  }
}

// ─── Seed / reset on open ─────────────────────────────────────────────────────
const seedFromTemplate = () => {
  const tpl = props.template
  errors.nameRu = ''
  saving.value = false
  uploading.value = false

  if (!tpl) {
    nameRu.value = ''
    nameEn.value = ''
    descriptionRu.value = ''
    descriptionEn.value = ''
    hasSource.value = false
    placeholders.value = []
    return
  }

  nameRu.value = typeof tpl.name === 'object' ? (tpl.name.ru ?? '') : String(tpl.name ?? '')
  nameEn.value = typeof tpl.name === 'object' ? (tpl.name.en ?? '') : ''

  const desc = tpl.description
  descriptionRu.value = desc && typeof desc === 'object' ? (desc.ru ?? '') : ''
  descriptionEn.value = desc && typeof desc === 'object' ? (desc.en ?? '') : ''

  hasSource.value = (tpl.sourcePath ?? null) !== null
}

watch(
  () => props.visible,
  (visible) => {
    if (visible) {
      seedFromTemplate()
      void loadCatalog()
      void loadPlaceholders()
    }
  },
)

// ─── Source upload ────────────────────────────────────────────────────────────
const onSourceSelect = (event: FileUploadSelectEvent) => {
  const files = Array.isArray(event.files) ? event.files : [event.files]
  const file = files[0] as File | undefined
  if (file) void uploadSource(file)
}

const uploadSource = async (file: File) => {
  const tpl = props.template
  if (!tpl || uploading.value) return
  uploading.value = true
  try {
    const sourcePath = await documentService.uploadSourceFile(tpl.id, file)
    hasSource.value = true
    notifySuccess(props.t('edit.source_uploaded'))
    // Reflect the new source on the parent template so the page re-renders.
    emit('saved', { ...tpl, sourcePath })
    await loadPlaceholders()
  } catch (error: unknown) {
    notifyApiError(error, props.t('edit.errors.upload'))
  } finally {
    uploading.value = false
  }
}

// ─── Save name / description ──────────────────────────────────────────────────
const buildLocalized = (ru: string, en: string): LocalizedText | null => {
  const ruTrim = ru.trim()
  const enTrim = en.trim()
  if (!ruTrim && !enTrim) return null
  const out: Record<string, string> = {}
  if (ruTrim) out.ru = ruTrim
  if (enTrim) out.en = enTrim
  return out
}

const submit = async () => {
  const tpl = props.template
  if (!tpl) return

  const ruName = nameRu.value.trim()
  if (!ruName) {
    errors.nameRu = props.t('edit.name_required')
    return
  }

  const name: Record<string, string> = { ru: ruName }
  const enName = nameEn.value.trim()
  if (enName) name.en = enName

  const payload: UpdateDocumentTemplateRequest = {
    name,
    description: buildLocalized(descriptionRu.value, descriptionEn.value),
  }

  saving.value = true
  try {
    const updated = await documentService.updateTemplate(tpl.id, payload)
    notifySuccess(props.t('edit.success'))
    emit('saved', updated)
    emit('update:visible', false)
  } catch (error: unknown) {
    notifyApiError(error, props.t('edit.errors.save'))
  } finally {
    saving.value = false
  }
}

// ─── AI hand-off ──────────────────────────────────────────────────────────────
const onEditWithAi = () => {
  const tpl = props.template
  if (!tpl) return
  // Close the manual editor and open the global generation modal in edit-mode
  // bound to this template. The open document page refetches in place once the
  // AI turn settles (driven by `signalDocumentUpdated`).
  emit('update:visible', false)
  documentGenerationModal.open({ mode: 'edit', documentId: tpl.id })
}

const onVisibleChange = (value: boolean) => {
  if (!value && (saving.value || uploading.value)) return
  emit('update:visible', value)
}

const close = () => {
  emit('update:visible', false)
}
</script>

<style lang="scss" scoped>
.document-edit {
  display: flex;
  flex-direction: column;
  gap: 1rem;

  &__ai {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;
    padding: 0.75rem;
    border: 1px dashed rgba($primary, 0.4);
    border-radius: $card-border-radius;
    background: rgba($primary, 0.03);

    &-hint {
      font-size: $font-size-xs;
      color: $surface-500;
    }
  }

  .form-group {
    display: flex;
    flex-direction: column;
    gap: 0.4rem;

    .form-label {
      font-size: $font-size-sm;
      font-weight: $font-weight-medium;
      color: $surface-700;

      .required {
        color: $danger;
      }
    }

    .form-label-row {
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      flex-wrap: wrap;
    }

    .form-help {
      font-size: $font-size-xs;
      color: $surface-500;
    }

    .p-error {
      color: $danger;
      font-size: $font-size-xs;
    }
  }

  .source-block {
    padding-top: 0.75rem;
    border-top: 1px solid $surface-200;

    .source-hint {
      display: inline-flex;
      align-items: center;
      gap: 0.5rem;
      font-size: $font-size-sm;
      color: $surface-600;
    }
  }

  .placeholders {
    margin-top: 0.5rem;
    display: flex;
    flex-direction: column;
    gap: 0.4rem;

    &__title {
      font-size: $font-size-xs;
      font-weight: $font-weight-semibold;
      color: $surface-600;
      text-transform: uppercase;
      letter-spacing: 0.03em;
    }
  }

  .placeholder-list {
    list-style: none;
    margin: 0;
    padding: 0;
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
  }

  .placeholder-row {
    display: flex;
    align-items: center;
    gap: 0.6rem;
    padding: 0.3rem 0.5rem;
    border-radius: 4px;
    background: $surface-50;

    .placeholder-icon {
      font-size: 0.85rem;
      color: $primary-color;
      flex-shrink: 0;
    }

    .placeholder-token {
      font-family: monospace;
      font-size: $font-size-sm;
      color: $surface-800;
      overflow: hidden;
      text-overflow: ellipsis;
      white-space: nowrap;
      flex: 1;
      min-width: 0;
    }

    .placeholder-status {
      font-size: $font-size-xs;
      color: $surface-500;
      flex-shrink: 0;
    }

    &.is-unknown {
      .placeholder-icon {
        color: $orange-500;
      }

      .placeholder-token {
        color: $surface-600;
      }
    }
  }
}
</style>
