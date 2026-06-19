<template>
  <div class="deals-page">
    <PageHeader
      :title="t('sales.deals.page.title')"
      :subtitle="pageSubtitle"
      icon="pi pi-briefcase"
    />

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

    <!-- Main toolbar (amo-style one-liner) -->
    <DealsToolbar
      v-else
      :active-view="salesStore.activeView"
      :total-deals="totalDealsCount"
      :total-sum="totalSumFormatted"
      :active-sort="salesStore.boardSort"
      @open-filter="filterOverlayVisible = true"
      @set-view="onSetView"
      @create="createDrawerOpen = true"
      @export="onExport"
      @enter-bulk="salesStore.enterBulkMode()"
      @set-sort="onSetSort"
    />

    <!-- Filter overlay -->
    <DealsFilterOverlay
      :visible="filterOverlayVisible"
      :stages="currentStages"
      :users="[]"
      :tags="[]"
      :filters="toOverlayFilters()"
      @close="filterOverlayVisible = false"
      @apply="onFilterApply"
      @reset="onFilterReset"
    />

    <!-- Board view -->
    <div v-if="salesStore.activeView === 'kanban'" class="deals-page__board-wrap">
      <DealsKanbanBoard
        :visible-columns="visibleColumns"
        :hidden-columns="hiddenColumns"
        :loading="boardLoading"
        @drop="onBoardDrop"
        @title-change="onCardTitleChange"
        @load-more="onLoadMore"
        @show-hidden="toggleHiddenStage"
        @create="createDrawerOpen = true"
        @add-deal-to-stage="onAddDealToStage"
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
        @page-change="onPageChange"
        @reset-filters="resetFilters"
        @create="createDrawerOpen = true"
        @change-stage="openMoveDialog"
        @delete="confirmDelete"
      />
    </div>

    <!-- Create deal drawer -->
    <DealCreateDrawer
      v-model="createDrawerOpen"
      :pipelines="pipelines"
      :initial-stage-id="createDrawerStageId"
      @created="onDealCreated"
    />

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
import { ref, computed, onMounted, onUnmounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute, useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import DealsKanbanBoard from './components/DealsKanbanBoard.vue'
import DealsListView from './components/DealsListView.vue'
import DealsToolbar from './components/DealsToolbar.vue'
import DealsBulkToolbar from './components/DealsBulkToolbar.vue'
import DealsFilterOverlay from './components/DealsFilterOverlay.vue'
import DealCreateDrawer from './components/DealCreateDrawer.vue'
import MoveDealDialog from './components/MoveDealDialog.vue'
import BulkAssignDialog from './components/BulkAssignDialog.vue'
import BulkMoveStageDialog from './components/BulkMoveStageDialog.vue'
import BulkEditFieldDialog from './components/BulkEditFieldDialog.vue'
import BulkTagDialog from './components/BulkTagDialog.vue'
import BulkAddTaskDialog from './components/BulkAddTaskDialog.vue'
import { useDealsFilters } from './composables/useDealsFilters'
import { useDealsBoard } from './composables/useDealsBoard'
import { useDealsList } from './composables/useDealsList'
import { useSalesStore } from '@/stores/salesStore'
import { useUiTriggersStore } from '@/stores/uiTriggers'
import { salesApi } from '@/api/sales'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import type { PipelineDto, DealDto, DealCardDto, PipelineStageDto } from '@/entities/sales'
import type { OverlayFilters } from './components/DealsFilterOverlay.vue'
import type { DealsView, BoardSort } from '@/stores/salesStore'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()
const salesStore = useSalesStore()
const uiTriggers = useUiTriggersStore()

// ── Filter overlay ─────────────────────────────────────────────────────────────

const filterOverlayVisible = ref(false)

// ── Pipeline ────────────────────────────────────────────────────────────────────

const pipelinesResource = useAsyncResource<PipelineDto[]>(() => [])
const pipelines = computed(() => pipelinesResource.data.value)

const currentPipelineId = computed(() => {
  if (salesStore.activePipelineId) return salesStore.activePipelineId
  return pipelines.value[0]?.id ?? null
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
} = useDealsFilters(() => {
  if (salesStore.activeView === 'kanban') {
    void boardComposable.load()
  } else if (salesStore.activeView === 'list') {
    listFilters.resetPage()
    void listComposable.load()
  }
})

function onFilterApply(overlayFilters: OverlayFilters) {
  applyOverlayFilters(overlayFilters)
}

function onFilterReset() {
  resetFilters()
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

function onSetSort(sort: BoardSort) {
  salesStore.setBoardSort(sort)
  void boardComposable.load()
}

// Sync URL
onMounted(() => {
  const urlView = route.query.view as string
  if (urlView === 'list' || urlView === 'kanban') {
    salesStore.setActiveView(urlView as DealsView)
  }
})

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
  hiddenColumns,
  loading: boardLoading,
  moveDeal,
  updateCardTitle,
  toggleHiddenStage,
  loadMoreInColumn,
} = boardComposable

// ── Summary (counts + sum) ─────────────────────────────────────────────────────

const totalDealsCount = computed(() => {
  return visibleColumns.value.reduce((s, col) => s + col.total, 0)
})

const totalSumFormatted = computed(() => {
  const totalKopecks = visibleColumns.value.reduce((s, col) => s + col.sum_amount, 0)
  const rub = totalKopecks / 100
  const sign = '₽'
  if (rub >= 1_000_000) return `${(rub / 1_000_000).toFixed(1)} млн ${sign}`
  if (rub >= 1_000) return `${Math.round(rub / 1_000)} тыс. ${sign}`
  return `${Math.round(rub)} ${sign}`
})

const pageSubtitle = computed(() => {
  const pipeline = boardComposable.pipeline.value
  const name = pipeline?.name ?? ''
  return `${name} · ${totalDealsCount.value} сделок · ≈ ${totalSumFormatted.value}`
})

// ── List composable ─────────────────────────────────────────────────────────────

const listComposable = useDealsList(filters, () => currentPipelineId.value)
const listFilters = listComposable
const {
  deals,
  total,
  loading: listLoading,
  perPage,
  onPageChange,
} = listComposable

// ── Create drawer ───────────────────────────────────────────────────────────────

const createDrawerOpen = ref(false)
const createDrawerStageId = ref<number | null>(null)

function onAddDealToStage(stageId: number) {
  createDrawerStageId.value = stageId
  createDrawerOpen.value = true
}

function onDealCreated() {
  createDrawerStageId.value = null
  void reload()
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

// ── Delete ─────────────────────────────────────────────────────────────────────

const deleteMutation = useMutation()

function confirmDelete(deal: DealDto) {
  confirm.require({
    header: t('sales.deals.page.actions.deleteConfirm'),
    message: t('sales.deals.page.actions.deleteDetail'),
    acceptLabel: t('sales.deals.page.actions.deleteAccept'),
    rejectLabel: t('sales.deals.page.actions.deleteReject'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await deleteMutation.run(() => salesApi.deleteDeal(deal.id))
        toast.add({ severity: 'success', summary: t('sales.deals.page.actions.deleteSuccess'), life: 3000 })
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

// ── Bulk dialogs visibility ────────────────────────────────────────────────────

const bulkAssignOpen = ref(false)
const bulkMoveStageOpen = ref(false)
const bulkEditFieldOpen = ref(false)
const bulkTagOpen = ref(false)
const bulkAddTaskOpen = ref(false)

// ── Computed: all visible deal IDs for select-all ─────────────────────────────

const allVisibleDealIds = computed<number[]>(() => {
  if (salesStore.activeView === 'kanban') {
    // Use all locally-loaded columns (including hidden ones) so that
    // "select all" captures every card currently rendered on screen.
    // visibleColumns already includes toggled-open hidden stages,
    // but hiddenColumns still holds the unopened ones; scanning
    // localColumns once is the simplest authoritative source.
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

// ── Global UI-trigger: open create drawer from QuickActionsCluster ─────────────

const stopDrawerTrigger = watch(
  () => uiTriggers.pendingDrawer,
  (trigger) => {
    if (trigger === 'deal_create') {
      createDrawerOpen.value = true
      uiTriggers.clearDrawer()
    }
  },
  { immediate: true },
)

onUnmounted(() => {
  stopDrawerTrigger()
})

// ── Bootstrap ──────────────────────────────────────────────────────────────────

async function reload() {
  if (salesStore.activeView === 'kanban') {
    void boardComposable.load()
  } else if (salesStore.activeView === 'list') {
    void listComposable.load()
  }
}

onMounted(async () => {
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

  // Load lost reasons into store cache
  try {
    const reasons = await salesApi.getLostReasons()
    salesStore.cacheLostReasons(reasons)
  } catch {
    // Non-critical
  }

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
}

.deals-page__board-wrap {
  flex: 1;
  overflow: hidden;
  padding: $space-4 $space-6;
  display: flex;
  flex-direction: column;
}

.deals-page__list-wrap {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  padding: $space-4 $space-6;
}

</style>
