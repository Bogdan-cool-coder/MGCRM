<template>
  <div class="macrodata-mapping-section">
    <div class="section-header">
      <div class="section-header-text">
        <h3 class="section-title">{{ t('macrodataMapping.title') }}</h3>
        <p class="section-subtitle">{{ t('macrodataMapping.subtitle') }}</p>
      </div>
      <div class="section-header-actions">
        <Button
          icon="pi pi-search"
          :label="probing ? t('macrodataMapping.scanning') : t('macrodataMapping.scanButton')"
          severity="info"
          :loading="probing"
          :disabled="!companyId || loading"
          @click="runProbe"
        />
      </div>
    </div>

    <div v-if="loading && !mappings.length" class="loading-state">
      <ProgressSpinner style="width: 32px; height: 32px" />
    </div>

    <DataTable
      v-else
      :value="mappings"
      :loading="loading"
      class="mappings-table"
      :empty-message="t('macrodataMapping.emptyState')"
    >
      <template #empty>
        <div class="empty-state">{{ t('macrodataMapping.emptyState') }}</div>
      </template>
      <Column
        field="semantic_key"
        :header="t('macrodataMapping.table.semanticKey')"
        style="width: 28%"
      >
        <template #body="{ data }">
          <code class="semantic-key">{{ data.semantic_key }}</code>
        </template>
      </Column>
      <Column :header="t('macrodataMapping.table.value')" style="width: 26%">
        <template #body="{ data }">
          <!--
            Array literal is rendered with explicit `[` / `]` brackets so the
            user can visually tell apart `[3884]` (single-element array) from
            the scalar `3884`. Scalars render as one chip without brackets.
            `null` / empty string / empty array → muted em-dash.
          -->
          <span v-if="isEmptyValue(data.value)" class="value-empty">—</span>
          <span v-else-if="Array.isArray(data.value)" class="value-array">
            <span class="value-bracket">[</span>
            <Tag
              v-for="(item, index) in data.value"
              :key="`${data.id}-${index}`"
              :value="String(item)"
              severity="info"
              rounded
            />
            <span class="value-bracket">]</span>
          </span>
          <Tag
            v-else-if="typeof data.value === 'number' || typeof data.value === 'string'"
            :value="String(data.value)"
            severity="info"
            rounded
          />
          <code v-else class="value-json">{{ formatValueInline(data.value) }}</code>
        </template>
      </Column>
      <Column
        field="notes"
        :header="t('macrodataMapping.table.notes')"
        style="width: 22%"
      >
        <template #body="{ data }">
          <span v-if="data.notes" class="notes-text">{{ data.notes }}</span>
          <span v-else class="value-empty">—</span>
        </template>
      </Column>
      <Column
        :header="t('macrodataMapping.table.autoProbedAt')"
        style="width: 14%"
      >
        <template #body="{ data }">
          <span v-if="data.auto_probed_at" class="probed-at">{{
            formatProbedAt(data.auto_probed_at)
          }}</span>
          <span v-else class="value-empty">{{
            t('macrodataMapping.table.neverAutoProbed')
          }}</span>
        </template>
      </Column>
      <Column :header="t('macrodataMapping.table.actions')" style="width: 10%">
        <template #body="{ data }">
          <ActionButtonGroup @edit="openEdit(data)" @delete="confirmDelete(data)" />
        </template>
      </Column>
    </DataTable>

    <!-- Create / edit one mapping -->
    <Dialog
      v-model:visible="editVisible"
      modal
      :header="
        editMode === 'create'
          ? t('macrodataMapping.edit.createTitle')
          : t('macrodataMapping.edit.editTitle')
      "
      :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
      :closable="true"
      :style="{ width: '520px' }"
    >
      <div class="mapping-form">
        <div class="form-group">
          <label for="semantic_key" class="form-label">
            {{ t('macrodataMapping.edit.semanticKeyLabel') }}
          </label>
          <InputText
            id="semantic_key"
            v-model="editForm.semantic_key"
            :placeholder="t('macrodataMapping.edit.semanticKeyPlaceholder')"
            :disabled="editMode === 'edit'"
            :class="{ 'p-invalid': !!editErrors.semantic_key }"
          />
          <small v-if="editErrors.semantic_key" class="p-error">{{ editErrors.semantic_key }}</small>
          <small v-else class="form-help">{{ t('macrodataMapping.edit.semanticKeyHelp') }}</small>
        </div>

        <div class="form-group">
          <label for="value" class="form-label">{{ t('macrodataMapping.edit.valueLabel') }}</label>
          <Textarea
            id="value"
            v-model="editForm.value"
            rows="3"
            autoResize
            :placeholder="t('macrodataMapping.edit.valuePlaceholder')"
            :class="{ 'p-invalid': !!editErrors.value }"
          />
          <small v-if="editErrors.value" class="p-error">{{ editErrors.value }}</small>
          <small v-else class="form-help">{{ t('macrodataMapping.edit.valueHelp') }}</small>
        </div>

        <div class="form-group">
          <label for="notes" class="form-label">{{ t('macrodataMapping.edit.notesLabel') }}</label>
          <InputText
            id="notes"
            v-model="editForm.notes"
            :placeholder="t('macrodataMapping.edit.notesPlaceholder')"
          />
        </div>
      </div>

      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          :disabled="saving"
          @click="closeEdit"
        />
        <Button :label="t('common.save')" :loading="saving" @click="submitEdit" />
      </template>
    </Dialog>

    <!-- Probe diff dialog -->
    <MacrodataMappingProbeDialog
      v-model:visible="probeDialogVisible"
      :result="probeResult"
      :current-mappings="mappings"
      :applying="applyingProbe"
      @apply="applyProbeSelection"
      @cancel="closeProbeDialog"
    />

    <!-- Delete confirm -->
    <DeleteConfirmModal
      v-model:visible="deleteDialogVisible"
      :item-name="mappingToDelete?.semantic_key"
      :title="t('macrodataMapping.delete.title')"
      :loading="deleting"
      :warning-text="t('macrodataMapping.delete.warning')"
      @cancel="cancelDelete"
      @confirm="performDelete"
    >
      <template #message>
        {{ t('macrodataMapping.delete.message') }}
        <strong>{{ mappingToDelete?.semantic_key }}</strong
        >?
      </template>
    </DeleteConfirmModal>
  </div>
</template>

<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue'
import Button from 'primevue/button'
import Column from 'primevue/column'
import DataTable from 'primevue/datatable'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import ProgressSpinner from 'primevue/progressspinner'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import { ActionButtonGroup } from '@/components/tables'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import { macrodataMappingsApi } from '@/api/macrodataMappings'
import type {
  MacrodataMappingDto,
  MacrodataMappingUpsertItem,
  MacrodataProbeResultDto,
} from '@/api/types/macrodataMappings'
import axios from 'axios'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useNotifications } from '@/composables/useNotifications'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'
import MacrodataMappingProbeDialog from './MacrodataMappingProbeDialog.vue'

const { t } = useLocalI18n({ en, ru })
const { notifySuccess, notifyError, notifyApiError } = useNotifications()

// Toast auto-dismiss window for this section. Tightened from the global
// default (4000ms) because the mapping workflow can produce 2–3 toasts in
// quick succession (edit → edit → delete) and the stack visually overlaps
// the action buttons when toasts linger.
const TOAST_LIFE_MS = 3000

interface Props {
  /** Active company id. The section becomes a no-op while id is missing. */
  companyId: number | null
}
const props = defineProps<Props>()

const SEMANTIC_KEY_PATTERN = /^[a-z][a-z0-9_]*$/

const mappings = ref<MacrodataMappingDto[]>([])
const loading = ref(false)

const editVisible = ref(false)
const editMode = ref<'create' | 'edit'>('create')
const saving = ref(false)
const editForm = reactive({
  semantic_key: '',
  value: '',
  notes: '',
})
interface EditErrors {
  semantic_key?: string
  value?: string
}
const editErrors = reactive<EditErrors>({})

const deleteDialogVisible = ref(false)
const deleting = ref(false)
const mappingToDelete = ref<MacrodataMappingDto | null>(null)

const probing = ref(false)
const probeDialogVisible = ref(false)
const probeResult = ref<MacrodataProbeResultDto | null>(null)
const applyingProbe = ref(false)

const fetchMappings = async () => {
  if (!props.companyId) {
    mappings.value = []
    return
  }
  loading.value = true
  try {
    mappings.value = await macrodataMappingsApi.listMappings(props.companyId)
  } catch (error: unknown) {
    notifyApiError(error, t('macrodataMapping.loadError'), undefined, TOAST_LIFE_MS)
  } finally {
    loading.value = false
  }
}

// Re-fetch when the active company id changes. Initial mount is also covered
// via `immediate: true`. The watcher gates on falsy id and clears the list,
// so a brief render between scope-switch transitions never shows stale rows
// from the previous company.
watch(
  () => props.companyId,
  () => {
    void fetchMappings()
  },
  { immediate: true },
)

const isEmptyValue = (value: unknown): boolean => {
  if (value === null || value === undefined) return true
  if (Array.isArray(value)) return value.length === 0
  if (typeof value === 'string') return value.length === 0
  return false
}

const formatValueInline = (value: unknown): string => {
  if (value === null || value === undefined) return '—'
  try {
    return JSON.stringify(value)
  } catch {
    return String(value)
  }
}

const formatProbedAt = (iso: string): string => {
  // The backend timestamp is ISO-8601 UTC. Format with the user's locale
  // without crashing if the string ends up malformed for any reason.
  try {
    const date = new Date(iso)
    if (Number.isNaN(date.getTime())) return iso
    return date.toLocaleString()
  } catch {
    return iso
  }
}

const resetEditForm = () => {
  editForm.semantic_key = ''
  editForm.value = ''
  editForm.notes = ''
  editErrors.semantic_key = undefined
  editErrors.value = undefined
}

const openCreate = () => {
  editMode.value = 'create'
  resetEditForm()
  editVisible.value = true
}

const openEdit = (mapping: MacrodataMappingDto) => {
  editMode.value = 'edit'
  resetEditForm()
  editForm.semantic_key = mapping.semantic_key
  editForm.value = formatValueInline(mapping.value)
  editForm.notes = mapping.notes ?? ''
  editVisible.value = true
}

const closeEdit = () => {
  editVisible.value = false
  resetEditForm()
}

const submitEdit = async () => {
  if (!props.companyId) return
  editErrors.semantic_key = undefined
  editErrors.value = undefined

  const semanticKey = editForm.semantic_key.trim()
  if (!SEMANTIC_KEY_PATTERN.test(semanticKey)) {
    editErrors.semantic_key = t('macrodataMapping.edit.semanticKeyInvalid')
    return
  }

  if (editMode.value === 'create') {
    const existing = mappings.value.some((m) => m.semantic_key === semanticKey)
    if (existing) {
      editErrors.semantic_key = t('macrodataMapping.edit.semanticKeyExists')
      return
    }
  }

  let parsedValue: unknown
  const rawValue = editForm.value.trim()
  if (!rawValue) {
    editErrors.value = t('macrodataMapping.edit.valueInvalid')
    return
  }
  try {
    parsedValue = JSON.parse(rawValue)
  } catch {
    editErrors.value = t('macrodataMapping.edit.valueInvalid')
    return
  }

  const item: MacrodataMappingUpsertItem = {
    semantic_key: semanticKey,
    value: parsedValue,
    notes: editForm.notes.trim() || null,
  }

  saving.value = true
  try {
    // Bulk upsert with a single item — backend treats a missing key as
    // "leave others untouched", so this only writes the one we care about.
    const updated = await macrodataMappingsApi.bulkUpsertMappings(props.companyId, [item])
    mappings.value = updated
    editVisible.value = false
    resetEditForm()
    notifySuccess(t('macrodataMapping.success.saved'), undefined, TOAST_LIFE_MS)
  } catch (error: unknown) {
    notifyApiError(error, t('macrodataMapping.errors.saveFailed'), undefined, TOAST_LIFE_MS)
  } finally {
    saving.value = false
  }
}

const confirmDelete = (mapping: MacrodataMappingDto) => {
  mappingToDelete.value = mapping
  deleteDialogVisible.value = true
}

const cancelDelete = () => {
  deleteDialogVisible.value = false
  mappingToDelete.value = null
}

const performDelete = async () => {
  if (!props.companyId || !mappingToDelete.value) return
  deleting.value = true
  try {
    await macrodataMappingsApi.deleteMapping(
      props.companyId,
      mappingToDelete.value.semantic_key,
    )
    mappings.value = mappings.value.filter(
      (m) => m.semantic_key !== mappingToDelete.value?.semantic_key,
    )
    notifySuccess(t('macrodataMapping.success.deleted'), undefined, TOAST_LIFE_MS)
    deleteDialogVisible.value = false
    mappingToDelete.value = null
  } catch (error: unknown) {
    notifyApiError(error, t('macrodataMapping.errors.deleteFailed'), undefined, TOAST_LIFE_MS)
  } finally {
    deleting.value = false
  }
}

const runProbe = async () => {
  if (!props.companyId) return
  probing.value = true
  try {
    const result = await macrodataMappingsApi.probeMappings(props.companyId)
    probeResult.value = result
    probeDialogVisible.value = true
  } catch (error: unknown) {
    // 503 with `{error: 'macrodata_unavailable', message}` — backend
    // signalled the client DB is unreachable. Show the message verbatim
    // so the admin can act on it (fix host/port, retry, etc.).
    if (axios.isAxiosError(error) && error.response?.status === 503) {
      const data = error.response.data as { message?: string } | undefined
      const message = data?.message || t('macrodataMapping.errors.macrodataUnavailable')
      notifyError(message, undefined, TOAST_LIFE_MS)
    } else {
      notifyApiError(error, t('macrodataMapping.errors.probeFailed'), undefined, TOAST_LIFE_MS)
    }
  } finally {
    probing.value = false
  }
}

const closeProbeDialog = () => {
  probeDialogVisible.value = false
  probeResult.value = null
}

const applyProbeSelection = async (selectedKeys: string[]) => {
  if (!props.companyId || !probeResult.value) return
  // Stamp every row in this batch with the single probe-scan timestamp.
  // Backend treats `auto_probed_at` as a partial-update field — sending it
  // here marks the row as "discovered/refreshed by probe at this moment".
  // Manual inline edits (submitEdit) intentionally omit the key so the
  // existing timestamp is preserved.
  const probedAt = probeResult.value.probed_at
  const items: MacrodataMappingUpsertItem[] = probeResult.value.mappings
    .filter((m) => selectedKeys.includes(m.semantic_key))
    .map((m) => ({
      semantic_key: m.semantic_key,
      value: m.value,
      notes: m.matched_by ? `Auto-probed: ${m.matched_by}` : null,
      auto_probed_at: probedAt,
    }))

  if (!items.length) {
    closeProbeDialog()
    return
  }

  applyingProbe.value = true
  try {
    const updated = await macrodataMappingsApi.bulkUpsertMappings(props.companyId, items)
    mappings.value = updated
    notifySuccess(t('macrodataMapping.success.probeApplied'), undefined, TOAST_LIFE_MS)
    closeProbeDialog()
  } catch (error: unknown) {
    notifyApiError(error, t('macrodataMapping.errors.saveFailed'), undefined, TOAST_LIFE_MS)
  } finally {
    applyingProbe.value = false
  }
}

// Expose for tests / future toolbar wiring.
const hasMappings = computed(() => mappings.value.length > 0)
defineExpose({ openCreate, hasMappings })
</script>

<style lang="scss" scoped>
.macrodata-mapping-section {
  display: flex;
  flex-direction: column;
  gap: 1rem;

  .section-header {
    display: flex;
    flex-wrap: wrap;
    align-items: flex-start;
    justify-content: space-between;
    gap: 1rem;

    .section-header-text {
      max-width: 640px;

      .section-title {
        margin: 0 0 0.25rem;
        font-size: $font-size-lg;
        font-weight: $font-weight-semibold;
        color: $surface-900;
      }

      .section-subtitle {
        margin: 0;
        font-size: $font-size-sm;
        color: $surface-600;
        line-height: 1.4;
      }
    }
  }

  .loading-state {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: 2rem 0;
  }

  .empty-state {
    padding: 1.5rem 1rem;
    text-align: center;
    color: $surface-600;
    font-size: $font-size-sm;
  }

  .mappings-table {
    .semantic-key {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
      font-size: $font-size-sm;
      color: $surface-900;
      background: $surface-100;
      padding: 0.125rem 0.375rem;
      border-radius: $border-radius;
    }

    .value-chips {
      display: flex;
      flex-wrap: wrap;
      gap: 0.25rem;
    }

    // Inline array-literal: brackets sit on the baseline next to the chips,
    // monospace + muted so they read as syntactic punctuation rather than
    // data. The wrapper is `inline-flex` so the row stays single-line when
    // the array fits and wraps naturally when it doesn't.
    .value-array {
      display: inline-flex;
      flex-wrap: wrap;
      align-items: center;
      gap: 0.25rem;
    }

    .value-bracket {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
      font-size: $font-size-sm;
      color: $surface-500;
      line-height: 1;
    }

    .value-json {
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, 'Liberation Mono', monospace;
      font-size: $font-size-sm;
      color: $surface-800;
    }

    .value-empty {
      color: $surface-400;
    }

    .notes-text {
      font-size: $font-size-sm;
      color: $surface-700;
    }

    .probed-at {
      font-size: $font-size-xs;
      color: $surface-600;
    }
  }

  .mapping-form {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;

    .form-group {
      display: flex;
      flex-direction: column;
      gap: 0.375rem;

      .form-label {
        font-size: $font-size-sm;
        font-weight: $font-weight-medium;
        color: $surface-700;
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
  }
}
</style>
