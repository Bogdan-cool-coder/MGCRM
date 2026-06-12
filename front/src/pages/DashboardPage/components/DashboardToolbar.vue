<template>
  <Card class="dashboard-toolbar mb-4">
    <template #content>
      <div class="d-flex flex-wrap align-items-center gap-3">
        <Select
          :model-value="filters.period"
          :options="periodOptions"
          option-label="label"
          option-value="value"
          class="dashboard-toolbar__select"
          @update:model-value="(v: DashboardPeriod) => emit('update:period', v)"
        />
        <Select
          :model-value="filters.pipeline_id"
          :options="pipelines"
          option-label="name"
          option-value="id"
          :loading="pipelinesLoading"
          show-clear
          :placeholder="t('dashboard.filters.allPipelines')"
          class="dashboard-toolbar__select"
          @update:model-value="(v: number | null) => emit('update:pipelineId', v)"
        />
        <Select
          v-if="canSeeAllManagers"
          :model-value="filters.manager_id"
          :options="managers"
          option-label="full_name"
          option-value="id"
          filter
          show-clear
          :placeholder="t('dashboard.filters.allManagers')"
          class="dashboard-toolbar__select"
          @update:model-value="(v: number | null) => emit('update:managerId', v)"
        />
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Select from 'primevue/select'
import type { DashboardFilters, DashboardPeriod } from '@/entities/salesDashboard'
import type { PipelineDto } from '@/entities/sales'
import type { UserOptionDto } from '@/api/users'

const { t } = useI18n()

defineProps<{
  filters: DashboardFilters
  pipelines: PipelineDto[]
  managers: UserOptionDto[]
  pipelinesLoading: boolean
  canSeeAllManagers: boolean
}>()

const emit = defineEmits<{
  'update:period': [value: DashboardPeriod]
  'update:pipelineId': [value: number | null]
  'update:managerId': [value: number | null]
}>()

const periodOptions = [
  { value: 'current_month' as DashboardPeriod, label: t('dashboard.periods.currentMonth') },
  { value: 'last_month' as DashboardPeriod, label: t('dashboard.periods.lastMonth') },
  { value: 'current_quarter' as DashboardPeriod, label: t('dashboard.periods.currentQuarter') },
  { value: 'current_year' as DashboardPeriod, label: t('dashboard.periods.currentYear') },
]
</script>

<style lang="scss" scoped>
.dashboard-toolbar {
  :deep(.p-card-body) {
    padding: $space-3;
  }
}

.dashboard-toolbar__select {
  min-width: 160px;
}
</style>
