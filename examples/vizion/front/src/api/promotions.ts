import { apiClient } from '@/api/client'
import type {
  CreatePromotionRequest,
  PromotionDto,
  UpdatePromotionRequest,
} from '@/api/types/promotions'

export interface PromotionsApi {
  /**
   * List promotions for the active company (resolved by backend middleware).
   * `activeOnly` filters to currently active promos for the discount
   * calculator; omit it (or pass `false`) to list everything for the admin
   * CRUD table.
   */
  list(_activeOnly?: boolean): Promise<PromotionDto[]>
  get(_id: number): Promise<PromotionDto>
  /** admin / superadmin only. */
  create(_payload: CreatePromotionRequest): Promise<PromotionDto>
  /** admin (own company) / superadmin. */
  update(_id: number, _payload: UpdatePromotionRequest): Promise<PromotionDto>
  /** admin (own company) / superadmin. */
  remove(_id: number): Promise<void>
}

export const promotionsApi: PromotionsApi = {
  async list(activeOnly?: boolean): Promise<PromotionDto[]> {
    const response = await apiClient.get<PromotionDto[]>('/api/promotions', {
      params: activeOnly ? { active: 1 } : undefined,
    })
    return response.data
  },

  async get(id: number): Promise<PromotionDto> {
    const response = await apiClient.get<PromotionDto>(`/api/promotions/${id}`)
    return response.data
  },

  async create(payload: CreatePromotionRequest): Promise<PromotionDto> {
    const response = await apiClient.post<PromotionDto>('/api/promotions', payload)
    return response.data
  },

  async update(id: number, payload: UpdatePromotionRequest): Promise<PromotionDto> {
    const response = await apiClient.put<PromotionDto>(`/api/promotions/${id}`, payload)
    return response.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/api/promotions/${id}`)
  },
}
