import { apiClient } from '@/api/client'
import type {
  CompanyDto,
  CreateCompanyRequest,
  UpdateCompanyRequest,
  UserDto,
} from '@/api/types'

export interface CompaniesApi {
  fetchCompanies(): Promise<CompanyDto[]>
  fetchCompany(id: number): Promise<CompanyDto>
  createCompany(data: CreateCompanyRequest): Promise<CompanyDto>
  updateCompany(id: number, data: UpdateCompanyRequest): Promise<CompanyDto>
  deleteCompany(id: number): Promise<void>
  switchActiveCompany(id: number): Promise<UserDto>
}

export const companiesApi: CompaniesApi = {
  async fetchCompanies(): Promise<CompanyDto[]> {
    const response = await apiClient.get<CompanyDto[]>('/api/companies')
    return response.data
  },

  async fetchCompany(id: number): Promise<CompanyDto> {
    const response = await apiClient.get<CompanyDto>(`/api/companies/${id}`)
    return response.data
  },

  async createCompany(data: CreateCompanyRequest): Promise<CompanyDto> {
    const response = await apiClient.post<CompanyDto>('/api/companies', data)
    return response.data
  },

  async updateCompany(id: number, data: UpdateCompanyRequest): Promise<CompanyDto> {
    const response = await apiClient.put<CompanyDto>(`/api/companies/${id}`, data)
    return response.data
  },

  async deleteCompany(id: number): Promise<void> {
    await apiClient.delete(`/api/companies/${id}`)
  },

  async switchActiveCompany(id: number): Promise<UserDto> {
    const response = await apiClient.post<UserDto>(`/api/active-company/${id}`)
    return response.data
  },
}

export type { CompanyDto, CreateCompanyRequest, UpdateCompanyRequest }
