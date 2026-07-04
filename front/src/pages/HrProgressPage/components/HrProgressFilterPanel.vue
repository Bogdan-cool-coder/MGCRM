<template>
  <div class="hr-progress-filter">
    <Select
      v-model="localFilters.status"
      :options="statusOptions"
      option-label="label"
      option-value="value"
      :placeholder="t('onboarding.hrProgress.filter.status')"
      class="hr-progress-filter__select"
      @change="emit('change')"
    />
    <Button
      v-if="localFilters.status"
      :label="t('onboarding.hrProgress.filter.reset')"
      severity="secondary"
      outlined
      icon="pi pi-filter-slash"
      class="hr-progress-filter__reset"
      @click="emit('reset')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import Button from 'primevue/button'

const props = defineProps<{
  filters: { status: string }
}>()

const emit = defineEmits<{
  change: []
  reset: []
}>()

const { t } = useI18n()

const localFilters = computed(() => props.filters)

const statusOptions = computed(() => [
  { label: t('onboarding.hrProgress.filter.all'), value: '' },
  { label: t('onboarding.assignments.statuses.pending'), value: 'pending' },
  { label: t('onboarding.assignments.statuses.in_progress'), value: 'in_progress' },
  { label: t('onboarding.assignments.statuses.completed'), value: 'completed' },
  { label: t('onboarding.assignments.statuses.overdue'), value: 'overdue' },
])
</script>

<style lang="scss" scoped>
.hr-progress-filter {
  display: inline-flex;
  align-items: center;
  gap: $space-2;

  &__select {
    min-width: 160px;
    height: 38px;
  }

  &__reset {
    height: 38px;
  }
}
</style>
