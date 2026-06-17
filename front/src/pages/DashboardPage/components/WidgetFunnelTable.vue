<template>
  <Card class="widget-card h-100">
    <template #title>{{ t('dashboard.funnel.title') }}</template>
    <template #content>
      <!-- Loading skeleton -->
      <template v-if="loading">
        <Skeleton height="200px" />
      </template>

      <!-- Empty state -->
      <template v-else-if="!funnel || funnel.stages.length === 0">
        <div class="widget-empty">
          <i class="pi pi-funnel widget-empty__icon" />
          <p class="widget-empty__text">{{ t('dashboard.empty.noFunnel') }}</p>
        </div>
      </template>

      <!-- Data table -->
      <template v-else>
        <DataTable
          :value="funnel.stages"
          size="small"
          :striped-rows="true"
          row-hover
          class="funnel-table"
        >
          <Column :header="t('dashboard.funnel.stage')" style="min-width: 140px">
            <template #body="{ data: row }">
              <div class="funnel-stage-cell">
                <span
                  class="funnel-stage-dot"
                  :class="{
                    'funnel-stage-dot--won': row.is_won,
                    'funnel-stage-dot--lost': row.is_lost,
                  }"
                />
                <span class="funnel-stage-name">{{ row.stage_name }}</span>
                <Tag
                  v-if="row.is_won"
                  severity="success"
                  value="WON"
                  class="funnel-stage-tag"
                />
                <Tag
                  v-else-if="row.is_lost"
                  severity="danger"
                  value="LOST"
                  class="funnel-stage-tag"
                />
              </div>
            </template>
          </Column>

          <Column
            field="count"
            :header="t('dashboard.funnel.count')"
            style="width: 80px; text-align: right"
          />

          <Column :header="t('dashboard.funnel.avgDays')" style="width: 100px; text-align: right">
            <template #body="{ data: row }">
              {{ row.avg_days_in_stage > 0 ? row.avg_days_in_stage.toFixed(1) : '—' }}
            </template>
          </Column>

          <Column :header="t('dashboard.funnel.transition')" style="min-width: 160px">
            <template #body="{ data: row }">
              <div class="funnel-progress">
                <div
                  class="funnel-progress__track"
                >
                  <div
                    class="funnel-progress__bar"
                    :class="progressClass(row)"
                    :style="{ width: `${row.transition_to_next_pct}%` }"
                  />
                </div>
                <span class="funnel-progress__pct">
                  {{ row.transition_to_next_pct.toFixed(1) }}%
                </span>
              </div>
            </template>
          </Column>
        </DataTable>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import type { FunnelData, FunnelStage } from '@/entities/salesDashboard'

const { t } = useI18n()

defineProps<{
  funnel: FunnelData | null
  loading: boolean
}>()

const progressClass = (row: FunnelStage): string => {
  if (row.is_won) return 'funnel-progress__bar--won'
  if (row.is_lost) return 'funnel-progress__bar--lost'
  if (row.transition_to_next_pct >= 50) return 'funnel-progress__bar--good'
  return 'funnel-progress__bar--low'
}
</script>

<style lang="scss" scoped>
.widget-card {
  :deep(.p-card-title) {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: $surface-800;
  }
}

.widget-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 160px;
}

.widget-empty__icon {
  font-size: 2.5rem;
  color: $surface-400;
}

.widget-empty__text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.funnel-table {
  :deep(.p-datatable-table) {
    font-size: $font-size-sm;
  }
}

.funnel-stage-cell {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.funnel-stage-dot {
  width: 8px;
  height: 8px;
  border-radius: 50%;
  background-color: $surface-400;
  flex-shrink: 0;

  &--won { background-color: var(--p-green-500); }
  &--lost { background-color: var(--p-red-500); }
}

.funnel-stage-name {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.funnel-stage-tag {
  font-size: 10px;
  padding: 1px 5px;
  flex-shrink: 0;
}

.funnel-progress {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.funnel-progress__track {
  flex: 1;
  height: 6px;
  background-color: $surface-200;
  border-radius: 3px;
  overflow: hidden;
}

.funnel-progress__bar {
  height: 100%;
  border-radius: 3px;
  transition: width 0.4s ease;

  &--won { background-color: $primary-color; }
  &--lost { background-color: $status-danger-text; }
  &--good { background-color: $status-success-text; }
  &--low { background-color: $status-warning-text; }
}

.funnel-progress__pct {
  font-size: $font-size-xs;
  color: $surface-600;
  min-width: 40px;
  text-align: right;
}
</style>
