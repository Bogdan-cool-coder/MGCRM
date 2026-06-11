/**
 * Catalog entities — Product, Plan, Price, Group, ExchangeRate.
 * Typed manually from Laravel API Resources (S1.2).
 * All monetary values are stored as integers (kopecks).
 */

// ─── Enums ────────────────────────────────────────────────────────────────────

export type PricingType = 'fixed' | 'tiered' | 'per_minute' | 'package' | 'custom'
export type BillingUnit = 'year' | 'one_time' | 'minute' | 'package'
export type RateSource = 'exchangerate-api' | 'manual'

// ─── Product Group ────────────────────────────────────────────────────────────

export interface ProductGroupDto {
  id: number
  name: string
  code: string
  description: string | null
  is_active: boolean
  sort_order: number
  created_at: string | null
  updated_at: string | null
}

// ─── Product Price ─────────────────────────────────────────────────────────────

export interface ProductPriceDto {
  id: number
  product_id: number
  plan_id: number | null
  currency_code: string
  amount: number
  valid_from?: string | null
  valid_to?: string | null
  created_at: string | null
  updated_at: string | null
}

// ─── Product Plan ─────────────────────────────────────────────────────────────

export interface ProductPlanDto {
  id: number
  product_id: number
  name: string
  code: string | null
  unit: BillingUnit
  sort_order: number
  is_active: boolean
  prices?: ProductPriceDto[]
  created_at: string | null
  updated_at: string | null
}

// ─── Product ──────────────────────────────────────────────────────────────────

export interface ProductDto {
  id: number
  group_id: number | null
  group?: ProductGroupDto | null
  name: string
  code: string
  description: string | null
  pricing_type: PricingType
  maps_to_product_code: string | null
  sort_order: number
  is_active: boolean
  plans?: ProductPlanDto[]
  prices?: ProductPriceDto[]
  created_at: string | null
  updated_at: string | null
}

// ─── Exchange Rate ─────────────────────────────────────────────────────────────

export interface ExchangeRateDto {
  id: number
  from_code: string
  to_code: string
  rate: number
  date: string
  source: RateSource
  created_at: string | null
  updated_at: string | null
}

// ─── Import Result ─────────────────────────────────────────────────────────────

export interface ImportErrorDto {
  row: number
  message: string
}

export interface ImportResultDto {
  dry_run: boolean
  would_insert: number
  would_update: number
  inserted: number
  updated: number
  skipped: number
  errors: ImportErrorDto[]
}

// ─── Pagination ────────────────────────────────────────────────────────────────

export interface CatalogPaginatedResponse<T> {
  data: T[]
  meta: {
    current_page: number
    last_page: number
    per_page: number
    total: number
    from: number | null
    to: number | null
  }
}
