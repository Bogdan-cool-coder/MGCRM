<template>
  <div class="assignments-filter-panel">
    <Select
      v-model="localFilters.status"
      :options="statusOptions"
      option-label="label"
      option-value="value"
      :placeholder="t('onboarding.assignments.filter.status')"
      class="assignments-filter-panel__select"
      @change="emit('change')"
    />
    <Button
      v-if="localFilters.status"
      :label="t('onboarding.assignments.filter.reset')"
      severity="secondary"
      outlined
      icon="pi pi-filter-slash"
      class="assignments-filter-panel__reset"
      @click="emit('reset')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import Button from 'primevue/button'
import type { AssignmentListParams } from '@/entities/assignment'

const props = defineProps<{
  filters: AssignmentListParams
}>()

const emit = defineEmits<{
  change: []
  reset: []
}>()

const { t } = useI18n()

const localFilters = computed(() => props.filters)

const statusOptions = computed(() => [
  { label: t('onboarding.assignments.filter.all'), value: '' },
  { label: t('onboarding.assignments.statuses.pending'), value: 'pending' },
  { label: t('onboarding.assignments.statuses.in_progress'), value: 'in_progress' },
  { label: t('onboarding.assignments.statuses.completed'), value: 'completed' },
  { label: t('onboarding.assignments.statuses.overdue'), value: 'overdue' },
  { label: t('onboarding.assignments.statuses.archived'), value: 'archived' },
])
</script>

<style lang="scss" scoped>
.assignments-filter-panel {
  display: inline-flex;
  align-items: center;
  gap: $space-2;

  &__select {
    min-width: 180px;
    height: 38px;
  }

  &__reset {
    height: 38px;
  }
}
</style>
