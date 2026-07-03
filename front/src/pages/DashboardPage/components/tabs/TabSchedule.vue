<template>
  <div class="tab-schedule">
    <!-- Endpoint not yet deployed (parallel backend) -->
    <Message
      v-if="endpointMissing && !loading"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="tab-schedule__notice"
    >
      {{ t('dashboard.schedule.endpoint_missing') }}
    </Message>

    <!-- Multi-currency warning -->
    <Message
      v-else-if="schedule?.meta?.multi_currency_warning"
      severity="warn"
      :closable="false"
      icon="pi pi-info-circle"
      class="tab-schedule__notice"
    >
      {{ t('dashboard.multiCurrencyWarning') }}
    </Message>

    <!-- Loading skeleton: KPI strip + chart + calendar grid -->
    <div v-if="loading" class="tab-schedule__skeleton">
      <div class="tab-schedule__skeleton-kpis">
        <Skeleton v-for="n in 4" :key="`k${n}`" height="4.5rem" />
      </div>
      <Skeleton height="18rem" class="mt-4" />
      <Skeleton height="16rem" class="mt-4" />
    </div>

    <template v-else-if="schedule">
      <!-- KPI summary strip -->
      <div class="tab-schedule__kpis">
        <div v-for="kpi in kpis" :key="kpi.key" class="tab-schedule__kpi">
          <span class="tab-schedule__kpi-label">{{ kpi.label }}</span>
          <span class="tab-schedule__kpi-value" :class="kpi.valueClass">{{ kpi.value }}</span>
        </div>
      </div>

      <!-- Cumulative chart -->
      <Card class="tab-schedule__card">
        <template #title>
          <div class="tab-schedule__card-head">
            <span>{{ t('dashboard.schedule.title') }} · {{ periodLabel }}</span>
            <ScheduleLegend />
          </div>
        </template>
        <template #content>
          <VChart
            theme="macro-crm"
            :option="chartOption"
            autoresize
            class="tab-schedule__chart"
          />
        </template>
      </Card>

      <!-- Day calendar -->
      <Card class="tab-schedule__card">
        <template #title>{{ t('dashboard.schedule.calendar_title') }}</template>
        <template #content>
          <NpCalendar
            :days="schedule.days"
            :year="year"
            :month="month"
            :base-currency="baseCurrency"
          />
        </template>
      </Card>
    </template>

    <!-- No data (endpoint-missing already shows its own hint above) -->
    <div v-else-if="!endpointMissing" class="tab-schedule__empty">
      <i class="pi pi-calendar tab-schedule__empty-icon" aria-hidden="true" />
      <h3 class="tab-schedule__empty-title">{{ t('dashboard.schedule.empty') }}</h3>
      <p class="tab-schedule__empty-hint">{{ t('dashboard.schedule.empty_hint') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import VChart from 'vue-echarts'
import type { EChartsOption } from 'echarts'
import NpCalendar from '../schedule/NpCalendar.vue'
import ScheduleLegend from '../schedule/ScheduleLegend.vue'
import { useScheduleTab } from '../../composables/useScheduleTab'
import { useThemeStore } from '@/stores/theme'
import { formatMkMoney, formatMkPct } from '@/utils/motivation'
import { formatMoney, formatFullNumber } from '@/utils/chartFormatters'
import { fromKopecks } from '@/utils/currency'

const props = defineProps<{
  year: number
  month: number
  pipelineId: number | null
}>()

const { t, locale } = useI18n()
const themeStore = useThemeStore()
const isDark = computed(() => themeStore.theme === 'dark')

const { schedule, loading, endpointMissing } = useScheduleTab({
  year: () => props.year,
  month: () => props.month,
  pipelineId: () => props.pipelineId,
})

const baseCurrency = computed<string>(() => schedule.value?.meta.base_currency ?? 'RUB')

const periodLabel = computed<string>(() => schedule.value?.meta.period.label ?? '')

// ─── KPI summary (план месяца / факт / % / дожим) ──────────────────────────────
const planTotal = computed<number>(() => schedule.value?.plan_total_base_kopecks ?? 0)

const factTotal = computed<number>(() => {
  const days = schedule.value?.days ?? []
  const last = days[days.length - 1]
  return last?.cumulative_fact_base_kopecks ?? 0
})

const squeezeTotal = computed<number>(() =>
  (schedule.value?.days ?? []).reduce((sum, d) => sum + d.squeeze_base_kopecks, 0),
)

const factPct = computed<number | null>(() => {
  if (planTotal.value <= 0) return null
  return Math.round((factTotal.value / planTotal.value) * 100)
})

const kpis = computed(() => [
  {
    key: 'plan',
    label: t('dashboard.schedule.kpi_plan'),
    value: formatMkMoney(planTotal.value, baseCurrency.value),
    valueClass: '',
  },
  {
    key: 'fact',
    label: t('dashboard.schedule.kpi_fact'),
    value: formatMkMoney(factTotal.value, baseCurrency.value),
    valueClass: 'tab-schedule__kpi-value--fact',
  },
  {
    key: 'pct',
    label: t('dashboard.schedule.kpi_pct'),
    value: formatMkPct(factPct.value),
    valueClass: '',
  },
  {
    key: 'squeeze',
    label: t('dashboard.schedule.kpi_squeeze'),
    value: formatMkMoney(squeezeTotal.value, baseCurrency.value),
    valueClass: 'tab-schedule__kpi-value--squeeze',
  },
])

// ─── ECharts cumulative (plan line / fact area / squeeze line) ─────────────────
const cssVar = (name: string, fallback: string): string => {
  if (typeof document === 'undefined') return fallback
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback
}

const chartOption = computed<EChartsOption>(() => {
  const days = schedule.value?.days ?? []
  const cur = baseCurrency.value
  const loc = locale.value

  // Semantic series colours from --p-* (dark-aware): plan=info, fact=success, squeeze=danger.
  const planColor = isDark.value ? cssVar('--p-blue-400', '#8DD9FF') : cssVar('--p-blue-500', '#378ADD')
  const factColor = isDark.value ? cssVar('--p-green-400', '#7BE07F') : cssVar('--p-green-500', '#1D9E75')
  const squeezeColor = isDark.value ? cssVar('--p-red-400', '#FF6B57') : cssVar('--p-red-500', '#FF5A44')

  return {
    grid: { top: 24, right: 16, bottom: 32, left: 56 },
    tooltip: {
      trigger: 'axis',
      valueFormatter: (v) =>
        typeof v === 'number' ? formatFullNumber(fromKopecks(v), loc, cur) : String(v),
    },
    legend: { show: false },
    xAxis: {
      type: 'category',
      boundaryGap: false,
      data: days.map((d) => String(d.day)),
    },
    yAxis: {
      type: 'value',
      axisLabel: {
        formatter: (v: number) => formatMoney(v * 100, loc, cur),
      },
    },
    series: [
      {
        name: t('dashboard.schedule.legend_plan'),
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: days.map((d) => fromKopecks(d.cumulative_plan_base_kopecks)),
        lineStyle: { color: planColor, width: 2.5, type: 'dashed' },
        itemStyle: { color: planColor },
      },
      {
        name: t('dashboard.schedule.legend_fact'),
        type: 'line',
        smooth: true,
        showSymbol: false,
        data: days.map((d) => fromKopecks(d.cumulative_fact_base_kopecks)),
        lineStyle: { color: factColor, width: 2.5 },
        itemStyle: { color: factColor },
        areaStyle: { color: factColor, opacity: 0.12 },
      },
      {
        name: t('dashboard.schedule.legend_slippage'),
        type: 'line',
        smooth: false,
        showSymbol: false,
        data: days.map((d) => fromKopecks(d.squeeze_base_kopecks)),
        lineStyle: { color: squeezeColor, width: 2, type: 'dotted' },
        itemStyle: { color: squeezeColor },
      },
    ],
  }
})
</script>

<style lang="scss" scoped>
.tab-schedule {
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.tab-schedule__notice {
  margin: 0;
}

.tab-schedule__skeleton-kpis {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: $space-3;
}

// ── KPI strip ────────────────────────────────────────────────────────────────
.tab-schedule__kpis {
  display: grid;
  grid-template-columns: repeat(4, 1fr);
  gap: $space-3;
}

.tab-schedule__kpi {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  padding: $space-3 $space-4;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  background: $surface-card;
}

.tab-schedule__kpi-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-500;
}

.tab-schedule__kpi-value {
  font-size: $font-size-lg;
  font-weight: $font-weight-bold;
  font-variant-numeric: tabular-nums;
  color: $surface-900;

  &--fact {
    color: var(--p-green-600);
  }

  &--squeeze {
    color: var(--p-red-500);
  }

  .app-dark &--fact {
    color: var(--p-green-400);
  }

  .app-dark &--squeeze {
    color: var(--p-red-400);
  }
}

// ── Cards ────────────────────────────────────────────────────────────────────
.tab-schedule__card {
  background: $surface-card;

  :deep(.p-card-title) {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
  }
}

.tab-schedule__card-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: $space-3;
}

.tab-schedule__chart {
  height: 300px;
  width: 100%;
}

// ── Empty state ──────────────────────────────────────────────────────────────
.tab-schedule__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 260px;
  padding: $space-8 $space-6;
  text-align: center;
}

.tab-schedule__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;
}

.tab-schedule__empty-title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;
}

.tab-schedule__empty-hint {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-500;
}

// ── Adaptive (≤1280) ─────────────────────────────────────────────────────────
@media (max-width: 1280px) {
  .tab-schedule__kpis,
  .tab-schedule__skeleton-kpis {
    grid-template-columns: repeat(2, 1fr);
  }
}
</style>
