<template>
  <div class="widget-card" :class="{ 'widget-card--hidden': !visible }">
    <div class="widget-card__header">
      <span class="widget-card__title" :title="title">{{ title }}</span>

      <div class="widget-card__actions">
        <Button
          v-if="!visible"
          icon="pi pi-eye-slash"
          text
          rounded
          size="small"
          :aria-label="t('hidden')"
          :title="t('hidden')"
          tabindex="-1"
        />
        <Button
          v-if="editable"
          class="widget-card__menu-btn"
          icon="pi pi-ellipsis-v"
          text
          rounded
          size="small"
          :aria-label="t('menu')"
          @click="toggleMenu"
        />
      </div>
    </div>

    <div class="widget-card__body">
      <div v-if="isLoading" class="widget-card__state">
        <i class="pi pi-spin pi-spinner" aria-hidden="true" />
      </div>
      <div v-else-if="!hasData" class="widget-card__state widget-card__state--empty">
        <i class="pi pi-chart-bar" aria-hidden="true" />
        <span>{{ t('noData') }}</span>
      </div>
      <VChart
        v-else
        class="widget-card__chart"
        theme="vizion"
        :option="chartOption"
        autoresize
      />
    </div>

    <Menu ref="menuRef" :model="menuItems" :popup="true" append-to="body" />
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import type { MenuItem } from 'primevue/menuitem'
import VChart from 'vue-echarts'
import { graphic } from 'echarts/core'
import type { EChartsOption } from 'echarts'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useCompaniesStore } from '@/stores/companies'
import type { WidgetChartType, WidgetConfigDto, WidgetData } from '@/entities/widget'
import { VIZION_ECHARTS_PALETTE } from '@/plugins/echarts'
import {
  formatAxisValue,
  formatFullNumber,
  formatTemporalLabel,
  isMonetaryWidget,
  resolveSeriesLabel,
} from '@/utils/chartFormatters'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t, locale } = useLocalI18n({ en, ru })
const companiesStore = useCompaniesStore()

interface Props {
  title: string
  chartType: WidgetChartType
  data: WidgetData | null
  config?: WidgetConfigDto | null
  visible: boolean
  isLoading?: boolean
  editable?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  config: null,
  isLoading: false,
  editable: false,
})

const emit = defineEmits<{
  edit: []
  detach: []
  'toggle-visibility': [visible: boolean]
}>()

const menuRef = ref<InstanceType<typeof Menu> | null>(null)

const hasData = computed(
  () => !!props.data && props.data.labels.length > 0 && props.data.datasets.length > 0,
)

// Formatting context. Currency is the active-company code (narrow symbol),
// matching useFormatter's resolution; only applied when the widget is monetary.
const localeCode = computed(() => locale.value)
const isMonetary = computed(() => isMonetaryWidget(props.config))
const currencyCode = computed(() =>
  isMonetary.value ? companiesStore.getCurrentCompany?.currency_code ?? 'RUB' : null,
)

// yAxis tick labels: abbreviated (млн/млрд) + currency symbol when monetary.
const axisValueFormatter = (value: number): string =>
  formatAxisValue(value, localeCode.value, currencyCode.value)

// Tooltip values: full grouped number (+ currency) so nothing is truncated.
const tooltipValueFormatter = (value: number): string =>
  formatFullNumber(value, localeCode.value, currencyCode.value)

// xAxis category labels: month / date for temporal buckets, passthrough else.
const categoryLabelFormatter = (value: string): string =>
  formatTemporalLabel(value, localeCode.value)

const colorAt = (index: number): string =>
  VIZION_ECHARTS_PALETTE[index % VIZION_ECHARTS_PALETTE.length] ?? '#2B4987'

// Top-to-bottom vertical gradient used as bar/line area fill so single-colour
// charts still read as premium. Mirrors the old dashboard-on-report widget.
const verticalGradient = (color: string, topAlpha = 0.85, bottomAlpha = 0.05) =>
  new graphic.LinearGradient(0, 0, 0, 1, [
    { offset: 0, color: withAlpha(color, topAlpha) },
    { offset: 1, color: withAlpha(color, bottomAlpha) },
  ])

function withAlpha(hex: string, alpha: number): string {
  const match = /^#([0-9a-f]{6})$/i.exec(hex.trim())
  if (!match) return hex
  const raw = match[1]!
  const r = parseInt(raw.slice(0, 2), 16)
  const g = parseInt(raw.slice(2, 4), 16)
  const b = parseInt(raw.slice(4, 6), 16)
  return `rgba(${r}, ${g}, ${b}, ${alpha})`
}

// Build a single ECharts `option` from the backend `{labels, datasets}` shape.
// Per-chart-type overrides win over the global `vizion` theme defaults.
const chartOption = computed<EChartsOption>(() => {
  const labels = props.data?.labels ?? []
  const datasets = props.data?.datasets ?? []
  const type = props.chartType

  const baseAnimation = {
    animation: true,
    animationDuration: 750,
    animationEasing: 'cubicOut' as const,
  }

  if (type === 'pie' || type === 'doughnut') {
    // Donut sits slightly smaller and higher so the bottom legend never collides
    // with the ring. Radius is a % of the container's shorter side, so it always
    // stays inside the (clipped) card body regardless of aspect ratio.
    const radius = type === 'doughnut' ? (['48%', '68%'] as const) : (['0%', '62%'] as const)
    const values = datasets[0]?.data ?? []

    return {
      ...baseAnimation,
      tooltip: {
        trigger: 'item',
        confine: true,
        // "<slice name>: <full value> (NN%)"
        valueFormatter: (value) =>
          typeof value === 'number' ? tooltipValueFormatter(value) : String(value ?? ''),
      },
      // `type: 'scroll'` keeps a long status list (Qualified / Deal in progress /
      // Marketing reserve / …) on a single paginated row instead of letting it
      // widen the layout and spill past the card edge onto the neighbour widget.
      legend: {
        type: 'scroll',
        bottom: 0,
        left: 'center',
        icon: 'circle',
        // Truncate any single over-long legend entry rather than overflow.
        formatter: (name: string) => (name.length > 22 ? `${name.slice(0, 21)}…` : name),
      },
      series: [
        {
          type: 'pie',
          radius: [...radius],
          // Reserve vertical room for the bottom legend (top:8% … bottom:18%) so
          // the ring is centred in the remaining space, never under the legend.
          center: ['50%', '44%'],
          data: labels.map((name, index) => ({
            name: categoryLabelFormatter(name),
            value: values[index] ?? 0,
            itemStyle: { color: colorAt(index) },
          })),
          itemStyle: { borderColor: '#fff', borderWidth: 2 },
          // Outside labels + leader lines on a pie can extend past the card edge;
          // keep them inside the ring (doughnut already has them off).
          label: { show: type === 'pie', position: 'inside', formatter: '{d}%' },
          labelLine: { show: false },
          emphasis: {
            scale: true,
            scaleSize: 6,
            itemStyle: { shadowBlur: 12, shadowColor: 'rgba(15, 23, 42, 0.18)' },
          },
        },
      ],
    }
  }

  // bar / line — cartesian, one series per dataset.
  const multiSeries = datasets.length > 1

  if (type === 'line') {
    return {
      ...baseAnimation,
      tooltip: {
        trigger: 'axis',
        confine: true,
        valueFormatter: (value) =>
          typeof value === 'number' ? tooltipValueFormatter(value) : String(value ?? ''),
      },
      legend: multiSeries
        ? { type: 'scroll', bottom: 0, left: 'center', icon: 'circle' }
        : { show: false },
      grid: { left: 12, right: 16, top: 16, bottom: multiSeries ? 36 : 24, containLabel: true },
      xAxis: {
        type: 'category',
        data: labels,
        boundaryGap: false,
        axisLabel: { formatter: categoryLabelFormatter },
      },
      yAxis: { type: 'value', axisLabel: { formatter: axisValueFormatter } },
      series: datasets.map((ds, dsIndex) => {
        const color = colorAt(dsIndex)
        return {
          name: resolveSeriesLabel(ds.label, props.config, localeCode.value),
          type: 'line',
          smooth: true,
          symbol: 'circle',
          symbolSize: 6,
          showSymbol: labels.length <= 24,
          data: ds.data,
          lineStyle: { width: 2.5, color },
          itemStyle: { color, borderColor: '#fff', borderWidth: 2 },
          areaStyle: multiSeries ? undefined : { color: verticalGradient(color, 0.45, 0.02) },
          emphasis: { focus: 'series' },
        }
      }),
    }
  }

  // bar
  return {
    ...baseAnimation,
    tooltip: {
      trigger: 'axis',
      confine: true,
      axisPointer: { type: 'shadow' },
      valueFormatter: (value) =>
        typeof value === 'number' ? tooltipValueFormatter(value) : String(value ?? ''),
    },
    legend: multiSeries
      ? { type: 'scroll', bottom: 0, left: 'center', icon: 'circle' }
      : { show: false },
    grid: { left: 12, right: 16, top: 16, bottom: multiSeries ? 36 : 24, containLabel: true },
    xAxis: { type: 'category', data: labels, axisLabel: { formatter: categoryLabelFormatter } },
    yAxis: { type: 'value', axisLabel: { formatter: axisValueFormatter } },
    series: datasets.map((ds, dsIndex) => {
      const color = colorAt(dsIndex)
      return {
        name: resolveSeriesLabel(ds.label, props.config, localeCode.value),
        type: 'bar',
        data: ds.data,
        itemStyle: {
          color: multiSeries ? color : verticalGradient(color, 0.95, 0.55),
          borderRadius: [6, 6, 0, 0],
        },
        emphasis: {
          itemStyle: { color: multiSeries ? color : verticalGradient(color, 1, 0.7) },
        },
        barMaxWidth: 48,
      }
    }),
  }
})

const menuItems = computed<MenuItem[]>(() => [
  {
    label: t('actions.edit'),
    icon: 'pi pi-pencil',
    command: () => emit('edit'),
  },
  {
    label: props.visible ? t('actions.hide') : t('actions.show'),
    icon: props.visible ? 'pi pi-eye-slash' : 'pi pi-eye',
    command: () => emit('toggle-visibility', !props.visible),
  },
  {
    separator: true,
  },
  {
    label: t('actions.detach'),
    icon: 'pi pi-times',
    command: () => emit('detach'),
  },
])

const toggleMenu = (event: Event) => {
  menuRef.value?.toggle(event)
}
</script>

<style lang="scss" scoped>
.widget-card {
  display: flex;
  flex-direction: column;
  height: 100%;
  background: $surface-0;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  overflow: hidden;

  &--hidden {
    opacity: 0.55;
  }

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: $space-2;
    padding: $space-2 $space-3;
    border-bottom: 1px solid $surface-200;
    flex-shrink: 0;
    // Reserve as a drag handle for grid-layout-plus — the body's chart should
    // not initiate a drag, only the header.
    cursor: move;
  }

  &__title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-900;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    min-width: 0;
  }

  &__actions {
    display: inline-flex;
    align-items: center;
    gap: $space-1;
    flex-shrink: 0;
    // Buttons must not start a drag.
    cursor: default;
  }

  &__body {
    flex: 1;
    min-height: 0;
    // min-width:0 lets the body shrink below the chart's intrinsic size inside
    // the absolutely-positioned grid item; overflow:hidden is the hard backstop
    // so an ECharts canvas / legend can never paint past the card onto a
    // neighbouring widget.
    min-width: 0;
    padding: $space-2;
    position: relative;
    overflow: hidden;
  }

  &__chart {
    width: 100%;
    height: 100%;
    // Absolute-fill the body so vue-echarts' `autoresize` always measures the
    // real (clipped) box, never a stale larger size during a grid reflow.
    position: absolute;
    inset: $space-2;
  }

  &__state {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    gap: $space-2;
    height: 100%;
    color: $surface-500;

    .pi {
      font-size: 1.5rem;
    }

    &--empty {
      font-size: $font-size-sm;
    }
  }
}
</style>
