<template>
  <div class="deals-page">
    <!-- Bulk toolbar (replaces normal toolbar when bulk mode is active) -->
    <DealsBulkToolbar
      v-if="salesStore.bulkMode"
      :selected-count="salesStore.bulkSelection.length"
      :total-visible="allVisibleDealIds.length"
      @cancel="salesStore.exitBulkMode()"
      @assign-owner="onBulkAssignOwner"
      @add-task="onBulkAddTask"
      @move-stage="onBulkMoveStage"
      @edit-field="onBulkEditField"
      @edit-tags="onBulkEditTags"
      @delete="onBulkDelete"
      @select-all="salesStore.selectAllBulk(allVisibleDealIds)"
      @clear-selection="salesStore.clearBulkSelection()"
    />

    <!-- Main toolbar (one-liner with pipeline picker) -->
    <DealsToolbar
      v-else
      :active-view="salesStore.activeView"
      :total-deals="totalDealsCount"
      :total-sum="totalSumFormatted"
      :pipeline-name="activePipelineName"
      :filter-active="hasActiveFilters()"
      :filter-count="activeFilterCount"
      :pipelines="pipelines"
      :pipeline-menu-open="pipelineMenuOpen"
      :active-pipeline-id="currentPipelineId"
      @open-filter="filterOverlayVisible = !filterOverlayVisible"
      @open-pipeline-menu="pipelineMenuOpen = !pipelineMenuOpen"
      @close-pipeline-menu="pipelineMenuOpen = false"
      @set-pipeline="onSetPipeline"
      @set-view="onSetView"
      @create="onCreateDeal()"
      @export="onExport"
      @enter-bulk="salesStore.enterBulkMode()"
    />

    <!-- Filter panel (inline, under toolbar) -->
    <DealsFilterOverlay
      v-if="filterOverlayVisible"
      :stages="currentStages"
      :users="ownerOptions"
      :tags="tagOptions"
      :filters="toOverlayFilters()"
      :hidden-stages="hiddenStages"
      @close="filterOverlayVisible = false"
      @apply="onFilterApply"
      @reset="onFilterReset"
      @toggle-hidden-stage="onToggleHiddenStage"
    />

    <!-- Board view -->
    <div v-if="salesStore.activeView === 'kanban'" class="deals-page__board-wrap">
      <DealsKanbanBoard
        :visible-columns="visibleColumns"
        :loading="boardLoading"
        @drop="onBoardDrop"
        @title-change="onCardTitleChange"
        @load-more="onLoadMore"
        @create="onCreateDeal()"
        @create-in-stage="onCreateDeal($event)"
      />
    </div>

    <!-- List view -->
    <div v-else-if="salesStore.activeView === 'list'" class="deals-page__list-wrap">
      <DealsListView
        :deals="deals"
        :loading="listLoading"
        :total="total"
        :per-page="perPage"
        :has-active-filters="hasActiveFilters()"
        :stages="currentStages"
        :kpi="kpi"
        :kpi-loading="kpiLoading"
        :sort-state="sortState"
        @page-change="onPageChange"
        @reset-filters="resetFilters"
        @create="onCreateDeal()"
        @sort="onSort"
      />
    </div>

    <!-- Bulk dialogs -->
    <BulkAssignDialog
      v-model="bulkAssignOpen"
      :deal-ids="salesStore.bulkSelection"
      @done="onBulkDone(t('sales.deals.page.bulk.assignOwnerSuccess', { n: salesStore.bulkSelection.length }))"
    />
    <BulkMoveStageDialog
      v-model="bulkMoveStageOpen"
      :deal-ids="salesStore.bulkSelection"
      :stages="currentStages"
      @done="onBulkDone(t('sales.deals.page.bulk.moveStageSuccess', { n: salesStore.bulkSelection.length }))"
    />
    <BulkEditFieldDialog
      v-model="bulkEditFieldOpen"
      :deal-ids="salesStore.bulkSelection"
      @done="onBulkDone(t('sales.deals.page.bulk.editFieldSuccess', { n: salesStore.bulkSelection.length }))"
    />
    <BulkTagDialog
      v-model="bulkTagOpen"
      :deal-ids="salesStore.bulkSelection"
      @done="onBulkDone(t('sales.deals.page.bulk.tagSuccess', { n: salesStore.bulkSelection.length }))"
    />
    <BulkAddTaskDialog
      v-model="bulkAddTaskOpen"
      :deal-ids="salesStore.bulkSelection"
      @done="onBulkDone(t('sales.deals.page.bulk.addTaskSuccess', { n: salesStore.bulkSelection.length }))"
    />

    <!-- Move deal dialog (list view action) -->
    <MoveDealDialog
      v-if="movingDeal"
      v-model="moveDialogOpen"
      :deal="movingDeal"
      :stages="currentStages"
      :lost-reasons="salesStore.lostReasonsCache"
      @moved="onDealMoved"
    />

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import DealsKanbanBoard from './components/DealsKanbanBoard.vue'
import DealsListView from './components/DealsListView.vue'
import DealsToolbar from './components/DealsToolbar.vue'
import DealsBulkToolbar from './components/DealsBulkToolbar.vue'
import DealsFilterOverlay from './components/DealsFilterOverlay.vue'
import MoveDealDialog from './components/MoveDealDialog.vue'
import BulkAssignDialog from './components/BulkAssignDialog.vue'
import BulkMoveStageDialog from './components/BulkMoveStageDialog.vue'
import BulkEditFieldDialog from './components/BulkEditFieldDialog.vue'
import BulkTagDialog from './components/BulkTagDialog.vue'
import BulkAddTaskDialog from './components/BulkAddTaskDialog.vue'
import { useDealsFilters } from './composables/useDealsFilters'
import { useDealsBoard } from './composables/useDealsBoard'
import { useDealsList } from './composables/useDealsList'
import { useDealsKpi } from './composables/useDealsKpi'
import { useSalesStore } from '@/stores/salesStore'
import { useDirectoriesStore } from '@/stores/directories'
import { salesApi } from '@/api/sales'
import { usersApi } from '@/api/users'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import { formatCurrency } from '@/utils/currency'
import type { PipelineDto, DealDto, DealCardDto, PipelineStageDto, UserRefDto } from '@/entities/sales'
import type { OverlayFilters } from './components/DealsFilterOverlay.vue'
import type { DealsView } from '@/stores/salesStore'
import { useUserStore } from '@/stores/user'
import { useDealsListRealtime } from '@/composables/realtime/useDealsListRealtime'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()
const salesStore = useSalesStore()
const directoriesStore = useDirectoriesStore()
const userStore = useUserStore()

// ── Filter overlay ─────────────────────────────────────────────────────────────

const filterOverlayVisible = ref(false)
const pipelineMenuOpen = ref(false)

// ── Pipeline ────────────────────────────────────────────────────────────────────

const pipelinesResource = useAsyncResource<PipelineDto[]>(() => [])
const pipelines = computed(() => pipelinesResource.data.value)

// ── Filter option sources (owners + tags) ────────────────────────────────────────

// Owner MultiSelect options — visible users mapped to the {id, name} shape the
// overlay binds (option-label="name"). Mirrors DealPage's usersApi mapping.
const ownersResource = useAsyncResource<UserRefDto[]>(() => [])
const ownerOptions = computed(() => ownersResource.data.value)

// Tag checklist options — distinct tags drawn from the currently-loaded deals
// (board cards + list rows). There is no tags endpoint; the set is data-driven
// so it always reflects tags that actually exist on visible deals.
const tagOptions = computed<string[]>(() => {
  const set = new Set<string>()
  for (const col of boardComposable.localColumns.value) {
    for (const card of col.deals) {
      for (const tag of card.tags ?? []) set.add(tag)
    }
  }
  for (const deal of deals.value) {
    for (const tag of deal.tags ?? []) set.add(tag)
  }
  return Array.from(set).sort((a, b) => a.localeCompare(b))
})

const currentPipelineId = computed(() => {
  if (salesStore.activePipelineId) return salesStore.activePipelineId
  return pipelines.value[0]?.id ?? null
})

const activePipelineName = computed(() => {
  const pid = currentPipelineId.value
  return pipelines.value.find((p) => p.id === pid)?.name ?? t('sales.deals.page.toolbar.pipeline')
})

const currentStages = computed<PipelineStageDto[]>(() => {
  const pid = currentPipelineId.value
  if (!pid) return []
  return salesStore.getCachedStages(pid)
})

// ── Filters ─────────────────────────────────────────────────────────────────────

const {
  filters,
  resetFilters,
  hasActiveFilters,
  applyOverlayFilters,
  toOverlayFilters,
  activeFilterCount,
} = useDealsFilters(() => {
  // KPI is funnel-wide — always reload regardless of view
  void kpiComposable.load()
  if (salesStore.activeView === 'kanban') {
    void boardComposable.load()
  } else if (salesStore.activeView === 'list') {
    listFilters.resetPage()
    void listComposable.load()
  }
})

function onFilterApply(overlayFilters: OverlayFilters) {
  applyOverlayFilters(overlayFilters)
  filterOverlayVisible.value = false
}

function onFilterReset() {
  resetFilters()
}

// ── Pipeline switch ────────────────────────────────────────────────────────────

function onSetPipeline(id: number) {
  salesStore.setActivePipeline(id)
  // Reset revealed set when switching pipelines — stage IDs are not portable
  salesStore.resetRevealedStages()
  pipelineMenuOpen.value = false
  const pipeline = pipelines.value.find((p) => p.id === id)
  if (pipeline?.stages) {
    salesStore.cacheStages(id, pipeline.stages)
  }
  void reload()
}

// ── View toggle ────────────────────────────────────────────────────────────────

function onSetView(view: DealsView) {
  salesStore.setActiveView(view)
  void router.replace({ query: { ...route.query, view } })

  if (view === 'kanban') {
    void boardComposable.load()
  } else if (view === 'list') {
    listFilters.resetPage()
    void listComposable.load()
  }
}

// Sync URL
onMounted(() => {
  const urlView = route.query.view as string
  if (urlView === 'list' || urlView === 'kanban') {
    salesStore.setActiveView(urlView as DealsView)
  }
})

/**
 * Apply deep-link query params (e.g. from the dashboard "сделки без задач" widget:
 * /deals?pipeline_id=X&only_no_task=1). Must run AFTER pipelines load so the
 * target pipeline's stages are cached; the subsequent reload() then fetches the
 * pre-filtered list. Overrides the default pipeline selection when a valid
 * pipeline_id is supplied.
 */
function applyDeepLinkQuery(): void {
  const rawPipelineId = route.query.pipeline_id
  const pipelineId = Array.isArray(rawPipelineId) ? rawPipelineId[0] : rawPipelineId
  if (pipelineId != null && pipelineId !== '') {
    const pid = Number(pipelineId)
    if (Number.isFinite(pid) && pipelines.value.some((p) => p.id === pid)) {
      salesStore.setActivePipeline(pid)
      salesStore.resetRevealedStages()
      const pipeline = pipelines.value.find((p) => p.id === pid)
      if (pipeline?.stages) {
        salesStore.cacheStages(pid, pipeline.stages)
      }
    }
  }

  const rawNoTask = route.query.only_no_task
  const noTask = Array.isArray(rawNoTask) ? rawNoTask[0] : rawNoTask
  if (noTask === '1' || noTask === 'true') {
    filters.value.only_no_task = true
  }
}

watch(
  () => salesStore.activeView,
  (view) => {
    void router.replace({ query: { ...route.query, view } })
  },
)

// ── Board composable ────────────────────────────────────────────────────────────

const boardComposable = useDealsBoard(
  filters,
  () => currentPipelineId.value,
)
const {
  visibleColumns,
  hiddenStages,
  loading: boardLoading,
  moveDeal,
  updateCardTitle,
  toggleHiddenStage,
  loadMoreInColumn,
} = boardComposable

/**
 * Toggle a hidden stage and refetch the board so the revealed column appears
 * at its correct sort_order position (backend controls the column set).
 */
async function onToggleHiddenStage(stageId: number) {
  toggleHiddenStage(stageId)
  void boardComposable.load()
}

// ── Summary (counts + sum) ─────────────────────────────────────────────────────

const totalDealsCount = computed(() => {
  // Exclude won/lost columns — only active pipeline stages count.
  return visibleColumns.value
    .filter((col) => !col.stage.is_won && !col.stage.is_lost)
    .reduce((s, col) => s + col.total, 0)
})

/**
 * Toolbar funnel total. Money is shown via formatCurrency (kopecks → "1 200 000 ₽"
 * with the real currency symbol), never with hardcoded ₽/млн/тыс. literals.
 *
 * Aggregate the NATIVE per-currency buckets (amounts_by_currency) across the
 * ACTIVE (non-won, non-lost) columns rather than summing all columns blindly.
 *   - single currency  → one formatted figure in that currency;
 *   - multiple currencies, all rates available → the base-currency converted
 *     total (sum_amount already in base currency, all columns converted cleanly);
 *   - multiple currencies with a missing rate → a per-currency breakdown joined
 *     by " + " (no fabricated single-currency sum).
 */
const totalSumFormatted = computed(() => {
  // Exclude won/lost columns — only active pipeline stages contribute.
  const cols = visibleColumns.value.filter((col) => !col.stage.is_won && !col.stage.is_lost)
  if (cols.length === 0) return formatCurrency(0, 'RUB')

  // Native per-currency totals across all visible columns.
  const byCurrency: Record<string, number> = {}
  for (const col of cols) {
    for (const [cur, kop] of Object.entries(col.amounts_by_currency ?? {})) {
      byCurrency[cur] = (byCurrency[cur] ?? 0) + kop
    }
  }
  const currencies = Object.keys(byCurrency)
  const baseCurrency = cols[0]?.base_currency ?? 'RUB'

  // Single currency: format that bucket directly (exact, no FX involved).
  if (currencies.length <= 1) {
    const cur = currencies[0] ?? baseCurrency
    return formatCurrency(byCurrency[cur] ?? 0, cur)
  }

  // Multiple currencies. If every column converted cleanly, the base-currency
  // sum_amount total is exact → show it in the base currency with a "≈" prefix.
  const allRatesAvailable = cols.every((col) => col.fx_rate_available !== false)
  if (allRatesAvailable) {
    const baseKopecks = cols.reduce((s, col) => s + col.sum_amount, 0)
    return `≈ ${formatCurrency(baseKopecks, baseCurrency)}`
  }

  // A rate is missing → do not fabricate a single sum; list native subtotals.
  return currencies
    .map((cur) => formatCurrency(byCurrency[cur] ?? 0, cur))
    .join(' + ')
})

// ── List composable ─────────────────────────────────────────────────────────────

const listComposable = useDealsList(filters, () => currentPipelineId.value)
const listFilters = listComposable
const {
  deals,
  total,
  loading: listLoading,
  perPage,
  sortState,
  onPageChange,
  onSort,
} = listComposable

// ── KPI composable (whole-funnel aggregate) ─────────────────────────────────────

const kpiComposable = useDealsKpi(filters, () => currentPipelineId.value)
const { kpi, loading: kpiLoading } = kpiComposable

// ── Create deal — route to full card ───────────────────────────────────────────

function onCreateDeal(stageId?: number) {
  const query: Record<string, string> = {}
  if (currentPipelineId.value) query.pipeline_id = String(currentPipelineId.value)
  if (stageId) query.stage_id = String(stageId)
  void router.push({ path: '/deals/new', query })
}

// ── Move dialog ─────────────────────────────────────────────────────────────────

const moveDialogOpen = ref(false)
const movingDeal = ref<DealDto | null>(null)

function openMoveDialog(deal: DealDto) {
  movingDeal.value = deal
  moveDialogOpen.value = true
}

function onDealMoved() {
  void reload()
}

// ── Board drag-and-drop ─────────────────────────────────────────────────────────

async function onBoardDrop(card: DealCardDto, fromStageId: number, toStageId: number) {
  const toStage = currentStages.value.find((s) => s.id === toStageId)

  if (toStage?.is_lost) {
    try {
      const deal = await salesApi.getDeal(card.id)
      openMoveDialog(deal)
    } catch {
      toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
    }
    void boardComposable.load()
    return
  }

  try {
    const result = await moveDeal(card, fromStageId, toStageId, {
      to_stage_id: toStageId,
    })

    if (result.won_gate_warning) {
      toast.add({
        severity: 'warn',
        summary: t('sales.move.dialog.wonGateWarningToast'),
        life: 5000,
      })
    }
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

async function onCardTitleChange(cardId: number, title: string) {
  try {
    await updateCardTitle(cardId, title)
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 3000,
    })
  }
}

async function onLoadMore(stageId: number) {
  try {
    await loadMoreInColumn(stageId)
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 3000,
    })
  }
}

// ── Delete: individual deal deletion is handled from DealPage (detail view) ───────

// ── Bulk dialogs visibility ────────────────────────────────────────────────────

const bulkAssignOpen = ref(false)
const bulkMoveStageOpen = ref(false)
const bulkEditFieldOpen = ref(false)
const bulkTagOpen = ref(false)
const bulkAddTaskOpen = ref(false)

// ── Computed: all visible deal IDs for select-all ─────────────────────────────

const allVisibleDealIds = computed<number[]>(() => {
  if (salesStore.activeView === 'kanban') {
    return boardComposable.localColumns.value.flatMap((col) => col.deals.map((d) => d.id))
  }
  if (salesStore.activeView === 'list') {
    return deals.value.map((d) => d.id)
  }
  return []
})

// ── Bulk action handlers ───────────────────────────────────────────────────────

function onBulkAssignOwner() {
  if (salesStore.bulkSelection.length === 0) return
  bulkAssignOpen.value = true
}

function onBulkAddTask() {
  if (salesStore.bulkSelection.length === 0) return
  bulkAddTaskOpen.value = true
}

function onBulkMoveStage() {
  if (salesStore.bulkSelection.length === 0) return
  bulkMoveStageOpen.value = true
}

function onBulkEditField() {
  if (salesStore.bulkSelection.length === 0) return
  bulkEditFieldOpen.value = true
}

function onBulkEditTags() {
  if (salesStore.bulkSelection.length === 0) return
  bulkTagOpen.value = true
}

function onBulkDelete() {
  if (salesStore.bulkSelection.length === 0) return
  const n = salesStore.bulkSelection.length
  confirm.require({
    header: t('sales.deals.page.bulk.deleteConfirm', { n }),
    message: t('sales.deals.page.bulk.deleteDetail'),
    acceptLabel: t('sales.deals.page.bulk.delete'),
    rejectLabel: t('sales.deals.page.bulk.cancel'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await salesApi.bulkDeleteDeals({ deal_ids: salesStore.bulkSelection })
        toast.add({
          severity: 'success',
          summary: t('sales.deals.page.bulk.deleteSuccess', { n }),
          life: 3000,
        })
        salesStore.exitBulkMode()
        void reload()
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: getApiErrorMessage(err, t('errors.server_error')),
          life: 4000,
        })
      }
    },
  })
}

function onBulkDone(successMessage: string) {
  toast.add({ severity: 'success', summary: successMessage, life: 3000 })
  salesStore.exitBulkMode()
  void reload()
}

// ── Export ─────────────────────────────────────────────────────────────────────

const exportMutation = useMutation<Blob>()

async function onExport() {
  try {
    const f = filters.value
    const blob = await exportMutation.run(() =>
      salesApi.exportDeals({
        pipeline_id: currentPipelineId.value ?? undefined,
        q: f.q || null,
        owner_id: f.owner_id,
        stage_id: f.stage_id,
      }),
    )
    const url = URL.createObjectURL(blob)
    const a = document.createElement('a')
    a.href = url
    a.download = `deals-${new Date().toISOString().slice(0, 10)}.xlsx`
    a.click()
    URL.revokeObjectURL(url)
  } catch (err) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

// ── Bootstrap ──────────────────────────────────────────────────────────────────

async function reload() {
  // KPI is funnel-wide — always reload alongside the view-specific query
  void kpiComposable.load()
  if (salesStore.activeView === 'kanban') {
    void boardComposable.load()
  } else if (salesStore.activeView === 'list') {
    void listComposable.load()
  }
}

onMounted(async () => {
  // Ensure directories are loaded so the country filter Select has options
  if (!directoriesStore.loaded) {
    void directoriesStore.fetchAll()
  }

  // Owner filter options — load once; maps full_name → name for the MultiSelect.
  void ownersResource.run(() =>
    usersApi.getUsers().then((users) =>
      users.map((u) => ({ id: u.id, name: u.full_name, avatar_path: u.avatar_path })),
    ),
  )

  // Load pipelines
  await pipelinesResource.run(() => salesApi.getPipelines('sales'), {
    commit: (result) => {
      pipelinesResource.data.value = result
      const first = result[0]
      if (first && !salesStore.activePipelineId) {
        salesStore.setActivePipeline(first.id)
        if (first.stages) {
          salesStore.cacheStages(first.id, first.stages)
        }
      }
    },
  })

  // Apply deep-link query params (pipeline_id + only_no_task) — must run after
  // pipelines load so the target pipeline + its stages resolve. Overrides the
  // default pipeline selection above when a valid pipeline_id is supplied.
  applyDeepLinkQuery()

  // Load lost reasons into store cache
  try {
    const reasons = await salesApi.getLostReasons()
    salesStore.cacheLostReasons(reasons)
  } catch {
    // Non-critical
  }

  // ── Realtime: subscribe to live board events ─────────────────────────────────
  useDealsListRealtime(
    () => userStore.getUser?.department_id ?? null,
    () => { void reload() },
  )

  // Load deals
  await reload()
})
</script>

<style lang="scss" scoped>
.deals-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
  background: var(--p-surface-100);

  .app-dark & {
    background: var(--p-surface-50);
  }
}

.deals-page__board-wrap {
  // F4: use min-height:0 so flex shrinking doesn't collapse this container
  // when the filter panel opens. The board itself overflows and scrolls
  // internally — cards keep their natural shape at all times.
  flex: 1;
  min-height: 0;
  overflow: auto;
  padding: $space-4 $space-5;
  display: flex;
  flex-direction: column;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

.deals-page__list-wrap {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  padding: $space-4 $space-5;
}
</style>
