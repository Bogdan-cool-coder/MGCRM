import { apiClient } from '@/api/client'
import type {
  CompanyType,
  Source,
  Country,
  City,
  ContactPosition,
  AcquisitionChannel,
  DisconnectReason,
} from '@/entities/crm'

export const directoriesApi = {
  async getCompanyTypes(): Promise<CompanyType[]> {
    const res = await apiClient.get<{ data: CompanyType[] }>('/api/admin/company-types')
    return res.data.data ?? []
  },

  async getSources(): Promise<Source[]> {
    const res = await apiClient.get<{ data: Source[] }>('/api/admin/sources')
    return res.data.data ?? []
  },

  async getCountries(params: { active_only?: boolean } = {}): Promise<Country[]> {
    const res = await apiClient.get<{ data: Country[] }>('/api/admin/countries', { params })
    return res.data.data ?? []
  },

  async createCountry(
    data: { code: string; name: string; name_en?: string; phone_prefix?: string; sort_order?: number; is_active?: boolean },
  ): Promise<Country> {
    const res = await apiClient.post<{ data: Country }>('/api/admin/countries', data)
    return res.data.data
  },

  async updateCountry(
    id: number,
    data: Partial<{ name: string; name_en: string; phone_prefix: string; sort_order: number; is_active: boolean }>,
  ): Promise<Country> {
    const res = await apiClient.patch<{ data: Country }>(`/api/admin/countries/${id}`, data)
    return res.data.data
  },

  async deleteCountry(id: number): Promise<void> {
    await apiClient.delete(`/api/admin/countries/${id}`)
  },

  async getCities(countryCode?: string): Promise<City[]> {
    const params = countryCode ? { country_code: countryCode } : {}
    const res = await apiClient.get<{ data: City[] }>('/api/admin/cities', { params })
    return res.data.data ?? []
  },

  async getContactPositions(): Promise<ContactPosition[]> {
    const res = await apiClient.get<{ data: ContactPosition[] }>('/api/admin/contact-positions')
    return res.data.data ?? []
  },

  // ── Acquisition Channels (N1) ─────────────────────────────────────────────

  async getAcquisitionChannels(params: { active_only?: boolean } = {}): Promise<AcquisitionChannel[]> {
    const res = await apiClient.get<{ data: AcquisitionChannel[] }>(
      '/api/admin/acquisition-channels',
      { params },
    )
    return res.data.data ?? []
  },

  async createAcquisitionChannel(
    data: { name: string; sort_order?: number; is_active?: boolean },
  ): Promise<AcquisitionChannel> {
    const res = await apiClient.post<{ data: AcquisitionChannel }>(
      '/api/admin/acquisition-channels',
      data,
    )
    return res.data.data
  },

  async updateAcquisitionChannel(
    id: number,
    data: Partial<{ name: string; sort_order: number; is_active: boolean }>,
  ): Promise<AcquisitionChannel> {
    const res = await apiClient.patch<{ data: AcquisitionChannel }>(
      `/api/admin/acquisition-channels/${id}`,
      data,
    )
    return res.data.data
  },

  async deleteAcquisitionChannel(id: number): Promise<void> {
    await apiClient.delete(`/api/admin/acquisition-channels/${id}`)
  },

  // ── Disconnect Reasons (N6) ───────────────────────────────────────────────

  async getDisconnectReasons(params: { active_only?: boolean } = {}): Promise<DisconnectReason[]> {
    const res = await apiClient.get<{ data: DisconnectReason[] }>(
      '/api/admin/disconnect-reasons',
      { params },
    )
    return res.data.data ?? []
  },

  async createDisconnectReason(
    data: { name: string; sort_order?: number; is_active?: boolean },
  ): Promise<DisconnectReason> {
    const res = await apiClient.post<{ data: DisconnectReason }>(
      '/api/admin/disconnect-reasons',
      data,
    )
    return res.data.data
  },

  async updateDisconnectReason(
    id: number,
    data: Partial<{ name: string; sort_order: number; is_active: boolean }>,
  ): Promise<DisconnectReason> {
    const res = await apiClient.patch<{ data: DisconnectReason }>(
      `/api/admin/disconnect-reasons/${id}`,
      data,
    )
    return res.data.data
  },

  async deleteDisconnectReason(id: number): Promise<void> {
    await apiClient.delete(`/api/admin/disconnect-reasons/${id}`)
  },
}
