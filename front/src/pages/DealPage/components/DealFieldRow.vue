<template>
  <div class="deal-field-row">
    <span class="deal-field-row__label">{{ label }}</span>
    <div class="deal-field-row__value">
      <!-- URL field — read-only with external link icon -->
      <template v-if="fieldType === 'url'">
        <template v-if="modelValue">
          <a
            :href="ensureHttp(String(modelValue))"
            target="_blank"
            rel="noopener noreferrer"
            class="deal-field-row__url-link"
          >
            {{ String(modelValue) }}
            <i class="pi pi-external-link deal-field-row__url-icon" />
          </a>
        </template>
        <span v-else class="deal-field-row__empty">—</span>
        <i
          v-if="editable"
          class="pi pi-pencil deal-field-row__edit-trigger"
          @click="startEdit"
        />
      </template>

      <!-- Inline-edit fields -->
      <template v-else-if="editable">
        <InlineEditableField
          :model-value="safeModelValue"
          :field-key="fieldKey"
          :field-type="inlineFieldType"
          :options="options"
          :option-label="optionLabel"
          :option-value="optionValue"
          :placeholder="placeholder"
          :saving="saving"
          @save="onSave"
        />
      </template>

      <!-- Read-only -->
      <template v-else>
        <span class="deal-field-row__read-only">{{ displayValue || '—' }}</span>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import InlineEditableField from '@/components/crm/InlineEditableField.vue'
import type { FieldType } from '@/components/crm/InlineEditableField.vue'

type DealFieldType = 'text' | 'select' | 'date' | 'url' | 'number' | 'bool'

const props = withDefaults(
  defineProps<{
    label: string
    fieldKey: string
    modelValue?: string | number | null
    fieldType?: DealFieldType
    editable?: boolean
    saving?: boolean
    options?: Array<Record<string, unknown>>
    optionLabel?: string
    optionValue?: string
    placeholder?: string
  }>(),
  {
    modelValue: null,
    fieldType: 'text',
    editable: true,
    saving: false,
    options: () => [],
    optionLabel: 'name',
    optionValue: 'id',
    placeholder: undefined,
  },
)

const emit = defineEmits<{
  save: [fieldKey: string, value: string | number | null]
}>()

// The editing flag used by the URL-type pencil icon
const _editingUrl = ref(false)

// Map DealFieldType to InlineEditableField fieldType
const inlineFieldType = computed((): FieldType => {
  if (props.fieldType === 'select') return 'select'
  return 'text'
})

// Safe cast for InlineEditableField which expects string | number | null | undefined
const safeModelValue = computed(
  (): string | number | null | undefined => props.modelValue,
)

const displayValue = computed(() => {
  const v = props.modelValue
  if (v === null || v === undefined || v === '') return ''
  if (props.fieldType === 'bool') return v ? 'Да' : 'Нет'
  if (props.fieldType === 'select' && props.options.length) {
    const optValueKey = props.optionValue ?? 'id'
    const optLabelKey = props.optionLabel ?? 'name'
    const opt = props.options.find((o) => o[optValueKey] === v)
    return opt ? String(opt[optLabelKey] ?? '') : String(v)
  }
  return String(v)
})

function ensureHttp(url: string): string {
  if (!url) return '#'
  if (/^https?:\/\//i.test(url)) return url
  return `https://${url}`
}

function startEdit() {
  _editingUrl.value = true
}

function onSave(key: string, value: string | number | null) {
  emit('save', key, value)
}
</script>

<style lang="scss" scoped>
.deal-field-row {
  display: grid;
  grid-template-columns: 100px 1fr;
  align-items: start;
  gap: $space-2;
  padding: $space-1 $space-4;
  min-height: 32px;
}

.deal-field-row__label {
  font-size: $font-size-xs;
  color: $surface-500;
  padding-top: 6px;
  line-height: 1.4;
}

.deal-field-row__value {
  display: flex;
  align-items: center;
  gap: $space-1;
  min-width: 0;
}

.deal-field-row__url-link {
  font-size: $font-size-sm;
  color: $primary-color;
  text-decoration: none;
  display: flex;
  align-items: center;
  gap: $space-1;
  word-break: break-all;

  &:hover {
    text-decoration: underline;
  }
}

.deal-field-row__url-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

.deal-field-row__edit-trigger {
  font-size: $font-size-xs;
  color: $surface-400;
  cursor: pointer;
  flex-shrink: 0;
  margin-left: $space-1;

  &:hover {
    color: $primary-color;
  }
}

.deal-field-row__empty {
  font-size: $font-size-sm;
  color: $surface-400;
}

.deal-field-row__read-only {
  font-size: $font-size-sm;
  color: $surface-700;
  padding: 6px $space-2;
}
</style>
