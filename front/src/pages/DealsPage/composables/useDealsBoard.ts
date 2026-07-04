/**
 * Board (Kanban) composable for DealsPage.
 * Manages columns, optimistic drag-and-drop with rollback.
 *
 * Revealed-stage state lives in salesStore (in-memory, survives SPA navigation,
 * resets on full page reload — no localStorage).
 */
import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { toKopecks } from '@/utils/currency'
import { useSalesStore } from '@/stores/salesStore'
import type {
  BoardResponseDto,
  BoardColumnDto,
  DealCardDto,
  HiddenStageDto,
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

  // Hidden stages metadata from the backend (all hidden stages, regardless of revealed set)
  const hiddenStagesMeta = ref<HiddenStageDto[]>([])

  const loading = computed(() => resource.loading.value)
  const error = computed(() => resource.error.value)
  const pipeline = computed(() => resource.data.value?.pipeline ?? null)

  /**
   * Visible columns: non-hidden stages + any revealed hidden stages.
   * The board only shows columns the backend sent (it already filters by
   * revealed_stage_ids), so all localColumns are "visible". We still derive
   * this for backward-compatible usage in the parent.
   */
  const visibleColumns = computed(() => localColumns.value)

  /**
   * Hidden columns: derived from hiddenStagesMeta — those NOT in revealedStageIds.
   * Used for count/display in the filter overlay.
   */
  const hiddenStages = computed<HiddenStageDto[]>(() => hiddenStagesMeta.value)

  function buildBoardQuery() {
    const pid = pipelineId()
    const f = filters.value
    const dateRange = f.dateRange
    const revealedIds = Array.from(salesStore.revealedStageIds)
    return {
      pipeline_id: pid ?? undefined,
      q: f.q || undefined,
      owner_ids: f.owner_ids.length ? f.owner_ids : undefined,
      stage_ids: f.stage_ids.length ? f.stage_ids : undefined,
      status: f.status ?? undefined,
      only_mine: f.only_mine || undefined,
      only_no_task: f.only_no_task || undefined,
      only_overdue: f.only_overdue || undefined,
      product_q: f.product_q || undefined,
      // countries[] takes precedence over single country when non-empty (backend whereIn).
      countries: f.countries.length ? f.countries : undefined,
      country: f.countries.length ? undefined : (f.country || undefined),
      city: f.city || undefined,
      // Budget inputs are in rubles (UI suffix " ₽"); the API compares
      // against deals.amount in kopecks → convert before sending (×100).
      budget_from: f.budget_from != null ? toKopecks(f.budget_from) : undefined,
      budget_to: f.budget_to != null ? toKopecks(f.budget_to) : undefined,
      tags: f.tags.length ? f.tags : undefined,
      created_from: dateRange?.[0] ? dateRange[0].toISOString().slice(0, 10) : undefined,
      created_to: dateRange?.[1] ? dateRange[1].toISOString().slice(0, 10) : undefined,
      revealed_stage_ids: revealedIds.length ? revealedIds : undefined,
    }
  }

  // Per-column pagination cursor (stage id → last-loaded page). Reset on every
  // board (re)load: page 1 is what the board endpoint returns. loadMoreInColumn
  // increments from this cursor rather than deriving a page from deals.length,
  // which is corrupted by optimistic cross-column moves (the length is no longer
  // a clean multiple of the page size → wrong / skipped pages).
  const columnPage = new Map<number, number>()

  function commitBoard(result: BoardResponseDto | null) {
    if (!result) return
    resource.data.value = result
    // Deep-copy columns for local mutable state
    localColumns.value = result.columns.map((col) => ({
      ...col,
      deals: [...col.deals],
    }))
    columnPage.clear()
    // Store hidden stages metadata from response
    hiddenStagesMeta.value = result.hidden_stages ?? []
    // Cache stages extracted from columns
    if (result.pipeline) {
      const stages = result.columns.map((col) => col.stage)
      salesStore.cacheStages(result.pipeline.id, stages)
    }
  }

  async function load() {
    await resource.run(() => salesApi.getDealsBoard(buildBoardQuery()), {
      commit: commitBoard,
    })
  }

  // Silent request gate for background refetches: last-wins without touching the
  // shared resource's `loading` flag (so the board is NOT replaced by skeletons).
  let silentToken = 0

  /**
   * Background refetch used by realtime events. Data is swapped in only when
   * ready — no skeleton flash, no scroll reset, no drag interruption. Keeps
   * last-wins semantics via its own token so a slow in-flight response can't
   * clobber a fresher one. Skips entirely while a drag is active OR when the
   * board has never been populated (first paint still shows the real skeleton
   * through load()).
   */
  async function loadSilent() {
    if (localColumns.value.length === 0) {
      // Nothing on screen yet → fall back to the normal (skeleton) load so the
      // user sees a loading state on first paint rather than a blank board.
      await load()
      return
    }
    const token = ++silentToken
    try {
      const result = await salesApi.getDealsBoard(buildBoardQuery())
      if (token !== silentToken) return
      commitBoard(result)
    } catch {
      // non-critical: keep the current board on a failed background refresh
    }
  }

  /**
   * Toggle a hidden stage's revealed state (stored in the Pinia store).
   * Caller must call load() after toggling to fetch the updated board.
   */
  function toggleHiddenStage(stageId: number) {
    salesStore.toggleRevealedStage(stageId)
  }

  /**
   * Append more cards to a column (pagination in kanban).
   */
  async function loadMoreInColumn(stageId: number) {
    const col = localColumns.value.find((c) => c.stage.id === stageId)
    if (!col) return

    // Advance the per-column cursor (starts at 1 = the board's initial page).
    // Deriving the page from deals.length breaks after optimistic moves change
    // the length; the explicit cursor is immune to that.
    const nextPage = (columnPage.get(stageId) ?? 1) + 1
    columnPage.set(stageId, nextPage)
    const pid = pipelineId()

    const res = await salesApi.getDeals({
      view: 'list',
      pipeline_id: pid ?? undefined,
      stage_id: stageId,
      page: nextPage,
      per_page: 30,
    })
    // Map DealDto to DealCardDto shape. The list endpoint stamps the SAME health
    // signals as the board's first page when a single stage_id is requested
    // (next_task, primary_product; days_in_stage is always present) — so loaded
    // cards carry the rotting clock + task/product chips exactly like page 1
    // (audit m10).
    const newCards: DealCardDto[] = res.data.map((d) => ({
      id: d.id,
      title: d.title,
      company: d.company,
      stage_id: d.stage.id,
      owner: d.owner,
      amount: d.amount,
      currency: d.currency,
      stage_changed_at: d.stage_changed_at,
      days_in_stage: d.days_in_stage ?? null,
      next_task: d.next_task ?? null,
      primary_product: d.primary_product ?? null,
    }))
    // Dedupe against cards already present (optimistic moves can place a card
    // here that a later page also returns) — immutable append triggers the
    // column's reference-change watch.
    const existingIds = new Set(col.deals.map((d) => d.id))
    col.deals = [...col.deals, ...newCards.filter((c) => !existingIds.has(c.id))]
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
    // Optimistic: remove from source column, add to target column.
    //
    // Arrays are replaced IMMUTABLY (new reference), never spliced in place: the
    // rendering column syncs its draggable list via a shallow watch on
    // `() => props.column.deals`, which fires ONLY on reference change. In-place
    // splice/unshift keeps the same reference, so both the optimistic move and
    // the rollback would be invisible — the card would stay wherever
    // vuedraggable's own DOM reorder left it, even after the server rejects.
    const fromCol = localColumns.value.find((c) => c.stage.id === fromStageId)
    const toCol = localColumns.value.find((c) => c.stage.id === toStageId)

    const fromIndex = fromCol?.deals.findIndex((d) => d.id === card.id) ?? -1

    // Native per-currency header sums (amounts_by_currency) are exact — adjust
    // them optimistically so the column header figure doesn't lie until the next
    // board refetch. sum_amount (base-currency converted) needs an FX rate we
    // don't have on the client, so it's left for the server's next snapshot.
    const adjustCurrency = (col: BoardColumnDto, delta: number) => {
      const cur = card.currency
      const next = { ...col.amounts_by_currency }
      next[cur] = Math.max(0, (next[cur] ?? 0) + delta * card.amount)
      col.amounts_by_currency = next
    }

    if (fromCol && fromIndex >= 0) {
      fromCol.deals = fromCol.deals.filter((d) => d.id !== card.id)
      fromCol.total = Math.max(0, fromCol.total - 1)
      adjustCurrency(fromCol, -1)
    }

    if (toCol) {
      toCol.deals = [{ ...card, stage_id: toStageId }, ...toCol.deals]
      toCol.total += 1
      adjustCurrency(toCol, +1)
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
      // Rollback (immutable): pull the card out of the target column and restore
      // it to its original position in the source column, so the reference-change
      // watch propagates the revert to the rendered list.
      if (toCol) {
        toCol.deals = toCol.deals.filter((d) => d.id !== card.id)
        toCol.total = Math.max(0, toCol.total - 1)
        adjustCurrency(toCol, -1)
      }
      if (fromCol && fromIndex >= 0) {
        const restored = [...fromCol.deals]
        restored.splice(fromIndex, 0, card)
        fromCol.deals = restored
        fromCol.total += 1
        adjustCurrency(fromCol, +1)
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
    hiddenStages,
    load,
    loadSilent,
    loadMoreInColumn,
    moveDeal,
    updateCardTitle,
    toggleHiddenStage,
  }
}
