<template>
  <!-- Whole tab on a widget-card подложка (restyle §2.1). div-based (ОВ-1) so the
       matrix keeps its scroll-height="flex" + sticky footer inside a flex column. -->
  <div class="tab-plans">
    <!-- Card header: dynamic metric title (left) + metric segmented (right).
         Все метрики включены: Поступления (P-1), Продукты (R6), Задачи (R4),
         Конверсии (R5). Каждая монтирует свою панель — dirty-guard активной
         панели прерывает и переключение метрики, и переключение таба хаба. -->
    <header class="tab-plans__head">
      <span class="tab-plans__head-title">{{ metricTitle }}</span>
      <SelectButton
        :key="metricStripKey"
        :model-value="metricTab"
        :options="metricOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        class="tab-plans__metrics"
        @update:model-value="onMetric"
      />
    </header>

    <div class="tab-plans__body">
      <component
        :is="metricComponent"
        :year="year"
        :layer="layer"
        :pipeline-id="pipelineId"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, provide, watch, nextTick, inject, onMounted, onActivated, onDeactivated, onBeforeUnmount, type Component } from 'vue'
import { useI18n } from 'vue-i18n'
import SelectButton from 'primevue/selectbutton'
import MetricIncome from '../plans/MetricIncome.vue'
import MetricProductIncome from '../plans/MetricProductIncome.vue'
import MetricTasks from '../plans/MetricTasks.vue'
import MetricConversions from '../plans/MetricConversions.vue'
import {
  HUB_REGISTER_LEAVE_GUARD,
  PLANS_REGISTER_METRIC_GUARD,
  PLANS_REGISTER_EXPORT,
  type HubLeaveGuard,
  type PlansExportDescriptor,
} from '../../composables/useAnalyticsHub'
import type { PlanLayer } from '@/entities/planTargets'

defineProps<{
  year: number
  layer: PlanLayer
  pipelineId: number | null
}>()

const { t } = useI18n()

/** Metric sub-tab (local state — the metric panel owns its own data/dirty-set). */
type PlanMetricTab = 'income' | 'product' | 'tasks' | 'conversions'
const metricTab = ref<PlanMetricTab>('income')

interface MetricOption {
  label: string
  value: PlanMetricTab
}

const metricOptions = computed<MetricOption[]>(() => [
  { label: t('dashboard.plans.metric_income'), value: 'income' },
  { label: t('dashboard.plans.metric_product'), value: 'product' },
  { label: t('dashboard.plans.metric_tasks'), value: 'tasks' },
  { label: t('dashboard.plans.metric_conversions'), value: 'conversions' },
])

const METRIC_COMPONENTS: Record<PlanMetricTab, Component> = {
  income: MetricIncome,
  product: MetricProductIncome,
  tasks: MetricTasks,
  conversions: MetricConversions,
}

const metricComponent = computed<Component>(() => METRIC_COMPONENTS[metricTab.value])

/** Card-header title mirrors the active metric (restyle §2.1). */
const metricTitle = computed<string>(
  () => metricOptions.value.find((o) => o.value === metricTab.value)?.label ?? '',
)

// ─── Dirty-guard bridge ─────────────────────────────────────────────────────
// The active metric panel (Поступления / Задачи) registers its leave-guard here.
// TabPlans (a) forwards it to the hub so an OUTER tab switch prompts, and (b)
// consults it on an INNER metric switch so changing метрику with unsaved edits
// prompts too. Only one panel is mounted at a time → one guard at a time.
const activeGuard = ref<HubLeaveGuard | null>(null)
const registerMetricGuard = (guard: HubLeaveGuard | null): void => {
  activeGuard.value = guard
}
provide(PLANS_REGISTER_METRIC_GUARD, registerMetricGuard)

// Forward the active panel's guard to the hub (outer tab-strip switch).
const hubRegister = inject(HUB_REGISTER_LEAVE_GUARD, null)
const forwardGuard: HubLeaveGuard = () => (activeGuard.value ? activeGuard.value() : true)
onMounted(() => hubRegister?.(forwardGuard))
onActivated(() => hubRegister?.(forwardGuard))
onDeactivated(() => hubRegister?.(null))
onBeforeUnmount(() => hubRegister?.(null))

// ─── Export descriptor bridge ───────────────────────────────────────────────
// Publish the active metric's (metric, scope_type) to the shell so its Excel
// button exports the visible plans matrix via `/api/plans/matrix/export`. The
// product panel refines its own entry (breakdown = company vs user scope) through
// the same injected register, so its publish wins after mounting.
const hubExport = inject(PLANS_REGISTER_EXPORT, null)

/** Static metric → export query descriptor (product refined by its own panel). */
const METRIC_EXPORT: Record<PlanMetricTab, PlansExportDescriptor> = {
  income: { metric: 'new_income', scope_type: 'user' },
  product: { metric: 'product_income', scope_type: 'company' },
  tasks: { metric: 'tasks_completed', scope_type: 'user' },
  conversions: { metric: 'conversion', scope_type: 'user' },
}

const publishExport = (): void => {
  const d = METRIC_EXPORT[metricTab.value]
  hubExport?.({ ...d })
}
watch(metricTab, publishExport)
onMounted(publishExport)
onActivated(publishExport)

// Provide down so the product panel can override the descriptor for its inner
// breakdown (product line → company scope; employee → user scope).
provide(PLANS_REGISTER_EXPORT, (d: PlansExportDescriptor) => hubExport?.(d))

// PrimeVue SelectButton toggles its checked-state optimistically before the async
// guard resolves; on a veto we bump this key to re-create the strip so the
// highlight snaps back to the unchanged metric (same trick as the hub tab-strip).
const metricStripKey = ref(0)

const onMetric = async (v: PlanMetricTab | null): Promise<void> => {
  if (v == null || v === metricTab.value) return
  if (activeGuard.value) {
    const ok = await activeGuard.value()
    if (!ok) {
      await nextTick()
      metricStripKey.value += 1
      return
    }
  }
  metricTab.value = v
}
</script>

<style lang="scss" scoped>
// widget-card подложка (= Card.widget-card в Обзоре), но div-based (ОВ-1) чтобы
// матрица сохранила scroll-height="flex" и sticky-футер внутри flex-колонки.
.tab-plans {
  display: flex;
  flex-direction: column;
  min-height: 0;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.tab-plans__head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  flex-wrap: wrap;
  gap: $space-3;
  padding: $space-4 $space-4 $space-3;
}

.tab-plans__head-title {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: $surface-800;
}

.tab-plans__body {
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: 0 $space-4 $space-4;
}
</style>
