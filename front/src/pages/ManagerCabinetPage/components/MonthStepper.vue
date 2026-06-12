<template>
  <Card class="month-stepper">
    <template #content>
      <div class="d-flex flex-wrap gap-2">
        <Button
          v-for="item in months"
          :key="item.value"
          :label="item.displayLabel"
          :severity="period === item.value ? 'primary' : 'secondary'"
          :outlined="period !== item.value"
          size="small"
          @click="emit('update:period', item.value)"
        />
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Card from 'primevue/card'
import Button from 'primevue/button'
import type { KpiPeriod } from '@/entities/managerCabinet'

defineProps<{
  period: KpiPeriod
}>()

const emit = defineEmits<{
  'update:period': [KpiPeriod]
}>()

interface MonthItem {
  value: KpiPeriod
  displayLabel: string
}

const months = computed<MonthItem[]>(() =>
  Array.from({ length: 7 }, (_, i) => {
    const d = new Date()
    d.setDate(1)
    d.setMonth(d.getMonth() - (6 - i))
    const isCurrent = i === 6
    const value: KpiPeriod = isCurrent
      ? 'current_month'
      : `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`
    const shortMonth = d.toLocaleString('ru', { month: 'short' })
    // Show year only for the first button if it's in a prior year
    const showYear = i === 0 && d.getFullYear() < new Date().getFullYear()
    const displayLabel = showYear ? `${shortMonth} '${d.getFullYear()}` : shortMonth
    return { value, displayLabel }
  }),
)
</script>

<style lang="scss" scoped>
.month-stepper {
  :deep(.p-card-body) {
    padding: $space-3 $space-4;
  }
  :deep(.p-card-content) {
    padding: 0;
  }
}
</style>
