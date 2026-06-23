import { apiClient } from '@/api/client'
import type {
  Company,
  ContactCompanyLink,
  HoldingTreeDto,
  PaginatedResponse,
  EmploymentStatus,
  ChannelHistoryEntry,
  CompanyRequisite,
  CreateRequisitePayload,
  UpdateRequisitePayload,
  CompanyClientStatusLogEntry,
  CompanyChannel,
} from '@/entities/crm'
import type { DocumentDto } from '@/entities/document'

export interface CompanyListParams {
  page?: number
  per_page?: number
  search?: string
  // multi-value filters (arrays sent as owner_ids[], company_type_ids[], etc.)
  owner_ids?: number[]
  company_type_ids?: number[]
  category_code?: string[]
  sources?: string[]
  tags?: string[]
  // single-value filters
  country_code?: string
  city?: string
  engagement_tier?: 'fresh' | 'cooling' | 'cold'
  // date-range filters (ISO date strings)
  created_from?: string
  created_to?: string
  last_touch_from?: string
  last_touch_to?: string
  // presets
  only_mine?: boolean
  only_active?: boolean
  only_with_deals?: boolean
  only_no_task?: boolean
  // sorting — backend contract: sort_by + sort_dir
  sort_by?: 'name' | 'category' | 'country' | 'deals' | 'last_contact' | 'engagement' | 'owner' | 'created'
  sort_dir?: 'asc' | 'desc'
  // legacy single params kept for backward compat
  company_type_id?: number
  source?: string
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
    // Serialize array params to bracket notation for Laravel
    const arrayKeys: Array<keyof CompanyListParams> = ['owner_ids', 'company_type_ids', 'category_code', 'sources', 'tags']
    for (const key of arrayKeys) {
      const val = params[key] as unknown[] | undefined
      if (val?.length) {
        searchParams[`${key}[]`] = val
      }
      delete searchParams[key]
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

  // ── Channel History (N1) ──────────────────────────────────────────────────

  async getChannelHistory(companyId: number): Promise<ChannelHistoryEntry[]> {
    const res = await apiClient.get<{ data: ChannelHistoryEntry[] }>(
      `/api/companies/${companyId}/channel-history`,
    )
    return res.data.data ?? []
  },

  // ── Requisites (N2) ───────────────────────────────────────────────────────

  async getRequisites(companyId: number): Promise<CompanyRequisite[]> {
    const res = await apiClient.get<{ data: CompanyRequisite[] }>(
      `/api/companies/${companyId}/requisites`,
    )
    return res.data.data ?? []
  },

  async createRequisite(
    companyId: number,
    payload: CreateRequisitePayload,
  ): Promise<CompanyRequisite> {
    const res = await apiClient.post<{ data: CompanyRequisite }>(
      `/api/companies/${companyId}/requisites`,
      payload,
    )
    return res.data.data
  },

  async updateRequisite(
    companyId: number,
    requisiteId: number,
    payload: UpdateRequisitePayload,
  ): Promise<CompanyRequisite> {
    const res = await apiClient.patch<{ data: CompanyRequisite }>(
      `/api/companies/${companyId}/requisites/${requisiteId}`,
      payload,
    )
    return res.data.data
  },

  async deleteRequisite(companyId: number, requisiteId: number): Promise<void> {
    await apiClient.delete(`/api/companies/${companyId}/requisites/${requisiteId}`)
  },

  async setCurrentRequisite(companyId: number, requisiteId: number): Promise<void> {
    await apiClient.post(`/api/companies/${companyId}/requisites/${requisiteId}/set-current`)
  },

  // ── Client lifecycle (N5/N6) ───────────────────────────────────────────────

  async getStatusLog(
    companyId: number,
    page = 1,
  ): Promise<PaginatedResponse<CompanyClientStatusLogEntry>> {
    const res = await apiClient.get<PaginatedResponse<CompanyClientStatusLogEntry>>(
      `/api/companies/${companyId}/status-log`,
      { params: { page } },
    )
    return res.data
  },

  async disconnect(
    companyId: number,
    payload: {
      disconnect_reason_id: number
      termination_date: string
      context?: { custom?: { termination_signatory?: string; original_contract_number?: string; original_contract_date?: string } }
    },
  ): Promise<DocumentDto> {
    const res = await apiClient.post<{ data: DocumentDto }>(
      `/api/companies/${companyId}/disconnect`,
      payload,
    )
    return res.data.data
  },

  async reconnect(companyId: number): Promise<Company> {
    const res = await apiClient.post<{ data: Company }>(
      `/api/companies/${companyId}/reconnect`,
    )
    return res.data.data
  },

  // ── Termination Document (N6) ─────────────────────────────────────────────

  async generateTerminationDocument(
    companyId: number,
    payload: {
      disconnect_reason_id: number
      termination_date: string
      context?: { custom?: Record<string, string> }
    },
  ): Promise<{ document_id: number; number: string | null; docx_url: string | null; pdf_url: string | null; warnings: string[] }> {
    const res = await apiClient.post<{ document_id: number; number: string | null; docx_url: string | null; pdf_url: string | null; warnings: string[] }>(
      `/api/companies/${companyId}/termination-documents/generate`,
      payload,
    )
    return res.data
  },

  // ── Company Channels ──────────────────────────────────────────────────────

  async getChannels(companyId: number): Promise<CompanyChannel[]> {
    const res = await apiClient.get<{ data: CompanyChannel[] }>(
      `/api/companies/${companyId}/channels`,
    )
    return res.data.data ?? []
  },

  async addChannel(
    companyId: number,
    payload: { channel_type: string; value: string },
  ): Promise<CompanyChannel> {
    const res = await apiClient.post<{ data: CompanyChannel }>(
      `/api/companies/${companyId}/channels`,
      payload,
    )
    return res.data.data
  },

  async updateChannel(
    companyId: number,
    channelId: number,
    payload: { channel_type?: string; value?: string },
  ): Promise<CompanyChannel> {
    const res = await apiClient.patch<{ data: CompanyChannel }>(
      `/api/companies/${companyId}/channels/${channelId}`,
      payload,
    )
    return res.data.data
  },

  async deleteChannel(companyId: number, channelId: number): Promise<void> {
    await apiClient.delete(`/api/companies/${companyId}/channels/${channelId}`)
  },
}
