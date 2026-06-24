<template>
  <Card class="hr-chart-card h-100">
    <template #title>{{ t('onboarding.hrProgress.charts.topCourses') }}</template>
    <template #content>
      <template v-if="loading">
        <Skeleton height="260px" />
      </template>
      <template v-else>
        <VChart
          theme="macro-crm"
          :option="barOption"
          autoresize
          style="height: 260px"
        />
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import VChart from 'vue-echarts'
import type { EChartsOption } from 'echarts'
import { useThemeStore } from '@/stores/theme'
import type { HrProgressSummary } from '@/api/onboardingAdmin'

const props = defineProps<{
  summary: HrProgressSummary | null
  loading: boolean
}>()

const { t } = useI18n()
const themeStore = useThemeStore()
const isDark = computed(() => themeStore.theme === 'dark')

/**
 * Resolve a CSS custom property value from the document root.
 * #14 fix: replaces hardcoded hex literals with design-system tokens.
 */
function cssVar(name: string, fallback: string): string {
  if (typeof document === 'undefined') return fallback
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback
}

const barOption = computed<EChartsOption>(() => {
  const chart = props.summary?.top_courses_chart
  // Backend returns labels + datasets[0].data — build ECharts horizontal bar
  const labels = chart?.labels ?? []
  const values = chart?.datasets?.[0]?.data ?? []
  // Reverse so highest value is at top
  const reversedLabels = [...labels].reverse()
  const reversedValues = [...values].reverse()

  // #14 fix: resolve bar fill and label colour from design-system tokens.
  // Primary brand colour (dark navy) for bar; muted text for value labels.
  const barColor = cssVar('--p-primary-color', '#172747')
  const labelColor = isDark.value
    ? cssVar('--p-text-muted-color', '#9E9E9E')
    : cssVar('--p-text-muted-color', '#7E7F82')

  return {
    tooltip: {
      trigger: 'axis',
      axisPointer: { type: 'shadow' },
      formatter: (params: unknown) => {
        const p = (params as { name: string; value: number }[] | undefined)?.[0]
        if (!p) return ''
        return `${p.name}: ${p.value}`
      },
    },
    grid: { left: 16, right: 40, top: 8, bottom: 8, containLabel: true },
    xAxis: {
      type: 'value',
      axisLabel: { formatter: '{value}' },
    },
    yAxis: {
      type: 'category',
      data: reversedLabels,
      axisLabel: { fontSize: 11, overflow: 'truncate', width: 120 },
    },
    series: [
      {
        type: 'bar',
        data: reversedValues,
        itemStyle: { borderRadius: [0, 6, 6, 0], color: barColor },
        barMaxWidth: 28,
        label: {
          show: true,
          position: 'right',
          formatter: '{c}',
          fontSize: 11,
          color: labelColor,
        },
      },
    ],
  }
})
</script>

<style lang="scss" scoped>
.hr-chart-card {
  :deep(.p-card-title) {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
  }
}
</style>
