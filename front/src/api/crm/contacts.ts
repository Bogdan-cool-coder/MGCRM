import { apiClient } from '@/api/client'
import type {
  Contact,
  ContactChannel,
  ContactCompanyLink,
  ContactRelation,
  PaginatedResponse,
  ChannelHistoryEntry,
} from '@/entities/crm'

export interface ContactListParams {
  page?: number
  per_page?: number
  search?: string
  source?: string
  country_code?: string
  tags?: string[]
  company_id?: number
  engagement_tier?: 'fresh' | 'cooling' | 'cold'
  sort?: string
  direction?: 'asc' | 'desc'
}

export interface BulkContactPayload {
  contact_ids: number[]
  operation: 'assign_owner' | 'set_tags' | 'add_tag' | 'remove_tag'
  owner_id?: number
  tags?: string[]
  tag?: string
}

export interface CreateContactRelationPayload {
  related_contact_id: number
  relation_type: 'partner' | 'referrer' | 'colleague' | 'friend' | 'investor' | 'mentor' | 'other'
  note?: string | null
}

export interface UpdateContactRelationPayload {
  relation_type?: 'partner' | 'referrer' | 'colleague' | 'friend' | 'investor' | 'mentor' | 'other'
  note?: string | null
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

export interface ContactsKpiResponse {
  data: {
    entity: string
    total: number
    // companies
    clients?: number
    cat_l?: number
    cat_m?: number
    cat_s?: number
    // contacts
    active?: number
    no_touch_30?: number
    // shared
    new_week?: number
  }
}

export const contactsApi = {
  async kpi(entity: 'company' | 'contact'): Promise<ContactsKpiResponse> {
    const res = await apiClient.get<ContactsKpiResponse>('/api/contacts/kpi', {
      params: { entity },
    })
    return res.data
  },

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

  // ── Contact Channels (Phase G) ─────────────────────────────────────────────

  async getChannels(contactId: number): Promise<ContactChannel[]> {
    const res = await apiClient.get<{ data: ContactChannel[] }>(
      `/api/contacts/${contactId}/channels`,
    )
    return res.data.data ?? []
  },

  async addChannel(
    contactId: number,
    payload: { channel_type: string; value: string },
  ): Promise<ContactChannel> {
    const res = await apiClient.post<{ data: ContactChannel }>(
      `/api/contacts/${contactId}/channels`,
      payload,
    )
    return res.data.data
  },

  async updateChannel(
    contactId: number,
    channelId: number,
    payload: { channel_type?: string; value?: string },
  ): Promise<ContactChannel> {
    const res = await apiClient.patch<{ data: ContactChannel }>(
      `/api/contacts/${contactId}/channels/${channelId}`,
      payload,
    )
    return res.data.data
  },

  async deleteChannel(contactId: number, channelId: number): Promise<void> {
    await apiClient.delete(`/api/contacts/${contactId}/channels/${channelId}`)
  },

  // ── Contact Relations (Slice 1 API) ────────────────────────────────────────

  async getRelations(contactId: number): Promise<ContactRelation[]> {
    const res = await apiClient.get<{ data: ContactRelation[] }>(
      `/api/contacts/${contactId}/relations`,
    )
    return res.data.data ?? []
  },

  async addRelation(
    contactId: number,
    payload: CreateContactRelationPayload,
  ): Promise<ContactRelation> {
    const res = await apiClient.post<{ data: ContactRelation }>(
      `/api/contacts/${contactId}/relations`,
      payload,
    )
    return res.data.data
  },

  async updateRelation(
    contactId: number,
    relationId: number,
    payload: UpdateContactRelationPayload,
  ): Promise<ContactRelation> {
    const res = await apiClient.patch<{ data: ContactRelation }>(
      `/api/contacts/${contactId}/relations/${relationId}`,
      payload,
    )
    return res.data.data
  },

  async deleteRelation(contactId: number, relationId: number): Promise<void> {
    await apiClient.delete(`/api/contacts/${contactId}/relations/${relationId}`)
  },

  // ── Contact Deals (real, Slice 1 API) ──────────────────────────────────────

  async getDeals(contactId: number, params: { page?: number; per_page?: number } = {}): Promise<PaginatedResponse<import('@/entities/sales').DealDto>> {
    const res = await apiClient.get<PaginatedResponse<import('@/entities/sales').DealDto>>(
      `/api/contacts/${contactId}/deals`,
      { params },
    )
    return res.data
  },

  // ── Bulk operations ────────────────────────────────────────────────────────

  async bulkPatch(payload: BulkContactPayload): Promise<{ affected: number }> {
    const res = await apiClient.patch<{ affected: number }>('/api/contacts/bulk', payload)
    return res.data
  },

  async bulkDelete(contactIds: number[]): Promise<{ deleted: number }> {
    const res = await apiClient.delete<{ deleted: number }>('/api/contacts/bulk', {
      data: { contact_ids: contactIds },
    })
    return res.data
  },

  async exportXlsx(
    contactIds: number[],
    filters: Record<string, unknown> = {},
  ): Promise<Blob> {
    const res = await apiClient.post<Blob>(
      '/api/contacts/export',
      { contact_ids: contactIds, filters },
      { responseType: 'blob' },
    )
    return res.data
  },

  // ── Channel History (N1) ──────────────────────────────────────────────────

  async getChannelHistory(contactId: number): Promise<ChannelHistoryEntry[]> {
    const res = await apiClient.get<{ data: ChannelHistoryEntry[] }>(
      `/api/contacts/${contactId}/channel-history`,
    )
    return res.data.data ?? []
  },
}
