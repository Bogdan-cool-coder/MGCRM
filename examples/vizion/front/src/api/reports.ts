import { apiClient } from '@/api/client'
import type {
  FetchReportOptions,
  GroupRowsResponseDto,
  ReportDto,
  ReportSortOption,
  UpdateReportRequest,
  UpdateReportsOrderResponse,
} from '@/api/types'

export interface FetchGroupRowsOptions {
  group_key: string
  page?: number
  per_page?: number
  filters?: Record<string, unknown>
  sort?: ReportSortOption
}

export interface FilterOption {
  value: string
  label: string
}

export interface FilterOptionsResponse {
  options: FilterOption[]
  async: boolean
}

export interface ReportsApi {
  /**
   * Active company is resolved by backend middleware ResolveActiveCompany —
   * no client-side `company_id` parameter is needed. Reactive scope-keying
   * for `useScopedResource` lives in the composable's `scope:` ref, not in
   * the API signature.
   */
  fetchReports(): Promise<ReportDto[]>
  fetchReport(_id: number, _options?: FetchReportOptions): Promise<ReportDto>
  fetchGroupRows(_reportId: number, _options: FetchGroupRowsOptions): Promise<GroupRowsResponseDto>
  fetchFilterOptions(_endpoint: string, _q: string, _limit?: number): Promise<FilterOptionsResponse>
  updateReport(_id: number, _data: UpdateReportRequest): Promise<ReportDto>
  /**
   * Toggle publication state for a custom report. Backend returns the full
   * report DTO so callers can refresh local state without an extra GET.
   * Throws on 403 (insufficient role / system report).
   */
  publishReport(_id: number): Promise<ReportDto>
  unpublishReport(_id: number): Promise<ReportDto>
  deleteReport(_id: number): Promise<void>
  /**
   * Persist the per-user report ordering for the active company. `order` is
   * the full list of report ids in the desired order; an empty array resets
   * to the company default. Backend echoes back `{ company_id, order }`.
   */
  updateReportsOrder(_order: number[]): Promise<UpdateReportsOrderResponse>
}

export const reportsApi: ReportsApi = {
  async fetchReports(): Promise<ReportDto[]> {
    const response = await apiClient.get<ReportDto[]>('/api/reports')
    return response.data
  },

  async fetchReport(id: number, options?: FetchReportOptions): Promise<ReportDto> {
    const params = new URLSearchParams()

    if (options?.page) {
      params.append('page', options.page.toString())
    }
    if (options?.per_page) {
      params.append('per_page', options.per_page.toString())
    }
    if (options?.sort) {
      params.append('sort[field]', options.sort.field)
      params.append('sort[direction]', options.sort.direction)
    }

    // Build filters query params
    if (options?.filters) {
      Object.entries(options.filters).forEach(([field, value]) => {
        if (typeof value === 'object' && value !== null) {
          // Handle date_range and other object filters
          Object.entries(value).forEach(([key, val]) => {
            if (val !== null && val !== undefined) {
              params.append(`filters[${field}][${key}]`, String(val))
            }
          })
        } else if (Array.isArray(value)) {
          // Handle multiselect arrays
          value.forEach((v, index) => {
            params.append(`filters[${field}][${index}]`, String(v))
          })
        } else if (value !== null && value !== undefined) {
          // Handle single values
          params.append(`filters[${field}]`, String(value))
        }
      })
    }

    const queryString = params.toString()
    const url = `/api/reports/${id}${queryString ? `?${queryString}` : ''}`

    const response = await apiClient.get<ReportDto>(url)
    return response.data
  },

  async fetchGroupRows(reportId: number, options: FetchGroupRowsOptions): Promise<GroupRowsResponseDto> {
    const params = new URLSearchParams()
    params.append('group_key', options.group_key)

    if (options.page) {
      params.append('page', options.page.toString())
    }
    if (options.per_page) {
      params.append('per_page', options.per_page.toString())
    }
    if (options.sort) {
      params.append('sort[field]', options.sort.field)
      params.append('sort[direction]', options.sort.direction)
    }
    if (options.filters) {
      Object.entries(options.filters).forEach(([field, value]) => {
        if (typeof value === 'object' && value !== null && !Array.isArray(value)) {
          Object.entries(value as Record<string, unknown>).forEach(([key, val]) => {
            if (val !== null && val !== undefined) {
              params.append(`filters[${field}][${key}]`, String(val))
            }
          })
        } else if (Array.isArray(value)) {
          value.forEach((v, index) => {
            params.append(`filters[${field}][${index}]`, String(v))
          })
        } else if (value !== null && value !== undefined) {
          params.append(`filters[${field}]`, String(value))
        }
      })
    }

    const response = await apiClient.get<GroupRowsResponseDto>(
      `/api/reports/${reportId}/group-rows?${params.toString()}`,
    )
    return response.data
  },

  async fetchFilterOptions(
    endpoint: string,
    q: string,
    limit = 20,
  ): Promise<FilterOptionsResponse> {
    const params = new URLSearchParams()
    params.append('q', q)
    params.append('limit', String(limit))
    const response = await apiClient.get<FilterOptionsResponse>(`${endpoint}?${params.toString()}`)
    return response.data
  },

  async updateReport(id: number, data: UpdateReportRequest): Promise<ReportDto> {
    const response = await apiClient.put<ReportDto>(`/api/reports/${id}`, {
      title: data.title,
      description: data.description,
      config: data.config,
      is_published: data.is_published,
    })
    return response.data
  },

  async publishReport(id: number): Promise<ReportDto> {
    const response = await apiClient.post<ReportDto>(`/api/reports/${id}/publish`)
    return response.data
  },

  async unpublishReport(id: number): Promise<ReportDto> {
    const response = await apiClient.post<ReportDto>(`/api/reports/${id}/unpublish`)
    return response.data
  },

  async deleteReport(id: number): Promise<void> {
    await apiClient.delete(`/api/reports/${id}`)
  },

  async updateReportsOrder(order: number[]): Promise<UpdateReportsOrderResponse> {
    const response = await apiClient.put<UpdateReportsOrderResponse>('/api/reports/order', { order })
    return response.data
  },
}

export type { FetchReportOptions, ReportDto as Report, UpdateReportRequest }
