import { promotionsApi } from '@/api/promotions'
import { mapPromotionDtoToPromotion } from '@/entities/promotion'
import type { Promotion } from '@/entities/promotion'
import type {
  CreatePromotionRequest,
  UpdatePromotionRequest,
} from '@/api/types/promotions'

export class PromotionService {
  async fetchAllPromotions(activeOnly?: boolean): Promise<Promotion[]> {
    return (await promotionsApi.list(activeOnly)).map(mapPromotionDtoToPromotion)
  }

  async fetchPromotion(id: number): Promise<Promotion> {
    return mapPromotionDtoToPromotion(await promotionsApi.get(id))
  }

  async createPromotion(payload: CreatePromotionRequest): Promise<Promotion> {
    return mapPromotionDtoToPromotion(await promotionsApi.create(payload))
  }

  async updatePromotion(id: number, payload: UpdatePromotionRequest): Promise<Promotion> {
    return mapPromotionDtoToPromotion(await promotionsApi.update(id, payload))
  }

  async deletePromotion(id: number): Promise<void> {
    await promotionsApi.remove(id)
  }
}
