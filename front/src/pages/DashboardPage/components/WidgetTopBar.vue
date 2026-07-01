<template>
  <Card class="widget-card h-100">
    <template #title>
      <div class="d-flex align-items-center justify-content-between gap-3 flex-wrap">
        <span>{{ t('dashboard.topBar.title') }}</span>
        <SelectButton
          v-model="activeTab"
          :options="tabOptions"
          option-label="label"
          option-value="value"
          size="small"
        />
      </div>
    </template>
    <template #content>
      <!-- Loading skeleton -->
      <template v-if="loading">
        <Skeleton height="320px" />
      </template>

      <!-- Empty state -->
      <template v-else-if="isEmpty">
        <div class="widget-empty">
          <i class="pi pi-chart-bar widget-empty__icon" />
          <p class="widget-empty__text">{{ t('dashboard.empty.noTopData') }}</p>
        </div>
      </template>

      <!-- Chart — :key forces full remount on theme switch so ECharts
           re-registers the registered theme colours (axes, grid, tooltip). -->
      <template v-else>
        <VChart
          :key="themeStore.theme"
          theme="macro-crm"
          :option="barOption"
          autoresize
          style="height: 320px"
        />
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import SelectButton from 'primevue/selectbutton'
import VChart from 'vue-echarts'
import type { EChartsOption } from 'echarts'
import type { TopChartData } from '@/entities/salesDashboard'
import { formatAxisValue, formatFullNumber } from '@/utils/chartFormatters'
import { macroCrmBarColor, macroCrmMutedText } from '@/plugins/echarts'
import { useThemeStore } from '@/stores/theme'

const { t, locale } = useI18n()
const themeStore = useThemeStore()

const props = defineProps<{
  topProducts: TopChartData | null
  topManagers: TopChartData | null
  baseCurrency: string
  loading: boolean
}>()

const activeTab = ref<'products' | 'managers'>('products')

const tabOptions = computed(() => [
  { label: t('dashboard.topBar.products'), value: 'products' },
  { label: t('dashboard.topBar.managers'), value: 'managers' },
])

const currentData = computed<TopChartData | null>(() =>
  activeTab.value === 'products' ? props.topProducts : props.topManagers,
)

const isEmpty = computed(
  () =>
    !currentData.value ||
    currentData.value.labels.length === 0 ||
    (currentData.value.datasets[0]?.data ?? []).length === 0,
)

const barOption = computed<EChartsOption>(() => {
  const src = currentData.value
  if (!src) return {}

  const labels = src.labels
  const values = src.datasets[0]?.data ?? []

  // ECharts draws category axis bottom-to-top — reverse so top item appears first
  const reversedLabels = [...labels].reverse()
  const reversedValues = [...values].reverse()

  return {
    animation: true,
    animationDuration: 600,
    animationEasing: 'cubicOut',
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      confine: true,
      valueFormatter: (v: unknown) =>
        formatFullNumber((v as number) / 100, locale.value, props.baseCurrency),
    },
    grid: { left: 16, right: 24, top: 8, bottom: 8, containLabel: true },
    xAxis: {
      type: 'value',
      axisLabel: {
        formatter: (v: number) => formatAxisValue(v / 100, locale.value, props.baseCurrency),
      },
    },
    yAxis: {
      type: 'category',
      data: reversedLabels,
      axisLabel: {
        fontSize: 12,
        overflow: 'truncate',
        width: 120,
      },
    },
    series: [
      {
        type: 'bar',
        data: reversedValues,
        itemStyle: { borderRadius: [0, 6, 6, 0], color: macroCrmBarColor() },
        barMaxWidth: 32,
        label: {
          show: true,
          position: 'right',
          formatter: (p: { value: unknown }) =>
            formatAxisValue((p.value as number) / 100, locale.value, props.baseCurrency),
          fontSize: 11,
          color: macroCrmMutedText(themeStore.theme === 'dark'),
        },
      },
    ],
  }
})
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
  min-height: 320px;
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
</style>
