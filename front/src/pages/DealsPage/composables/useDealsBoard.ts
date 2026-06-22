/**
 * Board (Kanban) composable for DealsPage.
 * Manages columns, optimistic drag-and-drop with rollback.
 */
import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { useSalesStore } from '@/stores/salesStore'
import type {
  BoardResponseDto,
  BoardColumnDto,
  DealCardDto,
  MoveDealPayload,
} from '@/entities/sales'
import type { DealsFilters } from './useDealsFilters'
import type { Ref } from 'vue'

export interface MoveDealResult {
  won_gate_warning: boolean
  isLostStage: boolean
  card: DealCardDto
  fromStageId: number
  toStageId: number
}

export function useDealsBoard(
  filters: Ref<DealsFilters>,
  pipelineId: () => number | null,
) {
  const salesStore = useSalesStore()

  const resource = useAsyncResource<BoardResponseDto | null>(() => null)
  const moveMutation = useMutation<unknown>()

  // Local mutable columns for optimistic UI
  const localColumns = ref<BoardColumnDto[]>([])

  // Which hidden columns are currently shown
  const visibleHiddenStageIds = ref<Set<number>>(new Set())

  const loading = computed(() => resource.loading.value)
  const error = computed(() => resource.error.value)
  const pipeline = computed(() => resource.data.value?.pipeline ?? null)

  const visibleColumns = computed(() => {
    return localColumns.value.filter(
      (col) => !col.stage.hidden_by_default || visibleHiddenStageIds.value.has(col.stage.id),
    )
  })

  const hiddenColumns = computed(() => {
    return localColumns.value.filter(
      (col) => col.stage.hidden_by_default && !visibleHiddenStageIds.value.has(col.stage.id),
    )
  })

  async function load() {
    const pid = pipelineId()
    const f = filters.value
    const dateRange = f.dateRange
    await resource.run(
      () =>
        salesApi.getDealsBoard({
          pipeline_id: pid ?? undefined,
          q: f.q || undefined,
          owner_ids: f.owner_ids.length ? f.owner_ids : undefined,
          stage_ids: f.stage_ids.length ? f.stage_ids : undefined,
          status: f.status ?? undefined,
          only_mine: f.only_mine || undefined,
          only_no_task: f.only_no_task || undefined,
          only_overdue: f.only_overdue || undefined,
          product_q: f.product_q || undefined,
          country: f.region || undefined,
          city: f.city || undefined,
          budget_from: f.budget_from ?? undefined,
          budget_to: f.budget_to ?? undefined,
          tags: f.tags.length ? f.tags : undefined,
          created_from: dateRange?.[0] ? dateRange[0].toISOString().slice(0, 10) : undefined,
          created_to: dateRange?.[1] ? dateRange[1].toISOString().slice(0, 10) : undefined,
        }),
      {
        commit: (result) => {
          if (!result) return
          resource.data.value = result
          // Deep-copy columns for local mutable state
          localColumns.value = result.columns.map((col) => ({
            ...col,
            deals: [...col.deals],
          }))
          // Cache stages extracted from columns (adapter embeds stage in each column)
          if (result.pipeline) {
            const stages = result.columns.map((col) => col.stage)
            salesStore.cacheStages(result.pipeline.id, stages)
          }
        },
      },
    )
  }

  function toggleHiddenStage(stageId: number) {
    const next = new Set(visibleHiddenStageIds.value)
    if (next.has(stageId)) {
      next.delete(stageId)
    } else {
      next.add(stageId)
    }
    visibleHiddenStageIds.value = next
  }

  /**
   * Append more cards to a column (pagination in kanban).
   */
  async function loadMoreInColumn(stageId: number) {
    const col = localColumns.value.find((c) => c.stage.id === stageId)
    if (!col) return

    const nextPage = Math.ceil(col.deals.length / 30) + 1
    const pid = pipelineId()

    const res = await salesApi.getDeals({
      view: 'list',
      pipeline_id: pid ?? undefined,
      stage_id: stageId,
      page: nextPage,
      per_page: 30,
    })
    // Map DealDto to DealCardDto shape
    const newCards: DealCardDto[] = res.data.map((d) => ({
      id: d.id,
      title: d.title,
      company: d.company,
      stage_id: d.stage.id,
      owner: d.owner,
      amount: d.amount,
      currency: d.currency,
      stage_changed_at: d.stage_changed_at,
      days_in_stage: null,
      next_task: null,
      primary_product: null,
    }))
    col.deals = [...col.deals, ...newCards]
  }

  /**
   * Optimistic move: immediately updates local columns, then calls API.
   * Rolls back on error.
   */
  async function moveDeal(
    card: DealCardDto,
    fromStageId: number,
    toStageId: number,
    payload: MoveDealPayload,
  ): Promise<MoveDealResult> {
    // Optimistic: remove from source column, add to target column
    const fromCol = localColumns.value.find((c) => c.stage.id === fromStageId)
    const toCol = localColumns.value.find((c) => c.stage.id === toStageId)

    const fromIndex = fromCol?.deals.findIndex((d) => d.id === card.id) ?? -1

    if (fromCol && fromIndex >= 0) {
      fromCol.deals.splice(fromIndex, 1)
      fromCol.total = Math.max(0, fromCol.total - 1)
    }

    if (toCol) {
      toCol.deals.unshift({ ...card, stage_id: toStageId })
      toCol.total += 1
    }

    try {
      const response = await moveMutation.run(() => salesApi.moveDeal(card.id, payload))
      const toStage = localColumns.value.find((c) => c.stage.id === toStageId)?.stage

      return {
        won_gate_warning: (response as unknown as { won_gate_warning?: boolean }).won_gate_warning ?? false,
        isLostStage: toStage?.is_lost ?? false,
        card,
        fromStageId,
        toStageId,
      }
    } catch (err) {
      // Rollback: put card back
      if (toCol) {
        const idx = toCol.deals.findIndex((d) => d.id === card.id)
        if (idx >= 0) {
          toCol.deals.splice(idx, 1)
          toCol.total = Math.max(0, toCol.total - 1)
        }
      }
      if (fromCol && fromIndex >= 0) {
        fromCol.deals.splice(fromIndex, 0, card)
        fromCol.total += 1
      }
      throw err
    }
  }

  /**
   * Inline-edit title on the kanban card.
   */
  async function updateCardTitle(cardId: number, title: string) {
    await salesApi.updateDeal(cardId, { title })
    for (const col of localColumns.value) {
      const card = col.deals.find((d) => d.id === cardId)
      if (card) {
        card.title = title
        break
      }
    }
  }

  return {
    loading,
    error,
    pipeline,
    localColumns,
    visibleColumns,
    hiddenColumns,
    visibleHiddenStageIds,
    load,
    loadMoreInColumn,
    moveDeal,
    updateCardTitle,
    toggleHiddenStage,
  }
}
