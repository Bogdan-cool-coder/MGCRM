<template>
  <div class="inline-edit" :class="{ 'inline-edit--editing': isEditing }">
    <!-- Display mode -->
    <div
      v-if="!isEditing"
      class="inline-edit__display"
      :title="t('company.page.inlineEdit.hint')"
      @dblclick="startEdit"
    >
      <span class="inline-edit__value">
        <slot name="display" :value="modelValue">
          {{ displayValue || '—' }}
        </slot>
      </span>
      <i class="pi pi-pencil inline-edit__hint-icon" />
    </div>

    <!-- Edit mode -->
    <div v-else class="inline-edit__edit-row">
      <!-- Text input -->
      <InputText
        v-if="fieldType === 'text'"
        ref="inputRef"
        v-model="localStringValue"
        :disabled="saving"
        :placeholder="placeholder"
        class="inline-edit__input"
        @keydown="onKeydown"
      />

      <!-- Textarea -->
      <Textarea
        v-else-if="fieldType === 'textarea'"
        ref="inputRef"
        v-model="localStringValue"
        :disabled="saving"
        :placeholder="placeholder"
        :rows="3"
        auto-resize
        class="inline-edit__input"
        @keydown="onTextareaKeydown"
      />

      <!-- Select -->
      <Select
        v-else-if="fieldType === 'select'"
        ref="inputRef"
        v-model="localSelectValue"
        :options="options"
        :option-label="optionLabel"
        :option-value="optionValue"
        :disabled="saving"
        :placeholder="placeholder"
        show-clear
        class="inline-edit__input"
      />

      <!-- Geo-country select -->
      <Select
        v-else-if="fieldType === 'geo-country'"
        ref="inputRef"
        v-model="localSelectValue"
        :options="directoriesStore.activeCountries"
        option-label="name"
        option-value="code"
        :disabled="saving"
        :placeholder="placeholder"
        show-clear
        filter
        class="inline-edit__input"
      />

      <!-- Geo-city select -->
      <Select
        v-else-if="fieldType === 'geo-city'"
        ref="inputRef"
        v-model="localSelectValue"
        :options="citiesForCurrentCountry"
        option-label="name"
        option-value="name"
        :disabled="saving"
        :placeholder="placeholder"
        show-clear
        filter
        class="inline-edit__input"
      />

      <!-- Actions -->
      <Button
        icon="pi pi-check"
        size="small"
        :loading="saving"
        :title="t('company.page.inlineEdit.save')"
        class="inline-edit__btn-save"
        @click="save"
      />
      <Button
        icon="pi pi-times"
        size="small"
        severity="secondary"
        text
        :disabled="saving"
        :title="t('company.page.inlineEdit.cancel')"
        @click="cancel"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { useDirectoriesStore } from '@/stores/directories'

export type FieldType = 'text' | 'select' | 'textarea' | 'geo-country' | 'geo-city'

const props = withDefaults(
  defineProps<{
    modelValue: string | number | null | undefined
    fieldKey: string
    fieldType?: FieldType
    options?: Array<Record<string, unknown>>
    optionLabel?: string
    optionValue?: string
    label?: string
    required?: boolean
    placeholder?: string
    saving?: boolean
    countryCode?: string | null
  }>(),
  {
    fieldType: 'text',
    options: () => [],
    optionLabel: 'name',
    optionValue: 'id',
    saving: false,
    countryCode: null,
  },
)

const emit = defineEmits<{
  save: [fieldKey: string, value: string | number | null]
}>()

const { t } = useI18n()
const directoriesStore = useDirectoriesStore()

const isEditing = ref(false)
const localStringValue = ref<string>('')
const localSelectValue = ref<string | number | null>(null)
const inputRef = ref<{ $el?: HTMLElement; focus?: () => void } | null>(null)

const displayValue = computed(() => {
  const v = props.modelValue
  if (v === null || v === undefined || v === '') return ''

  if (props.fieldType === 'select' && props.options.length) {
    const opt = props.options.find(
      (o) => (o[props.optionValue ?? 'id'] as unknown) === v,
    )
    return opt ? String(opt[props.optionLabel ?? 'name'] ?? '') : String(v)
  }
  if (props.fieldType === 'geo-country') {
    return directoriesStore.getCountryName(String(v)) || String(v)
  }
  return String(v)
})

const citiesForCurrentCountry = computed(() => {
  return directoriesStore.getCitiesForCountry(props.countryCode)
})

function isSelectType(): boolean {
  return ['select', 'geo-country', 'geo-city'].includes(props.fieldType)
}

function startEdit() {
  if (props.saving) return
  isEditing.value = true
  const v = props.modelValue ?? null
  if (isSelectType()) {
    localSelectValue.value = v as string | number | null
  } else {
    localStringValue.value = v !== null && v !== undefined ? String(v) : ''
  }
  nextTick(() => {
    if (inputRef.value) {
      if (typeof inputRef.value.focus === 'function') {
        inputRef.value.focus()
      } else if (inputRef.value.$el) {
        const el = inputRef.value.$el.querySelector<HTMLElement>('input, textarea')
        el?.focus()
      }
    }
  })
}

function cancel() {
  isEditing.value = false
  localStringValue.value = ''
  localSelectValue.value = null
}

async function save() {
  if (props.saving) return
  const value = isSelectType() ? localSelectValue.value : localStringValue.value || null
  emit('save', props.fieldKey, value)
  isEditing.value = false
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    void save()
  } else if (e.key === 'Escape') {
    cancel()
  }
}

function onTextareaKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') {
    cancel()
  }
}

// If parent commits changes, close edit mode
watch(
  () => props.modelValue,
  () => {
    if (isEditing.value) {
      isEditing.value = false
    }
  },
)
</script>

<style lang="scss" scoped>
.inline-edit {
  &__display {
    display: flex;
    align-items: center;
    gap: $space-2;
    cursor: pointer;
    padding: $space-1 $space-2;
    border-radius: $radius-sm;
    border: 1px solid transparent;
    transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast);
    min-height: 32px;

    &:hover {
      border-color: $surface-300;
      background-color: $surface-50;

      .inline-edit__hint-icon {
        opacity: 1;
      }
    }
  }

  &__value {
    flex: 1;
    font-size: $font-size-sm;
    color: $surface-900;
    word-break: break-word;
  }

  &__hint-icon {
    font-size: $font-size-xs;
    color: $surface-400;
    opacity: 0;
    flex-shrink: 0;
    transition: opacity var(--app-transition-fast);
  }

  &__edit-row {
    display: flex;
    align-items: flex-start;
    gap: $space-1;
  }

  &__input {
    flex: 1;
    font-size: $font-size-sm;
  }

  &__btn-save {
    flex-shrink: 0;
  }
}
</style>
