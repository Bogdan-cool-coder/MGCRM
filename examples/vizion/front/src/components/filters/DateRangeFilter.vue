<template>
  <div class="filter-field">
    <label class="filter-label">{{ getLabel(config) }}</label>
    <div class="date-range-inputs">
      <Calendar
        v-model="fromDate"
        view="date"
        date-format="dd.mm.yy"
        :manual-input="true"
        :show-icon="true"
        icon-display="input"
        :placeholder="t('dateFrom')"
        class="date-input"
        input-class="w-full"
        @update:model-value="emitChange"
      />
      <Calendar
        v-model="toDate"
        view="date"
        date-format="dd.mm.yy"
        :manual-input="true"
        :show-icon="true"
        icon-display="input"
        :placeholder="t('dateTo')"
        class="date-input"
        input-class="w-full"
        @update:model-value="emitChange"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import Calendar from 'primevue/calendar'
import type { DateRangeFilterConfig, DateRangeValue } from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  field: string
  config: DateRangeFilterConfig
  modelValue?: DateRangeValue
}

const props = withDefaults(defineProps<Props>(), {
  modelValue: () => ({}),
})

const emit = defineEmits<{
  'update:modelValue': [value: DateRangeValue]
}>()

const fromDate = ref<Date | null>(null)
const toDate = ref<Date | null>(null)

const parseDateValue = (value: string): Date | null => {
  // Handle relative dates like "-90 days", "today"
  if (typeof value === 'string') {
    if (value === 'today') {
      return new Date()
    }
    if (value.includes('days')) {
      const days = parseInt(value.replace(' days', '').replace('-', ''))
      const date = new Date()
      date.setDate(date.getDate() + days)
      return date
    }
    // Parse YYYY-MM-DD in local timezone to avoid UTC midnight → day-1 shift.
    // new Date('2026-05-31') is treated as UTC midnight which in UTC+ zones
    // becomes the previous calendar day when .getDate() is called later.
    const isoOnly = /^\d{4}-\d{2}-\d{2}$/.test(value)
    if (isoOnly) {
      const parts = value.split('-')
      const y = Number(parts[0])
      const m = Number(parts[1])
      const d = Number(parts[2])
      return new Date(y, m - 1, d)
    }
    // Try parsing as-is for any other string format
    const parsed = new Date(value)
    return isNaN(parsed.getTime()) ? null : parsed
  }
  return null
}

// Initialize from model value
watch(
  () => props.modelValue,
  (newVal) => {
    if (newVal?.from) {
      fromDate.value = parseDateValue(newVal.from)
    } else {
      fromDate.value = null
    }

    if (newVal?.to) {
      toDate.value = parseDateValue(newVal.to)
    } else {
      toDate.value = null
    }
  },
  { immediate: true, deep: true },
)

const formatDateValue = (date: Date | null): string | null => {
  if (!date) return null
  // Serialize in local timezone to avoid UTC-shift off-by-one.
  // toISOString() converts to UTC midnight, which in UTC+ zones gives the
  // previous calendar date (e.g. user clicks May 31 → "2026-05-30").
  const y = date.getFullYear()
  const m = String(date.getMonth() + 1).padStart(2, '0')
  const d = String(date.getDate()).padStart(2, '0')
  return `${y}-${m}-${d}`
}

const getLabel = (config: DateRangeFilterConfig): string => {
  if (config.label) {
    return getLocalizedText(config.label)
  }
  // Capitalize field name
  return props.field.replace(/_/g, ' ').replace(/\b\w/g, (l) => l.toUpperCase())
}

const emitChange = () => {
  const value: DateRangeValue = {
    from: formatDateValue(fromDate.value),
    to: formatDateValue(toDate.value),
  }
  emit('update:modelValue', value)
}
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

  .date-range-inputs {
    display: flex;
    align-items: center;
    gap: 0.75rem;

    .date-input {
      flex: 1;

      // Calendar with icon-display="input" overlays the icon on the right
      // edge of the input. Reserve space on the right so the date text
      // (e.g. 31.05.2026) does not collide with the calendar icon.
      :deep(.p-inputtext) {
        width: 100%;
        display: flex;
        align-items: center;
        min-height: 2.5rem;
        padding: 0 2.25rem 0 0.5rem;
        font-size: 1rem;
      }
    }
  }
}
</style>
