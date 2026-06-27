/**
 * Catalog API — all typed axios functions.
 * Maps to Laravel Catalog controllers (S1.2).
 */
import { apiClient } from '@/api/client'
import type {
  ProductDto,
  ProductPlanDto,
  ProductPriceDto,
  ProductGroupDto,
  ExchangeRateDto,
  ImportResultDto,
  CatalogPaginatedResponse,
} from '@/entities/catalog'

// ─── Product Groups ───────────────────────────────────────────────────────────

export interface ProductGroupListParams {
  active_only?: boolean
}

export interface CreateProductGroupPayload {
  name: string
  code: string
  description?: string | null
  is_active?: boolean
  sort_order?: number
}

export interface UpdateProductGroupPayload {
  name?: string
  code?: string
  description?: string | null
  is_active?: boolean
  sort_order?: number
}

// ─── Products ─────────────────────────────────────────────────────────────────

export interface ProductListParams {
  page?: number
  per_page?: number
  q?: string
  group_id?: number | null
  pricing_type?: string | null
  active_only?: boolean | null
}

export interface CreateProductPayload {
  group_id?: number | null
  name: string
  code: string
  description?: string | null
  pricing_type: string
  maps_to_product_code?: string | null
  sort_order?: number
  is_active?: boolean
}

export interface UpdateProductPayload {
  group_id?: number | null
  name?: string
  code?: string
  description?: string | null
  pricing_type?: string
  maps_to_product_code?: string | null
  sort_order?: number
  is_active?: boolean
}

// ─── Product Plans ────────────────────────────────────────────────────────────

export interface CreateProductPlanPayload {
  name: string
  code?: string | null
  unit: string
  sort_order?: number
  is_active?: boolean
}

export interface UpdateProductPlanPayload {
  name?: string
  code?: string | null
  unit?: string
  sort_order?: number
  is_active?: boolean
}

// ─── Product Prices ───────────────────────────────────────────────────────────

export interface UpsertPriceItem {
  plan_id: number | null
  currency_code: string
  amount: number
}

export interface UpsertPricesPayload {
  prices: UpsertPriceItem[]
}

// ─── Exchange Rates ───────────────────────────────────────────────────────────

export interface ExchangeRateListParams {
  page?: number
  per_page?: number
  from_code?: string | null
  to_code?: string | null
  date_from?: string | null
  date_to?: string | null
}

export interface CreateExchangeRatePayload {
  from_code: string
  to_code: string
  rate: number
  date: string
}

export interface UpdateExchangeRatePayload {
  from_code?: string
  to_code?: string
  rate?: number
  date?: string
}

// ─── API Object ───────────────────────────────────────────────────────────────

export const catalogApi = {
  // Product Groups
  async getProductGroups(
    params: ProductGroupListParams = {},
  ): Promise<ProductGroupDto[]> {
    const res = await apiClient.get<{ data: ProductGroupDto[] }>(
      '/api/catalog/product-groups',
      { params },
    )
    return res.data.data
  },

  async createProductGroup(
    payload: CreateProductGroupPayload,
  ): Promise<ProductGroupDto> {
    const res = await apiClient.post<{ data: ProductGroupDto }>(
      '/api/catalog/product-groups',
      payload,
    )
    return res.data.data
  },

  async updateProductGroup(
    id: number,
    payload: UpdateProductGroupPayload,
  ): Promise<ProductGroupDto> {
    const res = await apiClient.patch<{ data: ProductGroupDto }>(
      `/api/catalog/product-groups/${id}`,
      payload,
    )
    return res.data.data
  },

  async deleteProductGroup(id: number): Promise<void> {
    await apiClient.delete(`/api/catalog/product-groups/${id}`)
  },

  // Products
  async getProducts(
    params: ProductListParams = {},
  ): Promise<CatalogPaginatedResponse<ProductDto>> {
    // Clean null/undefined from params
    const clean: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined) clean[k] = v
    }
    const res = await apiClient.get<CatalogPaginatedResponse<ProductDto>>(
      '/api/catalog/products',
      { params: clean },
    )
    return res.data
  },

  async getProduct(id: number): Promise<ProductDto> {
    const res = await apiClient.get<{ data: ProductDto }>(
      `/api/catalog/products/${id}`,
    )
    return res.data.data
  },

  async createProduct(payload: CreateProductPayload): Promise<ProductDto> {
    const res = await apiClient.post<{ data: ProductDto }>(
      '/api/catalog/products',
      payload,
    )
    return res.data.data
  },

  async updateProduct(
    id: number,
    payload: UpdateProductPayload,
  ): Promise<ProductDto> {
    const res = await apiClient.patch<{ data: ProductDto }>(
      `/api/catalog/products/${id}`,
      payload,
    )
    return res.data.data
  },

  async deleteProduct(id: number): Promise<void> {
    await apiClient.delete(`/api/catalog/products/${id}`)
  },

  // Product Plans
  async getProductPlans(productId: number): Promise<ProductPlanDto[]> {
    const res = await apiClient.get<{ data: ProductPlanDto[] }>(
      `/api/catalog/products/${productId}/plans`,
    )
    return res.data.data
  },

  async createProductPlan(
    productId: number,
    payload: CreateProductPlanPayload,
  ): Promise<ProductPlanDto> {
    const res = await apiClient.post<{ data: ProductPlanDto }>(
      `/api/catalog/products/${productId}/plans`,
      payload,
    )
    return res.data.data
  },

  async updateProductPlan(
    productId: number,
    planId: number,
    payload: UpdateProductPlanPayload,
  ): Promise<ProductPlanDto> {
    const res = await apiClient.patch<{ data: ProductPlanDto }>(
      `/api/catalog/products/${productId}/plans/${planId}`,
      payload,
    )
    return res.data.data
  },

  async deleteProductPlan(productId: number, planId: number): Promise<void> {
    await apiClient.delete(`/api/catalog/products/${productId}/plans/${planId}`)
  },

  // Product Prices
  async upsertPrices(
    productId: number,
    payload: UpsertPricesPayload,
  ): Promise<ProductPriceDto[]> {
    const res = await apiClient.post<{ data: ProductPriceDto[] }>(
      `/api/catalog/products/${productId}/prices`,
      payload,
    )
    return res.data.data
  },

  async deleteProductPrice(productId: number, priceId: number): Promise<void> {
    await apiClient.delete(`/api/catalog/products/${productId}/prices/${priceId}`)
  },

  // Exchange Rates
  async getExchangeRates(
    params: ExchangeRateListParams = {},
  ): Promise<CatalogPaginatedResponse<ExchangeRateDto>> {
    const clean: Record<string, unknown> = {}
    for (const [k, v] of Object.entries(params)) {
      if (v !== null && v !== undefined) clean[k] = v
    }
    const res = await apiClient.get<CatalogPaginatedResponse<ExchangeRateDto>>(
      '/api/catalog/exchange-rates',
      { params: clean },
    )
    return res.data
  },

  async createExchangeRate(
    payload: CreateExchangeRatePayload,
  ): Promise<ExchangeRateDto> {
    const res = await apiClient.post<{ data: ExchangeRateDto }>(
      '/api/catalog/exchange-rates',
      payload,
    )
    return res.data.data
  },

  async updateExchangeRate(
    id: number,
    payload: UpdateExchangeRatePayload,
  ): Promise<ExchangeRateDto> {
    const res = await apiClient.patch<{ data: ExchangeRateDto }>(
      `/api/catalog/exchange-rates/${id}`,
      payload,
    )
    return res.data.data
  },

  async deleteExchangeRate(id: number): Promise<void> {
    await apiClient.delete(`/api/catalog/exchange-rates/${id}`)
  },

  async refreshRates(): Promise<void> {
    await apiClient.post('/api/catalog/exchange-rates/refresh')
  },

  // Price Import
  // NOTE: preview hits the dedicated /preview route (true dry-run, never writes to DB).
  // importConfirm hits /price-import (real write).
  async importPreview(file: File): Promise<ImportResultDto> {
    const form = new FormData()
    form.append('file', file)
    const res = await apiClient.post<{ data: ImportResultDto }>(
      '/api/catalog/price-import/preview',
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    return res.data.data
  },

  async importConfirm(file: File): Promise<ImportResultDto> {
    const form = new FormData()
    form.append('file', file)
    const res = await apiClient.post<{ data: ImportResultDto }>(
      '/api/catalog/price-import',
      form,
      { headers: { 'Content-Type': 'multipart/form-data' } },
    )
    return res.data.data
  },

  downloadTemplateUrl(): string {
    return '/api/catalog/price-import/template'
  },

  /**
   * Download the price-import Excel template via the authenticated apiClient
   * (Bearer token). Returns a Blob so the caller can create an object URL.
   */
  async downloadTemplate(): Promise<Blob> {
    const res = await apiClient.get<Blob>('/api/catalog/price-import/template', {
      responseType: 'blob',
    })
    return res.data
  },
}
