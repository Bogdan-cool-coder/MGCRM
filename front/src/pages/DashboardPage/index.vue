<template>
  <div class="dashboard-page">
    <PageHeader :title="t('dashboard.title')" icon="pi pi-chart-bar">
      <template #actions>
        <Button
          icon="pi pi-download"
          :label="t('dashboard.export')"
          severity="secondary"
          outlined
          :disabled="loading"
          @click="exportXlsx()"
        />
      </template>
    </PageHeader>

    <div class="dashboard-page__content">
      <!-- Toolbar -->
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
          <WidgetFunnelTable
            :funnel="data?.funnel ?? null"
            :loading="loading"
          />
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
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import { useMacroCrmEchartsTheme } from '@/composables/useMacroCrmEchartsTheme'
import { useDashboardPage } from './composables/useDashboardPage'
import DashboardToolbar from './components/DashboardToolbar.vue'
import WidgetStatusGroups from './components/WidgetStatusGroups.vue'
import WidgetFunnelTable from './components/WidgetFunnelTable.vue'
import WidgetTopBar from './components/WidgetTopBar.vue'
import WidgetForecast from './components/WidgetForecast.vue'
import WidgetDealsWithoutTasks from './components/WidgetDealsWithoutTasks.vue'

const { t } = useI18n()

// Register reactive ECharts theme (dark-mode aware) — once per page mount
useMacroCrmEchartsTheme()

const {
  filters,
  pipelines,
  managers,
  pipelinesLoading,
  canSeeAllManagers,
  data,
  loading,
  setFilter,
  exportXlsx,
} = useDashboardPage()
</script>

<style lang="scss" scoped>
.dashboard-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.dashboard-page__content {
  padding: $space-6;
  flex: 1;
}
</style>
