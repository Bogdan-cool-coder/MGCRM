import { apiClient } from '@/api/client'
import type { Company, ContactCompanyLink, PaginatedResponse, EmploymentStatus } from '@/entities/crm'

export interface CompanyListParams {
  page?: number
  per_page?: number
  search?: string
  company_type_id?: number
  source?: string
  country_code?: string
  tags?: string[]
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

  async getHolding(companyId: number): Promise<{ data: Company[]; stub?: boolean }> {
    const res = await apiClient.get<{ data: Company[]; stub?: boolean }>(
      `/api/companies/${companyId}/holding`,
    )
    return res.data
  },
}
