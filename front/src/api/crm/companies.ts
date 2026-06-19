import { apiClient } from '@/api/client'
import type {
  Company,
  ContactCompanyLink,
  HoldingTreeDto,
  PaginatedResponse,
  EmploymentStatus,
} from '@/entities/crm'

export interface CompanyListParams {
  page?: number
  per_page?: number
  search?: string
  company_type_id?: number
  source?: string
  country_code?: string
  tags?: string[]
  engagement_tier?: 'fresh' | 'cooling' | 'cold'
  sort?: string
  direction?: 'asc' | 'desc'
}

export interface BulkCompanyPayload {
  company_ids: number[]
  operation: 'assign_responsible' | 'set_tags' | 'add_tag' | 'remove_tag'
  responsible_user_id?: number
  tags?: string[]
  tag?: string
}

export interface AttachHoldingPayload {
  parent_id: number
  holding_role: 'parent' | 'subsidiary'
}

export interface CreateCompanyPayload {
  name: string
  legal_form?: string
  tax_id?: string
  company_type_id?: number
  source?: string
  holding_id?: number | null
  country_code?: string
  responsible_user_id?: number
}

export interface UpdateCompanyPayload {
  [key: string]: unknown
}

export interface AttachEmployeePayload {
  contact_id: number
  position?: string
  employment_status?: EmploymentStatus
  is_primary?: boolean
}

export interface UpdateEmployeeLinkPayload {
  position?: string
  employment_status?: EmploymentStatus
}

export const companiesApi = {
  async list(params: CompanyListParams = {}): Promise<PaginatedResponse<Company>> {
    const searchParams: Record<string, unknown> = { ...params }
    if (params.tags?.length) {
      searchParams['tags[]'] = params.tags
      delete searchParams['tags']
    }
    const res = await apiClient.get<PaginatedResponse<Company>>('/api/companies', {
      params: searchParams,
    })
    return res.data
  },

  async get(id: number): Promise<Company> {
    const res = await apiClient.get<{ data: Company }>(`/api/companies/${id}`)
    return res.data.data
  },

  async create(payload: CreateCompanyPayload): Promise<Company> {
    const res = await apiClient.post<{ data: Company }>('/api/companies', payload)
    return res.data.data
  },

  async update(id: number, payload: UpdateCompanyPayload): Promise<Company> {
    const res = await apiClient.patch<{ data: Company }>(`/api/companies/${id}`, payload)
    return res.data.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/api/companies/${id}`)
  },

  // Employees (M2M: company ↔ contact)
  async getEmployees(companyId: number): Promise<ContactCompanyLink[]> {
    const res = await apiClient.get<{ data: ContactCompanyLink[] }>(
      `/api/companies/${companyId}/employees`,
    )
    return res.data.data ?? []
  },

  async attachEmployee(
    companyId: number,
    payload: AttachEmployeePayload,
  ): Promise<ContactCompanyLink> {
    const res = await apiClient.post<{ data: ContactCompanyLink }>(
      `/api/companies/${companyId}/employees`,
      payload,
    )
    return res.data.data
  },

  async detachEmployee(companyId: number, contactId: number): Promise<void> {
    await apiClient.delete(`/api/companies/${companyId}/employees/${contactId}`)
  },

  async setPrimaryEmployee(contactId: number, companyId: number): Promise<void> {
    await apiClient.post(`/api/contacts/${contactId}/companies/${companyId}/primary`)
  },

  async updateEmployeeLink(
    companyId: number,
    contactId: number,
    payload: UpdateEmployeeLinkPayload,
  ): Promise<ContactCompanyLink> {
    const res = await apiClient.patch<{ data: ContactCompanyLink }>(
      `/api/companies/${companyId}/employees/${contactId}`,
      payload,
    )
    return res.data.data
  },

  async getHolding(companyId: number): Promise<HoldingTreeDto | null> {
    const res = await apiClient.get<{ data: HoldingTreeDto }>(
      `/api/companies/${companyId}/holding`,
    )
    return res.data.data ?? null
  },

  async attachHolding(companyId: number, payload: AttachHoldingPayload): Promise<void> {
    await apiClient.post(`/api/companies/${companyId}/holding`, payload)
  },

  async detachHolding(companyId: number): Promise<void> {
    await apiClient.delete(`/api/companies/${companyId}/holding`)
  },

  // ── Company Deals (real, Slice 1 API) ──────────────────────────────────────

  async getDeals(companyId: number, params: { page?: number; per_page?: number } = {}): Promise<PaginatedResponse<import('@/entities/sales').DealDto>> {
    const res = await apiClient.get<PaginatedResponse<import('@/entities/sales').DealDto>>(
      `/api/companies/${companyId}/deals`,
      { params },
    )
    return res.data
  },

  // ── Bulk operations ────────────────────────────────────────────────────────

  async bulkPatch(payload: BulkCompanyPayload): Promise<{ affected: number }> {
    const res = await apiClient.patch<{ affected: number }>('/api/companies/bulk', payload)
    return res.data
  },

  async bulkDelete(companyIds: number[]): Promise<{ deleted: number }> {
    const res = await apiClient.delete<{ deleted: number }>('/api/companies/bulk', {
      data: { company_ids: companyIds },
    })
    return res.data
  },

  async exportXlsx(
    companyIds: number[],
    filters: Record<string, unknown> = {},
  ): Promise<Blob> {
    const res = await apiClient.post<Blob>(
      '/api/companies/export',
      { company_ids: companyIds, filters },
      { responseType: 'blob' },
    )
    return res.data
  },
}
