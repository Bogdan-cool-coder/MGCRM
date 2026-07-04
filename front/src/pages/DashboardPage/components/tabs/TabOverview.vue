<template>
  <div class="tab-overview">
    <!-- The overview filters (named period · pipeline · manager · Edit toggle)
         all live in the shared hub AnalyticsFilterBar now (audit §3в: the legacy
         DashboardToolbar with its duplicate pickers was removed). This tab is a
         pure consumer — it receives those filters via props and refetches. -->

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
    <div v-if="props.editMode" class="tab-overview__edit-banner">
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
      :edit="props.editMode"
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
import { computed, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import Message from 'primevue/message'
import Button from 'primevue/button'
import { useDashboardPage } from '../../composables/useDashboardPage'
import { useDashboardLayout } from '../../composables/useDashboardLayout'
import { useNoTaskPreview } from '../../composables/useNoTaskPreview'
import WidgetGrid from '../WidgetGrid.vue'
import WidgetKpiCard from '../WidgetKpiCard.vue'
import WidgetFunnelTable from '../WidgetFunnelTable.vue'
import WidgetTopBar from '../WidgetTopBar.vue'
import WidgetForecast from '../WidgetForecast.vue'
import WidgetDealsWithoutTasks from '../WidgetDealsWithoutTasks.vue'
import type { StatusGroup } from '@/entities/salesDashboard'

const props = defineProps<{
  /** Explicit month selection ("YYYY-MM") from the shared hub month-picker (Ф8). */
  months: string[]
  /** Selected funnel from the shared hub filter bar. */
  pipelineId: number | null
  /** Cross-user manager filter from the shared hub filter bar. */
  managerId: number | null
  /** Widget-grid edit mode toggled from the shared hub filter bar. */
  editMode: boolean
  /** Hub's pipeline pre-selection in flight — gates the initial fetch. */
  pipelinesLoading: boolean
}>()

const { t } = useI18n()
const router = useRouter()

// ECharts dark-theme is registered app-wide in App.vue (useMacroCrmEchartsTheme).

// Filters are hub-owned; this tab feeds them in as getters and refetches on change.
const { data, loading, start } = useDashboardPage({
  months: () => props.months,
  pipelineId: () => props.pipelineId,
  managerId: () => props.managerId,
})

// The hub pre-selects a pipeline asynchronously. Fire the first load once the
// pipeline pre-selection settles: immediately if one is already selected at
// mount, on the first null→id transition, or — if the hub finishes loading with
// no pipeline at all — once loading ends (the backend resolves a default and
// returns `meta.no_pipeline`, so the empty-state renders instead of a perpetual
// skeleton). `start()` arms the composable's debounced watch; this single guarded
// call prevents a duplicate initial fetch.
let started = false
const kickoff = (): void => {
  if (started) return
  started = true
  void start()
}
onMounted(() => {
  if (props.pipelineId != null || !props.pipelinesLoading) kickoff()
})
watch(
  () => props.pipelineId,
  (id) => {
    if (id != null) kickoff()
  },
)
watch(
  () => props.pipelinesLoading,
  (loading) => {
    // Pre-selection finished with no pipeline → still fetch (backend default).
    if (!loading) kickoff()
  },
)

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

// ─── Layout (order/visibility, persisted) ─────────────────────────────────────
// Edit mode is hub-owned (`props.editMode`, toggled from the shared filter bar).
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
} = useNoTaskPreview(() => props.pipelineId ?? null)

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
  if (props.pipelineId != null) query.pipeline_id = String(props.pipelineId)
  void router.push({ path: '/deals', query })
}
</script>

<style lang="scss" scoped>
// Brand accent reads on both themes from the reactive PrimeVue token
// (navy #172747 in light → #4C7DF0 in dark) — no static $primary-color.
.tab-overview__banner-link {
  color: var(--p-primary-color);
  font-weight: $font-weight-semibold;
  text-decoration: underline;
  text-underline-offset: 2px;
}

// Edit-mode hint plaque: a reactive primary tint over the card surface (color-mix
// on --p-primary-color) so the fill + border track the theme instead of pinning
// the static light $primary-50/$primary-200 that stayed a pale plaque on navy.
.tab-overview__edit-banner {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  margin-bottom: $space-4;
  background: color-mix(in srgb, var(--p-primary-color) 8%, var(--p-surface-card));
  border: 1px solid color-mix(in srgb, var(--p-primary-color) 24%, var(--p-surface-card));
  border-radius: $radius-md;
}

.tab-overview__edit-icon {
  flex-shrink: 0;
  font-size: $font-size-sm;
  color: var(--p-primary-color);
}

.tab-overview__edit-text {
  flex: 1;
  min-width: 0;
  font-size: $font-size-sm;
  color: var(--p-primary-color);
}
</style>
