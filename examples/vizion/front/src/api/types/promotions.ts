import type { LocalizedText } from '@/shared/types'

/**
 * Snake_case mirror of the backend `promotions` row
 * (DOCUMENTS.md §`promotions`). Per-company discount presets surfaced in the
 * proposal discount calculator. `discount_min` / `discount_max` are decimals
 * (promo % / absolute amounts are Vizion config, not MacroData money — the
 * "money in int" rule does not apply here, see DOCUMENTS.md).
 */

/** Whether the discount range is a percentage or an absolute amount. */
export type PromotionDiscountType = 'percent' | 'absolute'

export interface PromotionDto {
  id: number
  company_id: number
  name: LocalizedText
  description: LocalizedText | null
  discount_type: PromotionDiscountType
  discount_min: number
  discount_max: number
  is_active: boolean
  sort_order: number | null
  created_by: number | null
  created_at: string
  updated_at: string
}

export interface CreatePromotionRequest {
  name: LocalizedText
  description?: LocalizedText | null
  discount_type: PromotionDiscountType
  discount_min: number
  discount_max: number
  is_active?: boolean
  sort_order?: number | null
}

export interface UpdatePromotionRequest {
  name?: LocalizedText
  description?: LocalizedText | null
  discount_type?: PromotionDiscountType
  discount_min?: number
  discount_max?: number
  is_active?: boolean
  sort_order?: number | null
}
