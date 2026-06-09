<template>
  <div
    class="widget-variant-card"
    role="button"
    :tabindex="disabled ? -1 : 0"
    :aria-disabled="disabled"
    :aria-label="variant.label"
    @click="handlePick"
    @keydown.enter.prevent="handlePick"
    @keydown.space.prevent="handlePick"
  >
    <div class="widget-variant-card__chart">
      <div v-if="status === 'loading' || status === 'idle'" class="widget-variant-card__state">
        <i class="pi pi-spin pi-spinner" aria-hidden="true" />
      </div>

      <div
        v-else-if="status === 'error' || !hasData"
        class="widget-variant-card__state widget-variant-card__state--fallback"
      >
        <i :class="['pi', fallbackIcon]" aria-hidden="true" />
      </div>

      <VChart
        v-else
        class="widget-variant-card__canvas"
        theme="vizion"
        :option="chartOption"
        autoresize
      />
    </div>

    <div class="widget-variant-card__meta">
      <span class="widget-variant-card__label" :title="variant.label">
        {{ variant.label }}
      </span>
      <Button
        class="widget-variant-card__pick"
        :label="t('widgetGenerationModal.variants.pick')"
        icon="pi pi-check"
        size="small"
        severity="primary"
        :disabled="disabled"
        type="button"
        @click.stop="handlePick"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref, shallowRef } from 'vue'
import VChart from 'vue-echarts'
import Button from 'primevue/button'
import type { EChartsOption } from 'echarts'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useServices } from '@/services'
import type { WidgetVariantDto } from '@/api/types/chats'
import type { WidgetChartType, WidgetData } from '@/entities/widget'
import { resolveSeriesLabel } from '@/utils/chartFormatters'
import { VIZION_ECHARTS_PALETTE } from '@/plugins/echarts'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t, locale } = useLocalI18n({ en, ru })
const { widgetService } = useServices()

interface Props {
  variant: WidgetVariantDto
  /** Locked once the user has chosen a variant — prevents double-submit. */
  disabled?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  disabled: false,
})

const emit = defineEmits<{
  pick: [index: number]
}>()

type PreviewStatus = 'idle' | 'loading' | 'loaded' | 'error'

const status = ref<PreviewStatus>('idle')
const data = shallowRef<WidgetData | null>(null)

const VALID_CHART_TYPES: readonly WidgetChartType[] = ['bar', 'line', 'pie', 'doughnut']

const chartType = computed<WidgetChartType>(() => {
  const type = props.variant.config?.chart?.type
  return VALID_CHART_TYPES.includes(type as WidgetChartType)
    ? (type as WidgetChartType)
    : 'bar'
})

// Fetch the preview for this variant's config once on mount. Best-effort: a
// failed preview falls back to the chart-type icon and never blocks selection.
onMounted(async () => {
  status.value = 'loading'
  try {
    data.value = await widgetService.previewWidget(props.variant.config)
    status.value = 'loaded'
  } catch {
    data.value = null
    status.value = 'error'
  }
})

const hasData = computed(
  () => !!data.value && data.value.labels.length > 0 && data.value.datasets.length > 0,
)

const colorAt = (index: number): string =>
  VIZION_ECHARTS_PALETTE[index % VIZION_ECHARTS_PALETTE.length] ?? '#2B4987'

const isCategorical = (type: WidgetChartType) => type === 'pie' || type === 'doughnut'

// Compact sparkline-style preview: no axes, no legend, no tooltips, no
// animation. Mirrors `WidgetPreviewCard`'s render so variants and library
// thumbnails look identical.
const chartOption = computed<EChartsOption>(() => {
  const labels = data.value?.labels ?? []
  const datasets = data.value?.datasets ?? []
  const type = chartType.value

  if (isCategorical(type)) {
    const radius = type === 'doughnut' ? (['45%', '72%'] as const) : (['0%', '72%'] as const)
    const values = datasets[0]?.data ?? []
    return {
      animation: false,
      series: [
        {
          type: 'pie',
          radius: [...radius],
          center: ['50%', '50%'],
          silent: true,
          label: { show: false },
          labelLine: { show: false },
          data: labels.map((name, index) => ({
            name,
            value: values[index] ?? 0,
            itemStyle: { color: colorAt(index), borderColor: '#fff', borderWidth: 1 },
          })),
        },
      ],
    }
  }

  return {
    animation: false,
    grid: { left: 2, right: 2, top: 6, bottom: 2, containLabel: false },
    xAxis: {
      type: 'category',
      data: labels,
      show: false,
      boundaryGap: type === 'bar',
    },
    yAxis: { type: 'value', show: false },
    series: datasets.map((ds, dsIndex) => {
      const color = colorAt(dsIndex)
      const seriesName = resolveSeriesLabel(ds.label, props.variant.config, String(locale.value))
      if (type === 'line') {
        return {
          name: seriesName,
          type: 'line',
          data: ds.data,
          smooth: true,
          showSymbol: false,
          silent: true,
          lineStyle: { width: 2, color },
          areaStyle: { color, opacity: 0.12 },
        }
      }
      return {
        name: seriesName,
        type: 'bar',
        data: ds.data,
        silent: true,
        itemStyle: { color, borderRadius: [2, 2, 0, 0] },
        barMaxWidth: 14,
      }
    }),
  }
})

const fallbackIcon = computed(() => {
  switch (chartType.value) {
    case 'line':
      return 'pi-chart-line'
    case 'pie':
    case 'doughnut':
      return 'pi-chart-pie'
    default:
      return 'pi-chart-bar'
  }
})

const handlePick = () => {
  if (props.disabled) return
  emit('pick', props.variant.index)
}
</script>

<style lang="scss" scoped>
.widget-variant-card {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-2;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  cursor: pointer;
  transition: background-color $transition-fast, border-color $transition-fast,
    box-shadow $transition-fast;

  &:hover,
  &:focus-visible {
    background: $surface-50;
    border-color: rgba($primary, 0.4);
    outline: none;
  }

  &[aria-disabled='true'] {
    cursor: default;
    opacity: 0.55;
  }

  &__chart {
    position: relative;
    height: 120px;
    min-height: 120px;
  }

  &__canvas {
    width: 100%;
    height: 100%;
  }

  &__state {
    display: flex;
    align-items: center;
    justify-content: center;
    height: 100%;
    color: $surface-400;

    .pi {
      font-size: 1.4rem;
    }

    &--fallback .pi {
      font-size: 2rem;
      color: $surface-300;
    }
  }

  &__meta {
    display: flex;
    flex-direction: column;
    gap: $space-2;
    min-width: 0;
  }

  &__label {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: $surface-900;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
  }

  &__pick {
    align-self: stretch;
  }
}
</style>
