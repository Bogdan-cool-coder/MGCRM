import { apiClient } from '@/api/client'
import type { Contact, ContactCompanyLink, PaginatedResponse } from '@/entities/crm'

export interface ContactListParams {
  page?: number
  per_page?: number
  search?: string
  source?: string
  country_code?: string
  tags?: string[]
}

export interface CreateContactPayload {
  full_name: string
  position?: string
  phone?: string
  email?: string
  tg_username?: string
  source?: string
  notes?: string
  tags?: string[]
}

export interface UpdateContactPayload {
  [key: string]: unknown
}

export interface AttachContactCompanyPayload {
  company_id: number
  position?: string
  employment_status?: 'works' | 'left'
}

export const contactsApi = {
  async list(params: ContactListParams = {}): Promise<PaginatedResponse<Contact>> {
    const searchParams: Record<string, unknown> = { ...params }
    if (params.tags?.length) {
      searchParams['tags[]'] = params.tags
      delete searchParams['tags']
    }
    const res = await apiClient.get<PaginatedResponse<Contact>>('/api/contacts', {
      params: searchParams,
    })
    return res.data
  },

  async get(id: number): Promise<Contact> {
    const res = await apiClient.get<{ data: Contact }>(`/api/contacts/${id}`)
    return res.data.data
  },

  async create(payload: CreateContactPayload): Promise<Contact> {
    const res = await apiClient.post<{ data: Contact }>('/api/contacts', payload)
    return res.data.data
  },

  async update(id: number, payload: UpdateContactPayload): Promise<Contact> {
    const res = await apiClient.patch<{ data: Contact }>(`/api/contacts/${id}`, payload)
    return res.data.data
  },

  async remove(id: number): Promise<void> {
    await apiClient.delete(`/api/contacts/${id}`)
  },

  // M2M: contact ↔ company
  async getCompanies(contactId: number): Promise<ContactCompanyLink[]> {
    const res = await apiClient.get<{ data: ContactCompanyLink[] }>(
      `/api/contacts/${contactId}/companies`,
    )
    return res.data.data ?? []
  },

  async attachCompany(
    contactId: number,
    payload: AttachContactCompanyPayload,
  ): Promise<ContactCompanyLink> {
    const res = await apiClient.post<{ data: ContactCompanyLink }>(
      `/api/contacts/${contactId}/companies`,
      payload,
    )
    return res.data.data
  },

  async detachCompany(contactId: number, companyId: number): Promise<void> {
    await apiClient.delete(`/api/contacts/${contactId}/companies/${companyId}`)
  },

  async setPrimaryCompany(contactId: number, companyId: number): Promise<void> {
    await apiClient.post(`/api/contacts/${contactId}/companies/${companyId}/primary`)
  },
}
