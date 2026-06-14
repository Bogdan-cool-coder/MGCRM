<template>
  <div class="row g-3 align-items-end mb-3">
    <div class="col-auto">
      <Select
        v-model="localFilters.status"
        :options="statusOptions"
        option-label="label"
        option-value="value"
        :placeholder="t('onboarding.hrProgress.filter.status')"
        style="min-width: 160px"
        @change="emit('change')"
      />
    </div>
    <div class="col-auto">
      <Button
        :label="t('onboarding.hrProgress.filter.reset')"
        severity="secondary"
        outlined
        icon="pi pi-filter-slash"
        @click="emit('reset')"
      />
    </div>
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
