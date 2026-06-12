/**
 * Sales Pinia store — client state only.
 * Server-state (deals list, board) is in page composables via useAsyncResource.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import type { PipelineStageDto, LostReasonDto } from '@/entities/sales'

export const useSalesStore = defineStore('sales', () => {
  // Active pipeline selection
  const activePipelineId = ref<number | null>(null)

  // Kanban / List view preference
  const activeView = ref<'board' | 'list'>('board')

  // Stage cache per pipeline (populated on first board load)
  const stagesCache = ref<Map<number, PipelineStageDto[]>>(new Map())

  // Lost reasons cache (populated once)
  const lostReasonsCache = ref<LostReasonDto[]>([])

  // ─── Actions ──────────────────────────────────────────────────────────────

  function setActivePipeline(id: number | null) {
    activePipelineId.value = id
  }

  function setActiveView(view: 'board' | 'list') {
    activeView.value = view
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

  return {
    activePipelineId,
    activeView,
    stagesCache,
    lostReasonsCache,
    setActivePipeline,
    setActiveView,
    cacheStages,
    getCachedStages,
    cacheLostReasons,
  }
})
