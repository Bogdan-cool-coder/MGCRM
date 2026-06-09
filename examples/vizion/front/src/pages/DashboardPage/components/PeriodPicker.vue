<template>
  <div class="period-picker">
    <div class="period-picker__presets">
      <Button
        v-for="preset in presets"
        :key="preset.id"
        :label="t(`presets.${preset.id}`)"
        size="small"
        rounded
        :severity="activePreset === preset.id ? undefined : 'secondary'"
        :outlined="activePreset !== preset.id"
        @click="applyPreset(preset.id)"
      />
    </div>

    <DatePicker
      :model-value="dateRange"
      selection-mode="range"
      view="month"
      date-format="mm/yy"
      show-icon
      icon-display="input"
      :max-date="maxDate"
      :manual-input="false"
      :placeholder="t('rangePlaceholder')"
      class="period-picker__input"
      @update:model-value="onChange"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import DatePicker from 'primevue/datepicker'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type { PeriodRange } from '@/api/types/dashboards'
import {
  matchPreset,
  monthKeyToDate,
  normaliseRange,
  presetRange,
  toMonthKey,
  type PeriodPresetId,
} from '../composables/periodRange'
import en from './locale/en.json'
import ru from './locale/ru.json'

interface Props {
  /** Inclusive `YYYY-MM` month range applied to every period-aware widget. */
  modelValue: PeriodRange
}

const props = defineProps<Props>()

const emit = defineEmits<{
  'update:modelValue': [value: PeriodRange]
}>()

const { t } = useLocalI18n({ en, ru })

const maxDate = new Date()

const presets: { id: PeriodPresetId }[] = [
  { id: 'last12' },
  { id: 'thisYear' },
  { id: 'last3' },
  { id: 'currentMonth' },
]

const activePreset = computed<PeriodPresetId | null>(() => matchPreset(props.modelValue))

/** Range as a `[Date, Date]` tuple for PrimeVue's range DatePicker. */
const dateRange = computed<Date[]>(() => {
  const from = monthKeyToDate(props.modelValue.from)
  const to = monthKeyToDate(props.modelValue.to)
  return [from, to].filter((d): d is Date => d !== null)
})

const applyPreset = (id: PeriodPresetId) => {
  emit('update:modelValue', presetRange(id))
}

const onChange = (value: Date | Date[] | (Date | null)[] | null | undefined) => {
  if (!Array.isArray(value)) return
  const [from, to] = value
  if (!(from instanceof Date)) return
  // While the user is mid-selection the second bound is still null — wait for
  // both ends before emitting so we never fire a one-ended range.
  if (!(to instanceof Date)) return
  emit('update:modelValue', normaliseRange(toMonthKey(from), toMonthKey(to)))
}
</script>

<style lang="scss" scoped>
.period-picker {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  flex-wrap: wrap;

  &__presets {
    display: flex;
    align-items: center;
    gap: 0.35rem;
    flex-wrap: wrap;
  }

  &__input {
    max-width: 16rem;
  }
}
</style>
