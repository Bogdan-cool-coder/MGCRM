<template>
  <Card class="widget-card h-100">
    <template #title>
      <div class="funnel-header">
        <span class="funnel-header__title">{{ t('dashboard.funnel.title') }}</span>
        <span
          v-if="!loading && overallConversion !== null"
          class="funnel-header__conversion"
          :title="t('dashboard.funnel.overallConversionHint')"
        >
          {{ t('dashboard.funnel.overallConversion') }}
          <strong class="funnel-header__conversion-value">{{ overallConversion }}%</strong>
        </span>
      </div>
    </template>
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
                  :value="t('dashboard.funnel.won')"
                  class="funnel-stage-tag"
                />
                <Tag
                  v-else-if="row.is_lost"
                  severity="danger"
                  :value="t('dashboard.funnel.lost')"
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
              <template v-if="row.transition_to_next_pct != null">
                <div class="funnel-progress">
                  <div class="funnel-progress__track">
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
              <span v-else class="funnel-progress__null">—</span>
            </template>
          </Column>
        </DataTable>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import type { FunnelData, FunnelStage } from '@/entities/salesDashboard'

const { t } = useI18n()

const props = defineProps<{
  funnel: FunnelData | null
  loading: boolean
}>()

/**
 * Overall conversion = honest win-rate = won ÷ ALL deals in the funnel for the
 * period. Using the first-stage-by-sort_order count as the denominator is wrong
 * for funnels with multiple parallel entry stages (e.g. «Неразобранное» +
 * «партнёрский канал»), which produced >100% (BUG-FUNNEL-CONVERSION-OVER-100).
 *
 * Denominator preference (ТЗ):
 *   (a) a funnel-level total field if the response carries one;
 *   (b) otherwise the sum of every stage row's count (won/lost rows included).
 * Result is clamped to 0–100 so a data anomaly can never render an impossible %.
 * Returns null when there are no deals at all (shows «—»).
 */
const overallConversion = computed<number | null>(() => {
  const f = props.funnel
  if (!f || f.stages.length === 0) return null

  // (a) prefer an explicit funnel-wide total if the backend ever adds one.
  const explicitTotal = (f as { total?: number; total_deals?: number }).total
    ?? (f as { total?: number; total_deals?: number }).total_deals
  // (b) fall back to the sum of all row counts (every stage, incl. won/lost rows).
  const summedTotal = f.stages.reduce((acc, s) => acc + (s.count ?? 0), 0)
  const total = explicitTotal != null && explicitTotal > 0 ? explicitTotal : summedTotal

  if (total <= 0) return null
  const pct = (f.total_won / total) * 100
  return Math.round(Math.min(100, Math.max(0, pct)))
})

const progressClass = (row: FunnelStage): string => {
  if (row.is_won) return 'funnel-progress__bar--won'
  if (row.is_lost) return 'funnel-progress__bar--lost'
  if (row.transition_to_next_pct != null && row.transition_to_next_pct >= 50) return 'funnel-progress__bar--good'
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

.funnel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  flex-wrap: wrap;
}

.funnel-header__title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-800;
}

.funnel-header__conversion {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-xs;
  font-weight: $font-weight-normal;
  color: $surface-600;
}

.funnel-header__conversion-value {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $status-success-text;
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
  font-size: $font-size-icon-xl;
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
  border-radius: $radius-circle;
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
  font-size: $font-size-3xs; // snap from 10px
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
  border-radius: $radius-xs;
  overflow: hidden;
}

.funnel-progress__bar {
  height: 100%;
  border-radius: $radius-xs;
  transition: width 0.4s ease;

  // Brand accent bar reads on both themes from the reactive PrimeVue token
  // (navy #172747 light → #4C7DF0 dark); static $primary-color dissolved on navy.
  &--won { background-color: var(--p-primary-color); }
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

.funnel-progress__null {
  font-size: $font-size-sm;
  color: $surface-400;
}
</style>
