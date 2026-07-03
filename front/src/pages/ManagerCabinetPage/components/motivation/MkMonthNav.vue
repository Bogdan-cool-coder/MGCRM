<template>
  <div class="mk-month-nav">
    <Button
      text
      severity="secondary"
      icon="pi pi-chevron-left"
      :aria-label="t('motivation.card.prev_month')"
      @click="emit('step', -1)"
    />
    <Select
      :model-value="selectedValue"
      :options="options"
      option-label="label"
      option-value="value"
      class="mk-month-nav__select"
      @update:model-value="(v) => emit('select', v as string)"
    />
    <Button
      text
      severity="secondary"
      icon="pi pi-chevron-right"
      :disabled="isCurrentMonth"
      :aria-label="t('motivation.card.next_month')"
      @click="emit('step', 1)"
    />
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Select from 'primevue/select'
import type { MonthOption } from '../../composables/useMotivationTab'

defineProps<{
  options: MonthOption[]
  selectedValue: string
  isCurrentMonth: boolean
}>()

const emit = defineEmits<{
  step: [delta: number]
  select: [value: string]
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.mk-month-nav {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.mk-month-nav__select {
  min-width: 160px;
}
</style>
