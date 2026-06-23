/**
 * Sales Pinia store — client state only.
 * Server-state (deals list, board) is in page composables via useAsyncResource.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { PipelineStageDto, LostReasonDto } from '@/entities/sales'

export type DealsView = 'kanban' | 'list'
export type BoardSort = 'created_at_desc' | 'title_asc' | 'amount_desc' | 'last_activity_desc'

const DEALS_VIEW_KEY = 'deals_active_view'

export const useSalesStore = defineStore('sales', () => {
  // Active pipeline selection
  const activePipelineId = ref<number | null>(null)

  // Kanban / List view preference (persisted in localStorage)
  // Guard: 'tasks' was removed from DealsView — fall back to 'kanban'
  const _rawSavedView = localStorage.getItem(DEALS_VIEW_KEY)
  const _savedView: DealsView | null =
    _rawSavedView === 'kanban' || _rawSavedView === 'list' ? _rawSavedView : null
  const activeView = ref<DealsView>(_savedView ?? 'kanban')

  // Board sort order
  const boardSort = ref<BoardSort>('created_at_desc')

  // Bulk selection mode
  const bulkMode = ref(false)
  const bulkSelection = ref<number[]>([])

  /**
   * Revealed hidden stage IDs — in-memory only (intentionally NOT persisted).
   * Survives SPA navigation; resets on full page reload (per spec).
   */
  const revealedStageIds = ref<Set<number>>(new Set())

  // Stage cache per pipeline (populated on first board load)
  const stagesCache = ref<Map<number, PipelineStageDto[]>>(new Map())

  // Lost reasons cache (populated once)
  const lostReasonsCache = ref<LostReasonDto[]>([])

  // ─── Actions ──────────────────────────────────────────────────────────────

  function setActivePipeline(id: number | null) {
    activePipelineId.value = id
  }

  function setActiveView(view: DealsView) {
    activeView.value = view
    localStorage.setItem(DEALS_VIEW_KEY, view)
  }

  function setBoardSort(sort: BoardSort) {
    boardSort.value = sort
  }

  function enterBulkMode() {
    bulkMode.value = true
    bulkSelection.value = []
  }

  function exitBulkMode() {
    bulkMode.value = false
    bulkSelection.value = []
  }

  function toggleBulkItem(id: number) {
    const idx = bulkSelection.value.indexOf(id)
    if (idx >= 0) {
      bulkSelection.value.splice(idx, 1)
    } else {
      bulkSelection.value.push(id)
    }
  }

  function selectAllBulk(ids: number[]) {
    bulkSelection.value = [...ids]
  }

  function clearBulkSelection() {
    bulkSelection.value = []
  }

  function cacheStages(pipelineId: number, stages: PipelineStageDto[]) {
    stagesCache.value = new Map(stagesCache.value).set(pipelineId, stages)
  }

  function getCachedStages(pipelineId: number): PipelineStageDto[] {
    return stagesCache.value.get(pipelineId) ?? []
  }

  function cacheLostReasons(reasons: LostReasonDto[]) {
    lostReasonsCache.value = reasons
  }

  function toggleRevealedStage(stageId: number) {
    const next = new Set(revealedStageIds.value)
    if (next.has(stageId)) {
      next.delete(stageId)
    } else {
      next.add(stageId)
    }
    revealedStageIds.value = next
  }

  function resetRevealedStages() {
    revealedStageIds.value = new Set()
  }

  function invalidateStagesCache(pipelineId?: number) {
    if (pipelineId != null) {
      const m = new Map(stagesCache.value)
      m.delete(pipelineId)
      stagesCache.value = m
    } else {
      stagesCache.value = new Map()
    }
  }

  return {
    activePipelineId,
    activeView,
    boardSort,
    bulkMode,
    bulkSelection,
    stagesCache,
    lostReasonsCache,
    revealedStageIds,
    setActivePipeline,
    setActiveView,
    setBoardSort,
    enterBulkMode,
    exitBulkMode,
    toggleBulkItem,
    selectAllBulk,
    clearBulkSelection,
    cacheStages,
    getCachedStages,
    cacheLostReasons,
    invalidateStagesCache,
    toggleRevealedStage,
    resetRevealedStages,
  }
})
