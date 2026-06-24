<template>
  <Card class="hr-chart-card h-100">
    <template #title>{{ t('onboarding.hrProgress.charts.byStatus') }}</template>
    <template #content>
      <template v-if="loading">
        <Skeleton height="260px" />
      </template>
      <template v-else>
        <VChart
          theme="macro-crm"
          :option="pieOption"
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
 * Falls back to the provided default when running outside a browser (SSR/test).
 * #14 fix: replaces hardcoded hex literals with design-system tokens.
 */
function cssVar(name: string, fallback: string): string {
  if (typeof document === 'undefined') return fallback
  return getComputedStyle(document.documentElement).getPropertyValue(name).trim() || fallback
}

const pieOption = computed<EChartsOption>(() => {
  // Legend text colour follows the active theme.
  const legendTextColor = isDark.value
    ? cssVar('--p-text-color', '#ffffff')
    : cssVar('--p-text-color', '#333333')

  return {
    tooltip: { trigger: 'item', formatter: '{b}: {c} ({d}%)' },
    legend: {
      orient: 'vertical',
      right: 10,
      top: 'center',
      textStyle: { color: legendTextColor },
    },
    series: [
      {
        type: 'pie',
        radius: ['40%', '70%'],
        center: ['35%', '50%'],
        data: [
          { name: t('onboarding.assignments.statuses.completed'), value: props.summary?.completed ?? 0 },
          { name: t('onboarding.assignments.statuses.in_progress'), value: props.summary?.in_progress ?? 0 },
          { name: t('onboarding.assignments.statuses.pending'), value: props.summary?.pending ?? 0 },
          { name: t('onboarding.assignments.statuses.overdue'), value: props.summary?.overdue ?? 0 },
        ],
        // Palette tokens: success-green / info-blue / neutral-grey / danger-red
        color: [
          cssVar('--p-green-300', '#A7EFAA'),
          cssVar('--p-blue-300', '#8DD9FF'),
          cssVar('--p-surface-400', '#B8B9BB'),
          cssVar('--p-red-400', '#FF5A44'),
        ],
        label: { show: false },
        emphasis: { label: { show: true, fontSize: 14, fontWeight: 'bold' } },
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
