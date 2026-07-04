<template>
  <div class="conv-pairs">
    <DataTable
      :value="pairs"
      size="small"
      :striped-rows="true"
      row-hover
      scrollable
      scroll-height="flex"
      class="conv-pairs__table"
    >
      <!-- Pair (frozen left): name + numerator/denominator descriptor -->
      <Column
        :header="t('dashboard.plans.conv_pair')"
        frozen
        align-frozen="left"
        class="conv-pairs__col-pair"
      >
        <template #body="{ data }">
          <div class="conv-pairs__pair">
            <span
              class="conv-pairs__pair-name"
              :title="(data as ConversionPair).name"
            >{{ (data as ConversionPair).name }}</span>
            <span
              class="conv-pairs__pair-formula"
              :title="formula(data as ConversionPair)"
            >{{ formula(data as ConversionPair) }}</span>
          </div>
        </template>
      </Column>

      <!-- Months × 12 + annual: fact% + scored PctTag -->
      <Column
        v-for="col in columns"
        :key="col.key"
        :header="col.label"
        class="conv-pairs__col-month"
      >
        <template #body="{ data }">
          <div
            v-if="cellOf(data as ConversionPair, col.key)"
            v-tooltip.top="cellTooltip(data as ConversionPair, col.key)"
            class="conv-pairs__cell"
          >
            <span class="conv-pairs__fact">{{ factLabel(data as ConversionPair, col.key) }}</span>
            <PctTag
              :value="scoreOf(data as ConversionPair, col.key)"
              :badge="badgeOf(data as ConversionPair, col.key)"
              size="sm"
            />
          </div>
          <span v-else class="conv-pairs__na">—</span>
        </template>
      </Column>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import PctTag from '@/components/shared/PctTag.vue'
import { formatMkPct } from '@/utils/motivation'
import type { PctBadge } from '@/entities/motivation'
import type {
  ConversionPair,
  ConversionCell,
  ConversionSide,
} from '@/entities/reports'
import type { PlanMatrixColumn } from '@/entities/planTargets'

const props = defineProps<{
  pairs: ConversionPair[]
}>()

const { t } = useI18n()

/** Column set is identical across pairs — take it from the first pair. */
const columns = computed<PlanMatrixColumn[]>(() => props.pairs[0]?.columns ?? [])

const cellOf = (pair: ConversionPair, key: string): ConversionCell | undefined => pair.cells[key]

const scoreOf = (pair: ConversionPair, key: string): number | null =>
  cellOf(pair, key)?.pct ?? cellOf(pair, key)?.fact_pct ?? null

const badgeOf = (pair: ConversionPair, key: string): PctBadge =>
  cellOf(pair, key)?.badge ?? 'none'

const factLabel = (pair: ConversionPair, key: string): string =>
  formatMkPct(cellOf(pair, key)?.fact_pct ?? null)

/** Human-readable side descriptor, e.g. «Встреча», «Сделка: won», «Этап #7». */
const sideLabel = (side: ConversionSide): string => {
  if (side.type === 'task' && side.kind) {
    return t(`dashboard.plans.side_task`, { kind: side.kind })
  }
  if (side.type === 'deal') {
    return t('dashboard.plans.side_deal', { status: side.status ?? '' })
  }
  if (side.type === 'stage' && side.stage_id != null) {
    return t('dashboard.plans.side_stage', { id: side.stage_id })
  }
  return side.type
}

const formula = (pair: ConversionPair): string =>
  `${sideLabel(pair.numerator)} / ${sideLabel(pair.denominator)}`

const cellTooltip = (pair: ConversionPair, key: string): string => {
  const cell = cellOf(pair, key)
  if (!cell) return ''
  const parts: string[] = []
  if (cell.plan_pct != null) parts.push(`${t('dashboard.plans.conv_plan')}: ${formatMkPct(cell.plan_pct)}`)
  parts.push(`${t('dashboard.plans.conv_fact')}: ${formatMkPct(cell.fact_pct)}`)
  parts.push(`${cell.num_count} / ${cell.den_count}`)
  return parts.join(' · ')
}
</script>

<style lang="scss" scoped>
.conv-pairs__table {
  :deep(.p-datatable-tbody > tr > td) {
    padding: $space-2 $space-3;
    vertical-align: middle;
  }

  :deep(.p-datatable-thead > tr > th) {
    white-space: nowrap;
  }

  // Frozen «Пара» column opaque so scrolled month columns don't bleed through
  // (reactive table surface — consistent across both themes).
  :deep(.p-datatable-frozen-column) {
    background: var(--p-datatable-header-cell-background, var(--p-card-background));
  }
}

.conv-pairs__pair {
  display: flex;
  flex-direction: column;
  gap: 2px;
  min-width: 180px;
  // Cap so a long pair name doesn't bloat the frozen column (audit L1).
  max-width: 240px;
}

.conv-pairs__pair-name {
  font-weight: $font-weight-medium;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.conv-pairs__pair-formula {
  font-size: $font-size-xs;
  color: $surface-500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.conv-pairs__cell {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  min-width: 96px;
}

.conv-pairs__fact {
  font-size: $font-size-sm;
  font-variant-numeric: tabular-nums;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.conv-pairs__na {
  display: block;
  text-align: center;
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-400);
  }
}
</style>
