<template>
  <div class="filter-field">
    <label class="filter-label">{{ label }}</label>

    <!-- Multi-select mode (config.multiple === true) -->
    <MultiSelect
      v-if="props.config.multiple"
      :model-value="selectedValues"
      :options="displayedOptions"
      option-value="value"
      option-label="label"
      :placeholder="t('selectMultiplePlaceholder')"
      :filter="true"
      :filter-placeholder="t('searchPlaceholder')"
      :loading="loading"
      display="chip"
      class="w-full"
      @show="onDropdownShow"
      @filter="onFilter"
      @update:model-value="onMultiSelect"
    >
      <!-- While a request is in flight (or before the first load completes)
           show a loading indicator instead of "No available options". The
           empty message is only meaningful after the backend has answered. -->
      <template #empty>
        <div v-if="showLoadingState" class="async-select-loading">
          <ProgressSpinner class="async-select-loading__spinner" stroke-width="4" />
          <span>{{ t('loadingOptions') }}</span>
        </div>
        <span v-else>{{ t('noOptions') }}</span>
      </template>
    </MultiSelect>

    <!-- Single-select mode (default) -->
    <Select
      v-else
      :model-value="selectedValue"
      :options="displayedOptions"
      option-value="value"
      option-label="label"
      :placeholder="t('selectSinglePlaceholder')"
      :filter="true"
      :filter-placeholder="t('searchPlaceholder')"
      :loading="loading"
      :clearable="true"
      class="w-full"
      @show="onDropdownShow"
      @filter="onFilter"
      @update:model-value="onSelect"
    >
      <template #empty>
        <div v-if="showLoadingState" class="async-select-loading">
          <ProgressSpinner class="async-select-loading__spinner" stroke-width="4" />
          <span>{{ t('loadingOptions') }}</span>
        </div>
        <span v-else>{{ t('noOptions') }}</span>
      </template>
    </Select>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch, onUnmounted } from 'vue'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import ProgressSpinner from 'primevue/progressspinner'
import type { AsyncSelectFilterConfig } from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { reportsApi } from '@/api/reports'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface SelectOption {
  value: string
  label: string
}

interface Props {
  field: string
  config: AsyncSelectFilterConfig
  modelValue?: string | string[] | null
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: null,
})

const emit = defineEmits<{
  'update:modelValue': [value: string | string[] | null]
  /**
   * Emitted in single-select mode alongside `update:modelValue` so the parent
   * can resolve a human-readable label for the chosen value (the value itself
   * is an opaque id). Used by ReportPage's header filter summary (#11) to show
   * "Контрагент: {имя}" instead of a raw id. `label` is null when the value is
   * cleared. Not emitted in multi-select mode (header summary skips arrays).
   */
  'update:selectedLabel': [value: string | null, label: string | null]
}>()

// Backed by useAsyncResource — its internal requestGate drops stale responses
// when the dropdown is reopened mid-flight or when debounce fires multiple
// queries in quick succession. The component no longer hand-rolls
// pending / try-catch / race-protection.
const optionsResource = useAsyncResource<SelectOption[]>(() => [])
const remoteOptions = optionsResource.data
const loading = optionsResource.loading
const didInitialLoad = ref(false)
// True once a request has fully settled at least once for the current
// endpoint. Until then (or while a fresh request is in flight) the empty
// dropdown shows a loading indicator rather than "No available options".
const firstLoadComplete = ref(false)

// Drives the `#empty` slot: show the spinner while a request is pending or
// before the very first response for this endpoint has arrived.
const showLoadingState = computed<boolean>(() => loading.value || !firstLoadComplete.value)

// ---------------------------------------------------------------------------
// Single-select state
// ---------------------------------------------------------------------------

// Persists the label of the last option the user explicitly chose.
// Used as a fallback label when remoteOptions is empty or does not
// contain the current modelValue (e.g. before first dropdown open,
// or after a debounced search that filtered the option out).
const selectedLabel = ref<string | null>(null)

const selectedValue = computed<string | null>(() =>
  !props.config.multiple && typeof props.modelValue === 'string' ? props.modelValue : null,
)

// Always guaranteed to contain a record for the current value so the Select
// control can resolve the label and display it rather than falling back to
// the placeholder.
const displayedOptions = computed<SelectOption[]>(() => {
  const list = [...remoteOptions.value]

  if (!props.config.multiple) {
    // Single mode: inject current value into the list when missing
    const current = selectedValue.value
    if (current !== null && !list.some((o) => o.value === current)) {
      list.unshift({ value: current, label: selectedLabel.value ?? current })
    }
  } else {
    // Multi mode: inject any selected value that is not in the fetched list
    const current = selectedValues.value
    for (const v of current) {
      if (!list.some((o) => o.value === v)) {
        const persisted = selectedLabels.value[v]
        list.unshift({ value: v, label: persisted ?? v })
      }
    }
  }

  return list
})

// ---------------------------------------------------------------------------
// Multi-select state
// ---------------------------------------------------------------------------

// Map of value → label for all items the user has explicitly selected.
// This prevents the "chip goes blank" problem when remoteOptions no longer
// contains a selected value (e.g. after the search query changes).
const selectedLabels = ref<Record<string, string>>({})

const selectedValues = computed<string[]>(() => {
  if (!props.config.multiple) return []
  if (Array.isArray(props.modelValue)) return props.modelValue as string[]
  return []
})

// ---------------------------------------------------------------------------
// Debounce helper
// ---------------------------------------------------------------------------
let debounceTimer: ReturnType<typeof setTimeout> | null = null
const DEBOUNCE_MS = 300

const loadOptions = async (q: string): Promise<void> => {
  try {
    // Use `commit` so requestGate (inside useAsyncResource) decides whether
    // the result is still the latest one. If a newer call superseded this
    // one, commit is never invoked and remoteOptions stays whatever was
    // last successfully fetched — better than clearing on stale responses.
    await optionsResource.run(
      async () => (await reportsApi.fetchFilterOptions(props.config.search_endpoint, q)).options,
      {
        commit: (options) => {
          remoteOptions.value = options

          // Capture labels for currently selected items while the result is fresh.
          if (props.config.multiple) {
            for (const v of selectedValues.value) {
              const match = options.find((o) => o.value === v)
              if (match) {
                selectedLabels.value[v] = match.label
              }
            }
          } else if (selectedValue.value !== null) {
            const match = options.find((o) => o.value === selectedValue.value)
            if (match) {
              selectedLabel.value = match.label
              // Late label resolution for a value set externally (e.g. a
              // backend default or a restored filter) — surface it so the
              // header summary (#11) can replace the raw id with the name.
              emit('update:selectedLabel', selectedValue.value, match.label)
            }
          }
        },
      },
    )
  } catch {
    // Leave existing remoteOptions as-is on error — better than clearing them
  } finally {
    // Mark the endpoint as "answered at least once" so the empty dropdown
    // can switch from the loading indicator to the "no options" message.
    firstLoadComplete.value = true
  }
}

const onDropdownShow = (): void => {
  if (!didInitialLoad.value) {
    didInitialLoad.value = true
    void loadOptions('')
  }
}

const onFilter = (event: { value: string }): void => {
  const q = event.value ?? ''
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer)
  }
  debounceTimer = setTimeout(() => {
    void loadOptions(q)
  }, DEBOUNCE_MS)
}

// ---------------------------------------------------------------------------
// Single-select handler
// ---------------------------------------------------------------------------
const onSelect = (value: string | null): void => {
  if (value !== null) {
    const chosen = displayedOptions.value.find((o) => o.value === value)
    if (chosen) {
      selectedLabel.value = chosen.label
    }
  } else {
    selectedLabel.value = null
  }
  emit('update:modelValue', value ?? null)
  // Surface the resolved label for the parent's header summary (#11).
  emit('update:selectedLabel', value ?? null, value !== null ? selectedLabel.value : null)
}

// ---------------------------------------------------------------------------
// Multi-select handler
// ---------------------------------------------------------------------------
const onMultiSelect = (values: string[]): void => {
  // Capture labels for all newly selected items
  for (const v of values) {
    if (!selectedLabels.value[v]) {
      const found = displayedOptions.value.find((o) => o.value === v)
      if (found) {
        selectedLabels.value[v] = found.label
      }
    }
  }
  // Remove labels for deselected items
  for (const v of Object.keys(selectedLabels.value)) {
    if (!values.includes(v)) {
      delete selectedLabels.value[v]
    }
  }
  emit('update:modelValue', values.length > 0 ? values : null)
}

// ---------------------------------------------------------------------------
// Label display
// ---------------------------------------------------------------------------
const label = computed<string>(() => {
  if (props.config.label) {
    return getLocalizedText(props.config.label)
  }
  return props.field.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())
})

// ---------------------------------------------------------------------------
// Watchers
// ---------------------------------------------------------------------------

// Reset the initial-load flag and cached state when the search_endpoint
// changes so options are refreshed if the component is reused for a
// different field.
watch(
  () => props.config.search_endpoint,
  () => {
    didInitialLoad.value = false
    firstLoadComplete.value = false
    // reset() also invalidates the requestGate — any in-flight request for the
    // previous endpoint is dropped instead of overwriting the cleared options.
    optionsResource.reset([])
    selectedLabel.value = null
    selectedLabels.value = {}
  },
)

// When modelValue is cleared externally (e.g. resetFilters), discard the
// stale labels so the placeholder is shown correctly on the next render.
watch(
  () => props.modelValue,
  (newVal) => {
    if (newVal === null || newVal === undefined) {
      selectedLabel.value = null
      selectedLabels.value = {}
    } else if (Array.isArray(newVal) && newVal.length === 0) {
      selectedLabels.value = {}
    }
  },
)

onUnmounted(() => {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer)
  }
})
</script>

<style lang="scss" scoped>
.filter-field {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;

  .filter-label {
    font-weight: $font-weight-semibold;
    font-size: $font-size-sm;
    color: $surface-700;
  }

  :deep(.p-select-label) {
    display: flex;
    align-items: center;
    min-height: 2.5rem;
    padding: 0 0.5rem;
    font-size: 1rem;
  }

  :deep(.p-multiselect-label) {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 0.25rem;
    min-height: 2.5rem;
    padding: 0.25rem 0.5rem;
    font-size: 1rem;
  }
}

.async-select-loading {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: 0.5rem;
  padding: 0.25rem 0;
  font-size: $font-size-sm;
  color: $surface-600;

  &__spinner {
    width: 1.1rem;
    height: 1.1rem;

    :deep(.p-progressspinner-circle) {
      stroke: var(--app-action-primary-bg);
    }
  }
}
</style>
