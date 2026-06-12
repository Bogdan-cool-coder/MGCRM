<template>
  <div class="deals-page">
    <PageHeader
      :title="t('sales.deals.page.title')"
      :subtitle="t('sales.deals.page.subtitle')"
      icon="pi pi-briefcase"
    >
      <template #actions>
        <SelectButton
          v-model="salesStore.activeView"
          :options="viewOptions"
          option-label="label"
          option-value="value"
          class="deals-page__view-toggle"
        />
        <Button
          icon="pi pi-plus"
          :label="t('sales.deals.page.create')"
          @click="createDrawerOpen = true"
        />
      </template>
    </PageHeader>

    <!-- Filters -->
    <DealsFilterPanel
      :filters="filters"
      :stages="currentStages"
      :show-stage-filter="salesStore.activeView === 'list'"
      :users="[]"
      @update:filters="onFiltersUpdate"
      @search-input="onSearchInput"
      @filter-select="onFilterSelect"
      @reset="resetFilters"
    />

    <!-- Board view -->
    <div v-if="salesStore.activeView === 'board'" class="deals-page__board-wrap">
      <DealsKanbanBoard
        :visible-columns="visibleColumns"
        :hidden-columns="hiddenColumns"
        :loading="boardLoading"
        @drop="onBoardDrop"
        @title-change="onCardTitleChange"
        @load-more="onLoadMore"
        @show-hidden="toggleHiddenStage"
        @create="createDrawerOpen = true"
      />
    </div>

    <!-- List view -->
    <div v-else class="deals-page__list-wrap">
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
      @created="onDealCreated"
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
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import DealsKanbanBoard from './components/DealsKanbanBoard.vue'
import DealsListView from './components/DealsListView.vue'
import DealsFilterPanel from './components/DealsFilterPanel.vue'
import DealCreateDrawer from './components/DealCreateDrawer.vue'
import MoveDealDialog from './components/MoveDealDialog.vue'
import { useDealsFilters, type DealsFilters } from './composables/useDealsFilters'
import { useDealsBoard } from './composables/useDealsBoard'
import { useDealsList } from './composables/useDealsList'
import { useSalesStore } from '@/stores/salesStore'
import { salesApi } from '@/api/sales'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import type { PipelineDto, DealDto, DealCardDto, PipelineStageDto } from '@/entities/sales'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const toast = useToast()
const confirm = useConfirm()
const salesStore = useSalesStore()

// ── View toggle ────────────────────────────────────────────────────────────────

const viewOptions = [
  { label: t('sales.deals.page.viewBoard'), value: 'board' },
  { label: t('sales.deals.page.viewList'), value: 'list' },
]

// Sync activeView with URL param ?view=
onMounted(() => {
  const urlView = route.query.view as string
  if (urlView === 'list' || urlView === 'board') {
    salesStore.setActiveView(urlView)
  }
})

watch(
  () => salesStore.activeView,
  (view) => {
    void router.replace({ query: { ...route.query, view } })
    if (view === 'list') {
      listFilters.resetPage()
      void listComposable.load()
    } else {
      void boardComposable.load()
    }
  },
)

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

const { filters, onSearchInput, onFilterSelect, resetFilters, hasActiveFilters } = useDealsFilters(
  () => {
    if (salesStore.activeView === 'board') {
      void boardComposable.load()
    } else {
      listFilters.resetPage()
      void listComposable.load()
    }
  },
)

function onFiltersUpdate(v: DealsFilters) {
  filters.value = v
}

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

function onDealCreated() {
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

  // If dropping into lost stage — show move dialog instead
  if (toStage?.is_lost) {
    // We need the full deal to open MoveDealDialog
    // Fetch minimal deal on demand
    try {
      const deal = await salesApi.getDeal(card.id)
      openMoveDialog(deal)
    } catch {
      toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
    }
    // Rollback the optimistic move since we're using dialog instead
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

// ── Bootstrap ──────────────────────────────────────────────────────────────────

async function reload() {
  if (salesStore.activeView === 'board') {
    void boardComposable.load()
  } else {
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

.deals-page__view-toggle {
  // SelectButton needs no extra styling
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
