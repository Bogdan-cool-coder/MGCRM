<template>
  <div
    class="widget-preview"
    role="button"
    tabindex="0"
    :aria-label="item.localizedName"
    @click="$emit('pick', item.id)"
    @keydown.enter.prevent="$emit('pick', item.id)"
    @keydown.space.prevent="$emit('pick', item.id)"
  >
    <div class="widget-preview__chart">
      <div v-if="status === 'loading' || status === 'idle'" class="widget-preview__state">
        <i class="pi pi-spin pi-spinner" aria-hidden="true" />
      </div>

      <div
        v-else-if="status === 'error' || !hasData"
        class="widget-preview__state widget-preview__state--fallback"
      >
        <i :class="['pi', fallbackIcon]" aria-hidden="true" />
      </div>

      <VChart
        v-else
        class="widget-preview__canvas"
        theme="vizion"
        :option="chartOption"
        autoresize
      />
    </div>

    <div class="widget-preview__meta">
      <span class="widget-preview__name" :title="item.localizedName">
        {{ item.localizedName }}
      </span>
      <span v-if="item.usedInDashboardsCount > 0" class="widget-preview__used">
        {{
          t('library.usedInDashboards', item.usedInDashboardsCount, {
            named: { count: item.usedInDashboardsCount },
          })
        }}
      </span>
    </div>

    <i class="pi pi-plus widget-preview__add" aria-hidden="true" />

    <!-- Per-card actions (delete). Only rendered for widgets the user can
         delete (own, or admin/superadmin; never system). `@click.stop` keeps
         the card's pick handler from firing when the menu is used. -->
    <template v-if="canDelete">
      <Button
        ref="menuAnchorRef"
        class="widget-preview__menu"
        icon="pi pi-ellipsis-v"
        severity="secondary"
        text
        rounded
        :aria-label="t('library.cardMenu')"
        :aria-haspopup="true"
        @click.stop="toggleMenu"
        @keydown.enter.stop
        @keydown.space.stop
      />

      <Popover ref="menuRef">
        <div class="widget-preview__menu-panel">
          <Button
            :label="t('library.delete')"
            icon="pi pi-trash"
            severity="danger"
            text
            class="widget-preview__menu-item"
            @click.stop="onDeleteClick"
          />
        </div>
      </Popover>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import VChart from 'vue-echarts'
import type { EChartsOption } from 'echarts'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useUserStore } from '@/stores/user'
import { canDeleteWidget } from '@/shared/auth/capabilities'
import type { WidgetChartType, WidgetData } from '@/entities/widget'
import { resolveSeriesLabel } from '@/utils/chartFormatters'
import { VIZION_ECHARTS_PALETTE } from '@/plugins/echarts'
import type { LocalizedWidgetItem } from './WidgetLibraryModal.vue'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t, locale } = useLocalI18n({ en, ru })
const userStore = useUserStore()

// vue-echarts manages the chart instance lifecycle itself (dispose on unmount),
// so the Chart.js-era preload / isAlive guard is no longer needed: there is no
// detached-canvas race to defend against.

interface Props {
  item: LocalizedWidgetItem
  status: 'idle' | 'loading' | 'loaded' | 'error'
  data: WidgetData | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
  ensure: [widgetId: number]
  pick: [widgetId: number]
  /** Request deletion of this widget (the library owns the confirm + API call). */
  delete: [item: LocalizedWidgetItem]
}>()

onMounted(() => {
  emit('ensure', props.item.id)
})

// ── Per-card actions menu ──────────────────────────────────────────────────
const menuRef = ref<InstanceType<typeof Popover> | null>(null)
const menuAnchorRef = ref<InstanceType<typeof Button> | null>(null)

const isOwner = computed<boolean>(() => {
  const me = userStore.currentUser
  if (!me || props.item.userId == null) return false
  return me.id === props.item.userId
})

const canDelete = computed<boolean>(() =>
  canDeleteWidget(userStore.currentUser?.role, isOwner.value, props.item.isSystem),
)

const toggleMenu = (event: MouseEvent): void => {
  if (!event.currentTarget) return
  if (!menuAnchorRef.value) return
  menuRef.value?.toggle(event)
}

const onDeleteClick = (): void => {
  menuRef.value?.hide()
  emit('delete', props.item)
}

const hasData = computed(
  () => !!props.data && props.data.labels.length > 0 && props.data.datasets.length > 0,
)

const colorAt = (index: number): string =>
  VIZION_ECHARTS_PALETTE[index % VIZION_ECHARTS_PALETTE.length] ?? '#2B4987'

const isCategorical = (type: WidgetChartType) => type === 'pie' || type === 'doughnut'

// Compact sparkline-style preview: no axes, no legend, no tooltips, no
// animation — pure shape at ~120px. Mirrors the global vizion palette.
const chartOption = computed<EChartsOption>(() => {
  const labels = props.data?.labels ?? []
  const datasets = props.data?.datasets ?? []
  const type = props.item.chartType

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
      const seriesName = resolveSeriesLabel(ds.label, props.item.config, String(locale.value))
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
  switch (props.item.chartType) {
    case 'line':
      return 'pi-chart-line'
    case 'pie':
    case 'doughnut':
      return 'pi-chart-pie'
    default:
      return 'pi-chart-bar'
  }
})
</script>

<style lang="scss" scoped>
.widget-preview {
  position: relative;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
  padding: 0.6rem;
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

    .widget-preview__add {
      opacity: 1;
    }
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
    gap: 0.15rem;
    min-width: 0;
  }

  &__name {
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    color: $surface-900;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
  }

  &__used {
    font-size: $font-size-xs;
    color: $surface-500;
  }

  &__add {
    position: absolute;
    top: 0.5rem;
    left: 0.5rem;
    width: 1.5rem;
    height: 1.5rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border-radius: 999px;
    background: $surface-0;
    color: $primary;
    font-size: 0.75rem;
    box-shadow: 0 1px 3px rgba(0, 0, 0, 0.12);
    opacity: 0;
    transition: opacity $transition-fast;
  }

  &__menu {
    position: absolute;
    top: 0.25rem;
    right: 0.25rem;
    // Compact corner button — override PrimeVue's default icon-button padding
    // so it does not crowd the card content.
    width: 1.75rem;
    height: 1.75rem;
    opacity: 0;
    transition: opacity $transition-fast;

    :deep(.p-button-icon) {
      font-size: 0.8rem;
    }
  }

  &:hover &__menu,
  &:focus-within &__menu {
    opacity: 1;
  }
}

.widget-preview__menu-panel {
  display: flex;
  flex-direction: column;
  min-width: 10rem;
}

.widget-preview__menu-item {
  justify-content: flex-start;
  width: 100%;

  :deep(.p-button-label) {
    flex: 1;
    text-align: left;
    font-weight: $font-weight-medium;
  }
}
</style>
