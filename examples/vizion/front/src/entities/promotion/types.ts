import type { LocalizedText } from '@/shared/types'
import type { PromotionDiscountType } from '@/api/types/promotions'

export type { PromotionDiscountType }

/**
 * Per-company discount preset — camelCase mirror of `PromotionDto`.
 * `discountMin` / `discountMax` are decimals (promo config, not MacroData
 * money). For `percent` they are percentages; for `absolute` they are amounts.
 */
export interface Promotion {
  id: number
  companyId: number
  name: LocalizedText
  description: LocalizedText | null
  discountType: PromotionDiscountType
  discountMin: number
  discountMax: number
  isActive: boolean
  sortOrder: number | null
  createdBy: number | null
  createdAt: string
  updatedAt: string
}
