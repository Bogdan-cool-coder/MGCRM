<template>
  <div class="dashboard-page">
    <PageHeader :title="t('dashboard.hub.title')" icon="pi pi-chart-bar">
      <template #actions>
        <!-- Excel export lives on the report tabs (Ф2+). Hidden on Обзор/Планы. -->
        <Button
          v-if="showExport"
          icon="pi pi-file-excel"
          :label="t('dashboard.hub.export')"
          severity="secondary"
          outlined
          disabled
          v-tooltip.bottom="t('common.coming_soon')"
        />
      </template>
    </PageHeader>

    <!-- Cross-cutting filter bar (period/layer · funnel · manager) -->
    <div class="dashboard-page__filters">
      <AnalyticsFilterBar
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
        @update:granularity="setGranularity"
        @step="stepPeriod"
        @update:layer="setLayer"
        @update:pipeline-id="setPipelineId"
        @update:manager-id="setManagerId"
      />
    </div>

    <!-- Tab strip -->
    <div class="dashboard-page__tabs">
      <SelectButton
        :key="tabStripKey"
        :model-value="activeTab"
        :options="tabOptions"
        option-label="label"
        option-value="value"
        :allow-empty="false"
        @update:model-value="onTabSelect"
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

    <ConfirmDialog />
    <Toast position="top-right" />
  </div>
</template>

<script setup lang="ts">
import { computed, provide, ref, nextTick, type Component } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import ConfirmDialog from 'primevue/confirmdialog'
import Toast from 'primevue/toast'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import AnalyticsFilterBar from './components/AnalyticsFilterBar.vue'
import TabOverview from './components/tabs/TabOverview.vue'
import TabPlans from './components/tabs/TabPlans.vue'
import TabRegistry from './components/tabs/TabRegistry.vue'
import TabSchedule from './components/tabs/TabSchedule.vue'
import TabRating from './components/tabs/TabRating.vue'
import {
  useAnalyticsHub,
  HUB_REGISTER_LEAVE_GUARD,
  type HubTab,
} from './composables/useAnalyticsHub'

const { t } = useI18n()

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

// Each report/plan tab reads the cross-cutting hub filters through props (single
// source of truth, TZ §1.1). Обзор owns its own filters; Рейтинг (Ф3) is still a
// stub — pass nothing so no stray DOM attributes land on its root.
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
    default:
      return {}
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

.dashboard-page__filters,
.dashboard-page__tabs {
  padding: 0 $space-6;
}

.dashboard-page__filters {
  padding-top: $space-4;
}

.dashboard-page__tabs {
  margin-bottom: $space-2;
}

.dashboard-page__content {
  padding: 0 $space-6 $space-6;
  flex: 1;
  overflow-y: auto;
  min-height: 0;
}
</style>
