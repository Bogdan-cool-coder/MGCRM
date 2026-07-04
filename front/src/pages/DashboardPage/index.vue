<template>
  <div class="dashboard-page">
    <!-- Unified hub toolbar: icon-tile + title + segmented tabs + Excel.
         Excel export lives on the report/plans tabs; backend export endpoints are
         built in parallel — a 404 degrades to an info-toast «скоро» (live but
         graceful), so the button is enabled on those tabs. -->
    <HubToolbar
      :active-tab="activeTab"
      :tab-options="tabOptions"
      :tab-strip-key="tabStripKey"
      :show-export="showExport"
      :exporting="exporting"
      @update:active-tab="onTabSelect"
      @export="onExport"
    />

    <!-- Cross-cutting filter bar (period/layer · funnel · manager). Tab-aware:
         on Обзор it shows the named-period Select + Edit toggle, on report tabs
         the granularity/stepper/layer. Pipeline + manager are the single source
         of those filters for every tab (audit §3в: legacy DashboardToolbar gone). -->
    <div class="dashboard-page__filters">
      <AnalyticsFilterBar
        :overview-mode="activeTab === 'overview'"
        :overview-period="overviewPeriod"
        :edit-mode="overviewEditMode"
        :granularity="granularity"
        :year="year"
        :month="month"
        :layer="layer"
        :layer-active="activeTab === 'plans'"
        :pipeline-id="pipelineId"
        :manager-id="managerId"
        :pipelines="pipelines"
        :managers="managers"
        :pipelines-loading="pipelinesLoading"
        :can-see-all-managers="canSeeAllManagers"
        @update:overview-period="setOverviewPeriod"
        @toggle-edit="toggleOverviewEdit"
        @update:granularity="setGranularity"
        @step="stepPeriod"
        @update:layer="setLayer"
        @update:pipeline-id="setPipelineId"
        @update:manager-id="setManagerId"
      />
    </div>

    <!-- Active tab body (keep-alive so scroll/input survive tab switches) -->
    <div class="dashboard-page__content">
      <keep-alive>
        <component
          :is="activeComponent"
          :key="activeTab"
          v-bind="activeProps"
        />
      </keep-alive>
    </div>

    <!-- Toast & ConfirmDialog are app-wide singletons in DefaultLayout — a local
         instance rendered each toast/alertdialog twice. -->
  </div>
</template>

<script setup lang="ts">
import { computed, provide, ref, nextTick, type Component } from 'vue'
import HubToolbar from './components/HubToolbar.vue'
import AnalyticsFilterBar from './components/AnalyticsFilterBar.vue'
import TabOverview from './components/tabs/TabOverview.vue'
import TabPlans from './components/tabs/TabPlans.vue'
import TabRegistry from './components/tabs/TabRegistry.vue'
import TabSchedule from './components/tabs/TabSchedule.vue'
import TabRating from './components/tabs/TabRating.vue'
import {
  useAnalyticsHub,
  HUB_REGISTER_LEAVE_GUARD,
  PLANS_REGISTER_EXPORT,
  type HubTab,
  type PlansExportDescriptor,
} from './composables/useAnalyticsHub'
import { useAnalyticsExport } from './composables/useAnalyticsExport'
import type { ReportExportParams } from '@/api/reportsExport'

const {
  activeTab,
  tabOptions,
  setActiveTab,
  registerLeaveGuard,
  showExport,
  year,
  month,
  granularity,
  stepPeriod,
  setGranularity,
  setYear,
  overviewPeriod,
  setOverviewPeriod,
  overviewEditMode,
  toggleOverviewEdit,
  layer,
  setLayer,
  pipelineId,
  managerId,
  setPipelineId,
  setManagerId,
  pipelines,
  managers,
  pipelinesLoading,
  canSeeAllManagers,
} = useAnalyticsHub()

// The dirty-capable tab (Планы) registers a leave guard through this provider so
// switching tabs via the strip prompts before losing unsaved plan edits.
provide(HUB_REGISTER_LEAVE_GUARD, registerLeaveGuard)

// ─── Excel export (report + plans tabs) ──────────────────────────────────────
const { exporting, exportTab } = useAnalyticsExport()

// The Планы tab publishes the visible matrix's (metric, scope_type) here so the
// plans export mirrors the current GET /api/plans/matrix query (metric + scope).
const plansExport = ref<PlansExportDescriptor>({ metric: 'new_income', scope_type: 'user' })
provide(PLANS_REGISTER_EXPORT, (d: PlansExportDescriptor) => {
  plansExport.value = d
})

/** Build the export params slice relevant to the active tab. */
const exportParams = (): ReportExportParams => {
  switch (activeTab.value) {
    case 'plans':
      // Matrix export takes the same query as GET /api/plans/matrix: metric +
      // scope_type of the visible grid + year/layer/pipeline (month is n/a).
      return {
        year: year.value,
        layer: layer.value,
        pipeline_id: pipelineId.value,
        metric: plansExport.value.metric,
        scope_type: plansExport.value.scope_type,
      }
    case 'registry':
      return {
        year: year.value,
        month: month.value,
        pipeline_id: pipelineId.value,
        manager_id: managerId.value,
      }
    case 'schedule':
      return { year: year.value, month: month.value, pipeline_id: pipelineId.value }
    case 'rating':
      return { year: year.value, pipeline_id: pipelineId.value }
    default:
      return {}
  }
}

const onExport = (): void => {
  void exportTab(activeTab.value, exportParams())
}

// PrimeVue SelectButton toggles its internal checked-state OPTIMISTICALLY before
// the async leave guard resolves. On a veto («Остаться») the strip would stick
// on the clicked tab while the content correctly stays put. `:model-value` is
// the single source of truth; on veto we bump this key so the SelectButton is
// re-created and re-reads the (unchanged) activeTab, snapping the highlight back.
const tabStripKey = ref(0)

const onTabSelect = async (value: HubTab | null): Promise<void> => {
  if (value == null) return
  const switched = await setActiveTab(value)
  if (!switched) {
    // Force the strip to re-render with the correct model-value after the veto.
    await nextTick()
    tabStripKey.value += 1
  }
}

const TAB_COMPONENTS: Record<HubTab, Component> = {
  overview: TabOverview,
  plans: TabPlans,
  registry: TabRegistry,
  schedule: TabSchedule,
  rating: TabRating,
}

const activeComponent = computed<Component>(() => TAB_COMPONENTS[activeTab.value])

// Every tab reads the cross-cutting hub filters through props (single source of
// truth, TZ §1.1). Обзор now does too — pipeline/manager come from the shared
// bar, plus its own named period + edit mode (audit §3в).
const activeProps = computed<Record<string, unknown>>(() => {
  switch (activeTab.value) {
    case 'plans':
      return { year: year.value, layer: layer.value, pipelineId: pipelineId.value }
    case 'registry':
      return {
        year: year.value,
        month: month.value,
        pipelineId: pipelineId.value,
        managerId: managerId.value,
      }
    case 'schedule':
      return { year: year.value, month: month.value, pipelineId: pipelineId.value }
    case 'rating':
      // Рейтинг is annual: its year-selector drives the shared hub year via setYear
      // (kept as the single period source, §1.1), not a tab-local year.
      return {
        year: year.value,
        pipelineId: pipelineId.value,
        'onUpdate:year': setYear,
      }
    default:
      return {
        period: overviewPeriod.value,
        pipelineId: pipelineId.value,
        managerId: managerId.value,
        editMode: overviewEditMode.value,
        pipelinesLoading: pipelinesLoading.value,
      }
  }
})
</script>

<style lang="scss" scoped>
.dashboard-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.dashboard-page__filters {
  padding: $space-4 $space-6 0;
}

.dashboard-page__content {
  padding: 0 $space-6 $space-6;
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}
</style>
