import type { PromotionDto } from '@/api/types/promotions'
import type { Promotion } from './types'

export const mapPromotionDtoToPromotion = (dto: PromotionDto): Promotion => ({
  id: dto.id,
  companyId: dto.company_id,
  name: dto.name,
  description: dto.description,
  discountType: dto.discount_type,
  discountMin: dto.discount_min,
  discountMax: dto.discount_max,
  isActive: dto.is_active,
  sortOrder: dto.sort_order,
  createdBy: dto.created_by,
  createdAt: dto.created_at,
  updatedAt: dto.updated_at,
})
