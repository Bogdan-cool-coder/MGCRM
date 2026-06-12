/**
 * Sales API — all typed axios functions for S1.3 Deals.
 * Follows catalogApi pattern from api/catalog.ts.
 */
import { apiClient } from '@/api/client'
import type {
  PipelineDto,
  PipelineStageDto,
  DealDto,
  DealCardDto,
  BoardColumnDto,
  BoardResponseDto,
  BoardRawResponseDto,
  DealProductDto,
  DealContactDto,
  LostReasonDto,
  DealStageHistoryDto,
  SalesPaginatedResponse,
  CreateDealPayload,
  UpdateDealPayload,
  MoveDealPayload,
  AddDealProductPayload,
  UpdateDealProductPayload,
  AddDealContactPayload,
  DealListParams,
  CreatePipelinePayload,
  UpdatePipelinePayload,
  CreateStagePayload,
  UpdateStagePayload,
  ReorderStageItem,
} from '@/entities/sales'

// ─── Move response ─────────────────────────────────────────────────────────────

export interface MoveDealResponse {
  data: DealDto
  won_gate_warning: boolean
}

// ─── Board adapter ─────────────────────────────────────────────────────────────
// Backend returns columns as a keyed object {stageId: {...}} with no embedded
// stage and with DealCard owner as `full_name`. This adapter normalises the
// response into the BoardResponseDto shape the frontend components expect.

function adaptBoardResponse(raw: BoardRawResponseDto): BoardResponseDto {
  const stagesById = new Map<number, PipelineStageDto>(
    raw.stages.map((s) => [s.id, s]),
  )

  const columns: BoardColumnDto[] = Object.values(raw.columns).map((col) => {
    const stage = stagesById.get(col.stage_id) ?? {
      id: col.stage_id,
      pipeline_id: raw.pipeline.id,
      name: String(col.stage_id),
      code: String(col.stage_id),
      color: null,
      sort_order: 0,
      is_won: false,
      is_lost: false,
      won_gate: false,
      hidden_by_default: false,
      parent_stage_id: null,
      stage_features: [],
      sla_hours: null,
      task_types: [],
      required_fields: {},
    }

    const deals: DealCardDto[] = col.deals.map((d) => ({
      id: d.id,
      title: d.title,
      amount: d.amount,
      currency: d.currency,
      stage_id: d.stage_id,
      stage_changed_at: null,
      company: { id: d.company_id, name: d.company_name ?? '' },
      owner: d.owner
        ? { id: d.owner.id, name: d.owner.full_name, avatar_path: null }
        : { id: 0, name: '', avatar_path: null },
    }))

    return {
      stage,
      total: col.total,
      sum_amount: col.sum_amount,
      currency: 'KZT', // backend does not return per-column currency; use default
      deals,
      has_more: col.total > col.deals.length,
    }
  })

  // Sort columns by stage sort_order
  columns.sort((a, b) => a.stage.sort_order - b.stage.sort_order)

  return { pipeline: raw.pipeline, columns }
}

// ─── API Object ───────────────────────────────────────────────────────────────

export const salesApi = {
  // ── Pipelines ──────────────────────────────────────────────────────────────

  async getPipelines(kind?: string): Promise<PipelineDto[]> {
    const params: Record<string, unknown> = {}
    if (kind) params.kind = kind
    const res = await apiClient.get<{ data: PipelineDto[] }>('/api/pipelines', { params })
    return res.data.data
  },

  async getPipeline(id: number): Promise<PipelineDto> {
    const res = await apiClient.get<{ data: PipelineDto }>(`/api/pipelines/${id}`)
    return res.data.data
  },

  async getPipelineStages(id: number): Promise<PipelineStageDto[]> {
    const res = await apiClient.get<{ data: PipelineStageDto[] }>(`/api/pipelines/${id}/stages`)
    return res.data.data
  },

  // ── Pipeline CRUD (S1.5) ───────────────────────────────────────────────────

  async createPipeline(payload: CreatePipelinePayload): Promise<PipelineDto> {
    const res = await apiClient.post<{ data: PipelineDto }>('/api/pipelines', payload)
    return res.data.data
  },

  async updatePipeline(id: number, payload: UpdatePipelinePayload): Promise<PipelineDto> {
    const res = await apiClient.patch<{ data: PipelineDto }>(`/api/pipelines/${id}`, payload)
    return res.data.data
  },

  async deletePipeline(id: number): Promise<void> {
    await apiClient.delete(`/api/pipelines/${id}`)
  },

  // ── Stage CRUD (S1.5) ──────────────────────────────────────────────────────

  async createStage(pipelineId: number, payload: CreateStagePayload): Promise<PipelineStageDto> {
    const res = await apiClient.post<{ data: PipelineStageDto }>(
      `/api/pipelines/${pipelineId}/stages`,
      payload,
    )
    return res.data.data
  },

  async updateStage(
    pipelineId: number,
    stageId: number,
    payload: UpdateStagePayload,
  ): Promise<PipelineStageDto> {
    const res = await apiClient.patch<{ data: PipelineStageDto }>(
      `/api/pipelines/${pipelineId}/stages/${stageId}`,
      payload,
    )
    return res.data.data
  },

  async deleteStage(pipelineId: number, stageId: number): Promise<void> {
    await apiClient.delete(`/api/pipelines/${pipelineId}/stages/${stageId}`)
  },

  async reorderStages(
    pipelineId: number,
    stages: ReorderStageItem[],
  ): Promise<PipelineStageDto[]> {
    const res = await apiClient.patch<{ data: PipelineStageDto[] }>(
      `/api/pipelines/${pipelineId}/stages/reorder`,
      { stages },
    )
    return res.data.data
  },

  // ── Lost Reasons ───────────────────────────────────────────────────────────

  async getLostReasons(activeOnly = true): Promise<LostReasonDto[]> {
    const res = await apiClient.get<{ data: LostReasonDto[] }>('/api/lost-reasons', {
      params: activeOnly ? { active_only: 1 } : {},
    })
    return res.data.data
  },

  // ── Deals (list / board) ───────────────────────────────────────────────────

  async getDeals(
    params: DealListParams = {},
  ): Promise<SalesPaginatedResponse<DealDto>> {
    const clean: Record<string, unknown> = { view: 'list' }
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') clean[k] = v
    }
    const res = await apiClient.get<SalesPaginatedResponse<DealDto>>('/api/deals', { params: clean })
    return res.data
  },

  async getDealsBoard(params: DealListParams = {}): Promise<BoardResponseDto> {
    const clean: Record<string, unknown> = { view: 'board' }
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined && v !== '') clean[k] = v
    }
    const res = await apiClient.get<BoardRawResponseDto>('/api/deals', { params: clean })
    return adaptBoardResponse(res.data)
  },

  async getDeal(id: number): Promise<DealDto> {
    const res = await apiClient.get<{ data: DealDto }>(`/api/deals/${id}`)
    return res.data.data
  },

  async createDeal(payload: CreateDealPayload): Promise<DealDto> {
    const res = await apiClient.post<{ data: DealDto }>('/api/deals', payload)
    return res.data.data
  },

  async updateDeal(id: number, payload: UpdateDealPayload): Promise<DealDto> {
    const res = await apiClient.patch<{ data: DealDto }>(`/api/deals/${id}`, payload)
    return res.data.data
  },

  async deleteDeal(id: number): Promise<void> {
    await apiClient.delete(`/api/deals/${id}`)
  },

  async moveDeal(id: number, payload: MoveDealPayload): Promise<MoveDealResponse> {
    const res = await apiClient.post<MoveDealResponse>(`/api/deals/${id}/move`, payload)
    return res.data
  },

  // ── Deal Products ──────────────────────────────────────────────────────────

  async getDealProducts(dealId: number): Promise<DealProductDto[]> {
    const res = await apiClient.get<{ data: DealProductDto[] }>(`/api/deals/${dealId}/products`)
    return res.data.data
  },

  async addDealProduct(dealId: number, payload: AddDealProductPayload): Promise<DealProductDto> {
    const res = await apiClient.post<{ data: DealProductDto }>(
      `/api/deals/${dealId}/products`,
      payload,
    )
    return res.data.data
  },

  async updateDealProduct(
    dealId: number,
    pid: number,
    payload: UpdateDealProductPayload,
  ): Promise<DealProductDto> {
    const res = await apiClient.patch<{ data: DealProductDto }>(
      `/api/deals/${dealId}/products/${pid}`,
      payload,
    )
    return res.data.data
  },

  async removeDealProduct(dealId: number, pid: number): Promise<void> {
    await apiClient.delete(`/api/deals/${dealId}/products/${pid}`)
  },

  // ── Deal Contacts ──────────────────────────────────────────────────────────

  async getDealContacts(dealId: number): Promise<DealContactDto[]> {
    const res = await apiClient.get<{ data: DealContactDto[] }>(`/api/deals/${dealId}/contacts`)
    return res.data.data
  },

  async addDealContact(dealId: number, payload: AddDealContactPayload): Promise<DealContactDto> {
    const res = await apiClient.post<{ data: DealContactDto }>(
      `/api/deals/${dealId}/contacts`,
      payload,
    )
    return res.data.data
  },

  async removeDealContact(dealId: number, cid: number): Promise<void> {
    await apiClient.delete(`/api/deals/${dealId}/contacts/${cid}`)
  },

  // ── Deal History ───────────────────────────────────────────────────────────

  async getDealHistory(dealId: number): Promise<DealStageHistoryDto[]> {
    const res = await apiClient.get<{ data: DealStageHistoryDto[] }>(
      `/api/deals/${dealId}/history`,
    )
    return res.data.data
  },
}
