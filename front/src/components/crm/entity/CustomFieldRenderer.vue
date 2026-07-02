<template>
  <div class="custom-field-renderer">
    <!-- Loading schema skeleton -->
    <div v-if="schemaLoading" class="custom-field-renderer__skeleton">
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" class="mb-2" />
      <Skeleton height="32px" />
    </div>

    <!-- Error -->
    <Message v-else-if="schemaError" severity="warn" class="mb-3">
      {{ t('crm.customFields.schemaError') }}
    </Message>

    <!-- Empty definitions -->
    <div
      v-else-if="definitions.length === 0"
      class="custom-field-renderer__empty"
    >
      <i class="pi pi-sliders-h custom-field-renderer__empty-icon" />
      <p class="custom-field-renderer__empty-text">{{ t('crm.customFields.empty') }}</p>
    </div>

    <!-- Fields grouped by group -->
    <template v-else>
      <div
        v-for="group in groupedDefinitions"
        :key="group.name"
        class="custom-field-renderer__group"
      >
        <div v-if="group.name" class="custom-field-renderer__group-title">{{ group.name }}</div>

        <KeyFactsBlock>
          <template v-for="def in group.fields" :key="def.code">
            <KeyFactsItem :label="def.label" :required="def.required">
              <!-- ── Text / Textarea ─────────────────────────────────────── -->
              <InlineEditableField
                v-if="def.field_type === 'text' || def.field_type === 'textarea'"
                :model-value="getStringValue(def.code)"
                :field-key="def.code"
                :field-type="def.field_type === 'textarea' ? 'textarea' : 'text'"
                :saving="isSaving(def.code)"
                @save="(key, val) => saveField(key, val)"
              />

              <!-- ── Number ─────────────────────────────────────────────── -->
              <InlineEditableField
                v-else-if="def.field_type === 'number'"
                :model-value="getStringValue(def.code)"
                :field-key="def.code"
                field-type="text"
                :saving="isSaving(def.code)"
                @save="(key, val) => saveField(key, val !== null ? Number(val) : null)"
              />

              <!-- ── URL ───────────────────────────────────────────────── -->
              <span v-else-if="def.field_type === 'url'" class="custom-field-renderer__url-wrap">
                <a
                  v-if="getFieldValue(def.code)"
                  :href="String(getFieldValue(def.code))"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="custom-field-renderer__url-link"
                >
                  {{ truncateUrl(String(getFieldValue(def.code))) }}
                  <i class="pi pi-external-link custom-field-renderer__url-icon" />
                </a>
                <InlineEditableField
                  :model-value="getStringValue(def.code)"
                  :field-key="def.code"
                  field-type="text"
                  :saving="isSaving(def.code)"
                  @save="(key, val) => saveField(key, val)"
                />
              </span>

              <!-- ── Date ──────────────────────────────────────────────── -->
              <span v-else-if="def.field_type === 'date'" class="custom-field-renderer__date-wrap">
                <span v-if="!editingField[def.code]" class="custom-field-renderer__date-display" @dblclick="editingField[def.code] = true">
                  {{ formatDate(getStringValue(def.code)) || '—' }}
                  <i class="pi pi-pencil custom-field-renderer__hint-icon" />
                </span>
                <span v-else class="custom-field-renderer__date-edit">
                  <DatePicker
                    v-model="dateEditValues[def.code]"
                    date-format="yy-mm-dd"
                    show-icon
                    fluid
                    class="custom-field-renderer__date-picker"
                  />
                  <Button
                    icon="pi pi-check"
                    size="small"
                    :loading="isSaving(def.code)"
                    @click="saveDateField(def.code)"
                  />
                  <Button
                    icon="pi pi-times"
                    size="small"
                    severity="secondary"
                    text
                    @click="editingField[def.code] = false"
                  />
                </span>
              </span>

              <!-- ── Select ─────────────────────────────────────────────── -->
              <InlineEditableField
                v-else-if="def.field_type === 'select'"
                :model-value="getStringValue(def.code)"
                :field-key="def.code"
                field-type="select"
                :options="selectOptions(def)"
                option-label="label"
                option-value="value"
                :saving="isSaving(def.code)"
                @save="(key, val) => saveField(key, val)"
              />

              <!-- ── MultiSelect ─────────────────────────────────────────── -->
              <span v-else-if="def.field_type === 'multiselect'" class="custom-field-renderer__multi-wrap">
                <span v-if="!editingField[def.code]" class="custom-field-renderer__multi-display" @dblclick="startMultiEdit(def)">
                  <Tag
                    v-for="v in getArrayValue(def.code)"
                    :key="v"
                    :value="getOptionLabel(def, v)"
                    severity="secondary"
                    size="small"
                    class="me-1"
                  />
                  <span v-if="!getArrayValue(def.code).length">—</span>
                  <i class="pi pi-pencil custom-field-renderer__hint-icon" />
                </span>
                <span v-else class="custom-field-renderer__multi-edit">
                  <MultiSelect
                    v-model="multiEditValues[def.code]"
                    :options="selectOptions(def)"
                    option-label="label"
                    option-value="value"
                    fluid
                    class="custom-field-renderer__multi-select"
                    append-to="body"
                  />
                  <Button
                    icon="pi pi-check"
                    size="small"
                    :loading="isSaving(def.code)"
                    @click="saveMultiField(def.code)"
                  />
                  <Button
                    icon="pi pi-times"
                    size="small"
                    severity="secondary"
                    text
                    @click="editingField[def.code] = false"
                  />
                </span>
              </span>

              <!-- ── Boolean ────────────────────────────────────────────── -->
              <span v-else-if="def.field_type === 'boolean'" class="custom-field-renderer__bool-wrap">
                <ToggleSwitch
                  :model-value="Boolean(getFieldValue(def.code))"
                  :disabled="isSaving(def.code)"
                  @update:model-value="(val: boolean) => saveField(def.code, val)"
                />
                <span class="custom-field-renderer__bool-label">
                  {{ Boolean(getFieldValue(def.code)) ? t('common.yes') : t('common.no') }}
                </span>
              </span>

              <!-- ── User ref ────────────────────────────────────────────── -->
              <span v-else-if="def.field_type === 'user_ref'" class="custom-field-renderer__user-ref">
                <span v-if="!editingField[def.code]" class="custom-field-renderer__user-display" @dblclick="editingField[def.code] = true">
                  {{ getUserName(getFieldValue(def.code) as number | null) || '—' }}
                  <i class="pi pi-pencil custom-field-renderer__hint-icon" />
                </span>
                <span v-else class="custom-field-renderer__user-edit">
                  <Select
                    v-model="userEditValues[def.code]"
                    :options="cachedUsers"
                    option-label="name"
                    option-value="id"
                    show-clear
                    fluid
                    append-to="body"
                  />
                  <Button
                    icon="pi pi-check"
                    size="small"
                    :loading="isSaving(def.code)"
                    @click="saveUserRefField(def.code)"
                  />
                  <Button
                    icon="pi pi-times"
                    size="small"
                    severity="secondary"
                    text
                    @click="editingField[def.code] = false"
                  />
                </span>
              </span>

              <!-- ── Fallback ───────────────────────────────────────────── -->
              <span v-else class="custom-field-renderer__fallback">
                {{ getFieldValue(def.code) ?? '—' }}
              </span>
            </KeyFactsItem>
          </template>
        </KeyFactsBlock>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import ToggleSwitch from 'primevue/toggleswitch'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import KeyFactsBlock from './KeyFactsBlock.vue'
import KeyFactsItem from './KeyFactsItem.vue'
import { customFieldsApi } from '@/api/crm/customFields'
import { getApiErrorMessage } from '@/utils/errors'
import type { CustomFieldDef, CustomFieldScope } from '@/entities/crm'

interface FieldGroup {
  name: string
  fields: CustomFieldDef[]
}

interface UserOption {
  id: number
  name: string
}

const props = defineProps<{
  /** 'deal' | 'contact' | 'company' | 'contract' */
  entityScope: CustomFieldScope
  entityId: number
  /** Current extra_fields values from entity */
  extraFields: Record<string, unknown>
  /** Callback to persist extra_fields via PATCH */
  onSave: (code: string, value: unknown) => Promise<void>
  /** Pre-loaded list of users for user_ref fields */
  users?: UserOption[]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Schema loading ─────────────────────────────────────────────────────────────

const definitions = ref<CustomFieldDef[]>([])
const schemaLoading = ref(false)
const schemaError = ref(false)

async function loadSchema() {
  schemaLoading.value = true
  schemaError.value = false
  try {
    // Prefer the schema endpoint (grouped, sorted); fall back to definitions list
    definitions.value = await customFieldsApi.getSchema(props.entityScope)
  } catch {
    try {
      definitions.value = await customFieldsApi.getDefinitions(props.entityScope)
    } catch {
      schemaError.value = true
    }
  } finally {
    schemaLoading.value = false
  }
}

// ── Grouped definitions ───────────────────────────────────────────────────────

const groupedDefinitions = computed((): FieldGroup[] => {
  const groups = new Map<string, CustomFieldDef[]>()

  for (const def of definitions.value) {
    if (!def.is_active) continue
    const groupKey = def.group ?? ''
    const existing = groups.get(groupKey)
    if (existing) {
      existing.push(def)
    } else {
      groups.set(groupKey, [def])
    }
  }

  return Array.from(groups.entries())
    .map(([name, fields]) => ({ name, fields }))
    .sort((a, b) => a.name.localeCompare(b.name))
})

// ── Field values ──────────────────────────────────────────────────────────────

function getFieldValue(code: string): unknown {
  return props.extraFields[code] ?? null
}

function getStringValue(code: string): string | null {
  const v = getFieldValue(code)
  if (v === null || v === undefined) return null
  return String(v)
}

function getArrayValue(code: string): string[] {
  const v = getFieldValue(code)
  if (Array.isArray(v)) return v as string[]
  return []
}


// ── Options helpers ───────────────────────────────────────────────────────────

function selectOptions(def: CustomFieldDef): Array<{ value: string; label: string }> {
  if (!def.options) return []
  return def.options.map((o) => ({ value: o, label: o }))
}

function getOptionLabel(def: CustomFieldDef, value: string): string {
  const opt = selectOptions(def).find((o) => o.value === value)
  return opt?.label ?? value
}

// ── Save handling ──────────────────────────────────────────────────────────────

const savingFields = reactive<Record<string, boolean>>({})

function isSaving(code: string): boolean {
  return savingFields[code] ?? false
}

async function saveField(code: string, value: unknown) {
  savingFields[code] = true
  try {
    await props.onSave(code, value)
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    savingFields[code] = false
  }
}

// ── Date field ────────────────────────────────────────────────────────────────

const editingField = reactive<Record<string, boolean>>({})
const dateEditValues = reactive<Record<string, Date | null>>({})

function formatDate(val: string | null): string {
  if (!val) return ''
  const d = new Date(val)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })
}

function saveDateField(code: string) {
  const d = dateEditValues[code]
  const formatted = d
    ? `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
    : null
  void saveField(code, formatted).then(() => {
    editingField[code] = false
  })
}

// ── MultiSelect field ─────────────────────────────────────────────────────────

const multiEditValues = reactive<Record<string, string[]>>({})

function startMultiEdit(def: CustomFieldDef) {
  const current = getFieldValue(def.code)
  multiEditValues[def.code] = Array.isArray(current) ? (current as string[]) : []
  editingField[def.code] = true
}

function saveMultiField(code: string) {
  void saveField(code, multiEditValues[code] ?? []).then(() => {
    editingField[code] = false
  })
}

// ── User ref field ────────────────────────────────────────────────────────────

const userEditValues = reactive<Record<string, number | null>>({})

const cachedUsers = computed(() => props.users ?? [])

function getUserName(userId: number | null): string {
  if (!userId) return ''
  const user = cachedUsers.value.find((u) => u.id === userId)
  return user?.name ?? String(userId)
}

function saveUserRefField(code: string) {
  void saveField(code, userEditValues[code] ?? null).then(() => {
    editingField[code] = false
  })
}

// ── URL helpers ───────────────────────────────────────────────────────────────

function truncateUrl(url: string): string {
  try {
    const u = new URL(url)
    return u.hostname + (u.pathname !== '/' ? u.pathname : '')
  } catch {
    return url.slice(0, 40)
  }
}

// ── Lifecycle ─────────────────────────────────────────────────────────────────

onMounted(() => {
  void loadSchema()
})
</script>

<style lang="scss" scoped>
.custom-field-renderer {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.custom-field-renderer__skeleton {
  display: flex;
  flex-direction: column;
}

.custom-field-renderer__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6;
  text-align: center;
}

.custom-field-renderer__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}

.custom-field-renderer__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.custom-field-renderer__group {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.custom-field-renderer__group-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $surface-500;
  padding-bottom: $space-1;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
    color: var(--p-surface-400);
  }
}

// ── URL field ──────────────────────────────────────────────────────────────────
.custom-field-renderer__url-wrap {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  width: 100%;
}

.custom-field-renderer__url-link {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-sm;
  color: var(--p-primary-color);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }
}

.custom-field-renderer__url-icon {
  font-size: $font-size-3xs;
}

// ── Date field ─────────────────────────────────────────────────────────────────
.custom-field-renderer__date-display {
  display: flex;
  align-items: center;
  gap: $space-2;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-800;
  padding: $space-1 $space-2;
  border-radius: $radius-sm;
  border: 1px solid transparent;
  min-height: 32px;

  &:hover {
    border-color: $surface-300;
    background: $surface-50;

    .custom-field-renderer__hint-icon {
      opacity: 1;
    }
  }
}

.custom-field-renderer__date-edit {
  display: flex;
  align-items: center;
  gap: $space-1;
  width: 100%;
}

.custom-field-renderer__date-picker {
  flex: 1;
}

// ── Multi field ────────────────────────────────────────────────────────────────
.custom-field-renderer__multi-display {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-1;
  cursor: pointer;
  padding: $space-1 $space-2;
  border-radius: $radius-sm;
  border: 1px solid transparent;
  min-height: 32px;

  &:hover {
    border-color: $surface-300;
    background: $surface-50;

    .custom-field-renderer__hint-icon {
      opacity: 1;
    }
  }
}

.custom-field-renderer__multi-edit {
  display: flex;
  align-items: center;
  gap: $space-1;
  width: 100%;
}

.custom-field-renderer__multi-select {
  flex: 1;
}

// ── Boolean field ──────────────────────────────────────────────────────────────
.custom-field-renderer__bool-wrap {
  display: flex;
  align-items: center;
  gap: $space-2;
  min-height: 32px;
}

.custom-field-renderer__bool-label {
  font-size: $font-size-sm;
  color: $surface-700;
}

// ── User ref field ─────────────────────────────────────────────────────────────
.custom-field-renderer__user-ref {
  width: 100%;
}

.custom-field-renderer__user-display {
  display: flex;
  align-items: center;
  gap: $space-2;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-800;
  padding: $space-1 $space-2;
  border-radius: $radius-sm;
  border: 1px solid transparent;
  min-height: 32px;

  &:hover {
    border-color: $surface-300;
    background: $surface-50;

    .custom-field-renderer__hint-icon {
      opacity: 1;
    }
  }
}

.custom-field-renderer__user-edit {
  display: flex;
  align-items: center;
  gap: $space-1;
  width: 100%;
}

// ── Hint icon (shared) ─────────────────────────────────────────────────────────
.custom-field-renderer__hint-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  opacity: 0;
  flex-shrink: 0;
  transition: opacity var(--app-transition-fast);
}

// ── Fallback ───────────────────────────────────────────────────────────────────
.custom-field-renderer__fallback {
  font-size: $font-size-sm;
  color: $surface-600;
}
</style>
