<template>
  <!--
    Compact, inline rendering of the report's *primary* filter, surfaced in
    the header right of the title (red-frame area in the design). It reuses
    the very same filter component the big filter panel uses (resolved by
    `config.type`) so there is zero markup duplication — the only difference
    is a compact, single-row presentation enforced via scoped `:deep()` rules
    (the inner `.filter-label` rendered by the child components is hidden and
    replaced by our inline label).

    Source of truth is shared with the panel: this widget binds straight to
    the parent's `localFilters[field]` via `model-value` + `@update:model-value`
    (the same props/handlers the panel passes). So changing the value here
    mutates the same state the panel reads, and vice-versa — no second state.

    Application differs from the panel (which has an "Apply" button):
      - select / async_select / date_range → apply immediately on change
      - text / number_range → debounced apply (typing-friendly)
  -->
  <div class="report-header-primary-filter">
    <span v-if="resolvedLabel" class="report-header-primary-filter__label">
      {{ resolvedLabel }}:
    </span>
    <div class="report-header-primary-filter__control">
      <component
        :is="filterComponent"
        v-if="filterComponent"
        :field="field"
        :config="config"
        :model-value="modelValue"
        @update:model-value="onChange"
        @update:selected-label="onSelectedLabel"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, type Component } from 'vue'
import AsyncSelectFilter from '@/components/filters/AsyncSelectFilter.vue'
import DateRangeFilter from '@/components/filters/DateRangeFilter.vue'
import SelectFilter from '@/components/filters/SelectFilter.vue'
import TextFilter from '@/components/filters/TextFilter.vue'
import NumberRangeFilter from '@/components/filters/NumberRangeFilter.vue'
import type {
  ReportFilterConfig,
  ReportFilterType,
  ReportFilterValue,
} from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'

interface Props {
  field: string
  config: ReportFilterConfig
  modelValue?: ReportFilterValue
}

const props = defineProps<Props>()

const emit = defineEmits<{
  /** Fires when the value should be committed + applied (debounce already handled). */
  apply: [field: string, value: ReportFilterValue]
  /** Mirrors AsyncSelectFilter's label surfacing so the parent label cache stays in sync. */
  'update:selectedLabel': [field: string, value: string | null, label: string | null]
}>()

// Text / number filters fire on every keystroke — debounce the apply so we
// don't refetch the report per character. Select / date apply immediately.
const DEBOUNCE_MS = 450
const DEBOUNCED_TYPES: ReadonlySet<ReportFilterType> = new Set<ReportFilterType>([
  'text',
  'number_range',
])

let debounceTimer: ReturnType<typeof setTimeout> | null = null

const clearDebounce = (): void => {
  if (debounceTimer !== null) {
    clearTimeout(debounceTimer)
    debounceTimer = null
  }
}

const filterComponent = computed<Component | null>(() => {
  switch (props.config.type) {
    case 'async_select':
      return AsyncSelectFilter
    case 'date_range':
      return DateRangeFilter
    case 'multiselect':
    case 'select':
      return SelectFilter
    case 'text':
      return TextFilter
    case 'number_range':
      return NumberRangeFilter
    default:
      return null
  }
})

const resolvedLabel = computed<string | null>(() => {
  if (props.config.label) {
    return getLocalizedText(props.config.label)
  }
  return null
})

const onChange = (value: ReportFilterValue): void => {
  if (DEBOUNCED_TYPES.has(props.config.type)) {
    clearDebounce()
    debounceTimer = setTimeout(() => {
      emit('apply', props.field, value)
      debounceTimer = null
    }, DEBOUNCE_MS)
    return
  }
  // select / async_select / date_range → apply right away.
  clearDebounce()
  emit('apply', props.field, value)
}

const onSelectedLabel = (_value: string | null, label: string | null): void => {
  emit('update:selectedLabel', props.field, _value, label)
}

onBeforeUnmount(clearDebounce)
</script>

<style lang="scss" scoped>
.report-header-primary-filter {
  display: inline-flex;
  align-items: center;
  gap: 0.4rem;
  min-width: 0;
  max-width: 100%;

  &__label {
    font-size: $font-size-sm;
    color: $surface-500;
    white-space: nowrap;
    flex-shrink: 0;
  }

  &__control {
    min-width: 0;
    flex: 1 1 auto;
  }

  // Compact the reused panel filter components for the header strip:
  //  - drop their stacked top label (we render an inline one above)
  //  - collapse the column layout to a single inline row
  //  - cap the overall width so the widget stays in the header line
  :deep(.filter-field) {
    flex-direction: row;
    align-items: center;
    gap: 0.4rem;
    margin: 0;

    > .filter-label {
      display: none;
    }
  }

  // Single controls (select / async_select / text) — give them a sensible,
  // bounded width so they read as a compact header control rather than a
  // full-width panel field.
  :deep(.p-select),
  :deep(.p-multiselect),
  :deep(.p-inputtext) {
    min-width: 12rem;
  }

  // Range controls (date_range / number_range) render two inputs side by side.
  :deep(.date-range-inputs),
  :deep(.number-range-inputs) {
    gap: 0.4rem;

    .date-input,
    .number-input {
      flex: 0 0 auto;

      :deep(.p-inputtext) {
        min-width: 9rem;
        min-height: 2.25rem;
      }
    }
  }

  // Slightly shorter controls than the panel's 2.5rem to fit the header row.
  :deep(.p-select-label),
  :deep(.p-multiselect-label) {
    min-height: 2.25rem;
  }
}

@media (max-width: 767px) {
  .report-header-primary-filter {
    flex-wrap: wrap;

    :deep(.p-select),
    :deep(.p-multiselect),
    :deep(.p-inputtext) {
      min-width: 0;
      width: 100%;
    }
  }
}
</style>
