<template>
  <div class="tab-overview">
    <!-- Overview keeps its OWN toolbar + filter behaviour (TZ §1.1: «прежнее
         поведение фильтров сохранить»). The hub's AnalyticsFilterBar governs the
         report tabs; the overview widgets stay on the period-select they shipped
         with so nothing regresses. The Edit/Done toggle drives the widget-grid. -->
    <DashboardToolbar
      :filters="filters"
      :pipelines="pipelines"
      :managers="managers"
      :pipelines-loading="pipelinesLoading"
      :can-see-all-managers="canSeeAllManagers"
      :edit="editMode"
      @update:period="(v) => setFilter('period', v)"
      @update:pipeline-id="(v) => setFilter('pipeline_id', v)"
      @update:manager-id="(v) => setFilter('manager_id', v)"
      @toggle-edit="editMode = !editMode"
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

    <!-- Dismissible multi-currency warning (session-only; returns on reload so the
         underlying data problem stays visible — ТЗ §3.2). -->
    <Message
      v-if="data?.meta?.multi_currency_warning && !bannerDismissed"
      severity="warn"
      :closable="true"
      icon="pi pi-info-circle"
      class="mb-4"
      @close="bannerDismissed = true"
    >
      {{ t('dashboard.multiCurrencyWarning') }}
      <router-link
        class="tab-overview__banner-link"
        :to="{ path: '/settings', query: { section: 'exchange-rates' } }"
      >
        {{ t('dashboard.currencyBanner.link') }}
      </router-link>
    </Message>

    <!-- Edit-mode hint + reset -->
    <div v-if="editMode" class="tab-overview__edit-banner">
      <i class="pi pi-arrows-alt tab-overview__edit-icon" />
      <span class="tab-overview__edit-text">{{ t('dashboard.layout.editHint') }}</span>
      <Button
        :label="t('dashboard.layout.reset')"
        icon="pi pi-refresh"
        severity="secondary"
        text
        size="small"
        @click="resetLayout"
      />
    </div>

    <!-- 12-column widget grid (variant Б: order + visibility) -->
    <WidgetGrid
      :ordered="ordered"
      :edit="editMode"
      :is-first="isFirst"
      :is-last="isLast"
      @move-up="moveUp"
      @move-down="moveDown"
      @toggle-visible="toggleVisible"
    >
      <template #kpi-active>
        <WidgetKpiCard
          :group="groupByKey.active"
          :base-currency="baseCurrency"
          :loading="loading"
          @open="openDealsList()"
        />
      </template>
      <template #kpi-won>
        <WidgetKpiCard
          :group="groupByKey.won"
          :base-currency="baseCurrency"
          :loading="loading"
          @open="openDealsList()"
        />
      </template>
      <template #kpi-lost>
        <WidgetKpiCard
          :group="groupByKey.lost"
          :base-currency="baseCurrency"
          :loading="loading"
          @open="openDealsList()"
        />
      </template>
      <template #kpi-total>
        <WidgetKpiCard
          :group="groupByKey.total"
          :base-currency="baseCurrency"
          :loading="loading"
          @open="openDealsList()"
        />
      </template>

      <template #funnel>
        <WidgetFunnelTable :funnel="data?.funnel ?? null" :loading="loading" />
      </template>

      <template #forecast>
        <WidgetForecast
          :forecast="data?.forecast ?? null"
          :base-currency="baseCurrency"
          :loading="loading"
        />
      </template>

      <template #top>
        <WidgetTopBar
          :top-products="data?.top_products ?? null"
          :top-managers="data?.top_managers ?? null"
          :base-currency="baseCurrency"
          :loading="loading"
        />
      </template>

      <template #notask>
        <WidgetDealsWithoutTasks
          :data="dealsWithoutTasksView"
          :loading="loading"
          :deals="noTaskDeals"
          :preview-loading="noTaskLoading"
          :preview-failed="noTaskFailed"
          @task-created="onNoTaskTaskCreated"
        />
      </template>
    </WidgetGrid>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Message from 'primevue/message'
import Button from 'primevue/button'
import { useDashboardPage } from '../../composables/useDashboardPage'
import { useDashboardLayout } from '../../composables/useDashboardLayout'
import { useNoTaskPreview } from '../../composables/useNoTaskPreview'
import DashboardToolbar from '../DashboardToolbar.vue'
import WidgetGrid from '../WidgetGrid.vue'
import WidgetKpiCard from '../WidgetKpiCard.vue'
import WidgetFunnelTable from '../WidgetFunnelTable.vue'
import WidgetTopBar from '../WidgetTopBar.vue'
import WidgetForecast from '../WidgetForecast.vue'
import WidgetDealsWithoutTasks from '../WidgetDealsWithoutTasks.vue'
import type { StatusGroup } from '@/entities/salesDashboard'

const { t } = useI18n()
const router = useRouter()

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

const baseCurrency = computed(() => data.value?.meta?.base_currency ?? 'RUB')

// ─── KPI groups keyed for the grid slots ──────────────────────────────────────
const groupByKey = computed<Record<StatusGroup['key'], StatusGroup | null>>(() => {
  const map: Record<StatusGroup['key'], StatusGroup | null> = {
    active: null,
    won: null,
    lost: null,
    total: null,
  }
  for (const g of data.value?.status_groups ?? []) map[g.key] = g
  return map
})

// ─── Layout (edit mode + order/visibility, persisted) ─────────────────────────
const editMode = ref(false)
const {
  ordered,
  moveUp,
  moveDown,
  toggleVisible,
  reset: resetLayout,
  isFirst,
  isLast,
} = useDashboardLayout()

// ─── Currency banner (session-only dismissal) ─────────────────────────────────
const bannerDismissed = ref(false)

// ─── "Deals without tasks" preview list ───────────────────────────────────────
const {
  deals: noTaskDeals,
  loading: noTaskLoading,
  failed: noTaskFailed,
  removeDeal,
} = useNoTaskPreview(() => filters.pipeline_id ?? null)

/**
 * Badge freshness (BUG-NOTASK-BADGE-STALE): the «N требуют внимания» count comes
 * from the main dashboard response, which we deliberately do NOT refetch when a
 * task is created inline (ТЗ §3.8). Mutating the response object in place is
 * fragile (it is the async-resource payload and would be clobbered on refetch),
 * so we keep a local decrement overlay: each inline task-create bumps it, and it
 * resets to 0 whenever fresh dashboard data arrives. The widget receives the
 * corrected count via `dealsWithoutTasksView`, clamped at 0.
 */
const noTaskLocalDecrement = ref(0)

watch(
  () => data.value,
  () => {
    noTaskLocalDecrement.value = 0
  },
)

const dealsWithoutTasksView = computed(() => {
  const raw = data.value?.deals_without_tasks ?? null
  if (raw === null) return null
  return {
    ...raw,
    count: Math.max(0, raw.count - noTaskLocalDecrement.value),
  }
})

/**
 * A task was just created for a deal in the preview: optimistically drop the row
 * AND decrement the local badge overlay so the «N требуют внимания» count stays
 * honest without a full dashboard refetch (ТЗ §3.8).
 */
const onNoTaskTaskCreated = (dealId: number): void => {
  removeDeal(dealId)
  noTaskLocalDecrement.value += 1
}

// ─── Deep-links to the deals list ─────────────────────────────────────────────
// KPI cards open the deals list scoped to the current pipeline. Precise
// status/stage filters are ГЭП-5 (DealsPage consumes pipeline_id + only_no_task
// from route.query today); we keep to the supported params, no invented filters.
const openDealsList = (): void => {
  const query: Record<string, string> = {}
  if (filters.pipeline_id != null) query.pipeline_id = String(filters.pipeline_id)
  void router.push({ path: '/deals', query })
}
</script>

<style lang="scss" scoped>
.tab-overview__banner-link {
  color: $primary-color;
  font-weight: $font-weight-semibold;
  text-decoration: underline;
  text-underline-offset: 2px;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.tab-overview__edit-banner {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  margin-bottom: $space-4;
  background: $primary-50;
  border: 1px solid $primary-200;
  border-radius: $radius-md;
}

.tab-overview__edit-icon {
  flex-shrink: 0;
  font-size: $font-size-sm;
  color: $primary-color;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.tab-overview__edit-text {
  flex: 1;
  min-width: 0;
  font-size: $font-size-sm;
  color: $primary-color;

  .app-dark & {
    color: var(--p-primary-color);
  }
}
</style>
