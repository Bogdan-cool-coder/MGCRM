<template>
  <div class="tab-overview">
    <!-- Overview keeps its OWN toolbar + filter behaviour (TZ §1.1: «прежнее
         поведение фильтров сохранить»). The hub's AnalyticsFilterBar governs the
         report tabs; the overview widgets stay on the period-select they shipped
         with so nothing regresses. -->
    <DashboardToolbar
      :filters="filters"
      :pipelines="pipelines"
      :managers="managers"
      :pipelines-loading="pipelinesLoading"
      :can-see-all-managers="canSeeAllManagers"
      @update:period="(v) => setFilter('period', v)"
      @update:pipeline-id="(v) => setFilter('pipeline_id', v)"
      @update:manager-id="(v) => setFilter('manager_id', v)"
    />

    <!-- No active/selected pipeline: every widget is empty because no funnel
         resolved. Surface a single explanatory message. -->
    <Message
      v-if="!loading && data?.meta?.no_pipeline"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.noPipeline') }}
    </Message>

    <!-- Multi-currency warning -->
    <Message
      v-if="data?.meta?.multi_currency_warning"
      severity="warn"
      :closable="false"
      icon="pi pi-info-circle"
      class="mb-4"
    >
      {{ t('dashboard.multiCurrencyWarning') }}
    </Message>

    <!-- Row 1: Status groups -->
    <div class="row g-4 mb-4">
      <div class="col-12">
        <WidgetStatusGroups
          :groups="data?.status_groups ?? []"
          :base-currency="data?.meta?.base_currency ?? 'RUB'"
          :loading="loading"
        />
      </div>
    </div>

    <!-- Row 2: Funnel + Forecast -->
    <div class="row g-4 mb-4">
      <div class="col-12 col-lg-7">
        <WidgetFunnelTable :funnel="data?.funnel ?? null" :loading="loading" />
      </div>
      <div class="col-12 col-lg-5">
        <WidgetForecast
          :forecast="data?.forecast ?? null"
          :base-currency="data?.meta?.base_currency ?? 'RUB'"
          :loading="loading"
        />
      </div>
    </div>

    <!-- Row 3: Top chart + Deals without tasks -->
    <div class="row g-4">
      <div class="col-12 col-lg-8">
        <WidgetTopBar
          :top-products="data?.top_products ?? null"
          :top-managers="data?.top_managers ?? null"
          :base-currency="data?.meta?.base_currency ?? 'RUB'"
          :loading="loading"
        />
      </div>
      <div class="col-12 col-lg-4">
        <WidgetDealsWithoutTasks
          :data="data?.deals_without_tasks ?? null"
          :loading="loading"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import { useDashboardPage } from '../../composables/useDashboardPage'
import DashboardToolbar from '../DashboardToolbar.vue'
import WidgetStatusGroups from '../WidgetStatusGroups.vue'
import WidgetFunnelTable from '../WidgetFunnelTable.vue'
import WidgetTopBar from '../WidgetTopBar.vue'
import WidgetForecast from '../WidgetForecast.vue'
import WidgetDealsWithoutTasks from '../WidgetDealsWithoutTasks.vue'

const { t } = useI18n()

// ECharts dark-theme is registered app-wide in App.vue (useMacroCrmEchartsTheme).

const {
  filters,
  pipelines,
  managers,
  pipelinesLoading,
  canSeeAllManagers,
  data,
  loading,
  setFilter,
} = useDashboardPage()
</script>
