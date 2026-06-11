import { apiClient } from '@/api/client'
import type { CompanyType, Source, Country, City, ContactPosition } from '@/entities/crm'

export const directoriesApi = {
  async getCompanyTypes(): Promise<CompanyType[]> {
    const res = await apiClient.get<{ data: CompanyType[] }>('/api/admin/company-types')
    return res.data.data ?? []
  },

  async getSources(): Promise<Source[]> {
    const res = await apiClient.get<{ data: Source[] }>('/api/admin/sources')
    return res.data.data ?? []
  },

  async getCountries(): Promise<Country[]> {
    const res = await apiClient.get<{ data: Country[] }>('/api/admin/countries')
    return res.data.data ?? []
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
}
