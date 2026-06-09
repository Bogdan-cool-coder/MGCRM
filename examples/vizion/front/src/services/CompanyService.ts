import { companiesApi } from '@/api/companies'
import { mapCompanyDtoToCompany, type Company } from '@/entities/company'
import { mapUserDtoToUser, type User } from '@/entities/user'
import type { CreateCompanyRequest, UpdateCompanyRequest } from '@/api/types'

export class CompanyService {
  async fetchCompanies(): Promise<Company[]> {
    return (await companiesApi.fetchCompanies()).map(mapCompanyDtoToCompany)
  }

  async fetchCompany(id: number): Promise<Company> {
    return mapCompanyDtoToCompany(await companiesApi.fetchCompany(id))
  }

  async createCompany(data: CreateCompanyRequest): Promise<Company> {
    return mapCompanyDtoToCompany(await companiesApi.createCompany(data))
  }

  async updateCompanyById(id: number, data: UpdateCompanyRequest): Promise<Company> {
    return mapCompanyDtoToCompany(await companiesApi.updateCompany(id, data))
  }

  async deleteCompanyById(id: number): Promise<void> {
    await companiesApi.deleteCompany(id)
  }

  async switchActiveCompany(id: number): Promise<User> {
    return mapUserDtoToUser(await companiesApi.switchActiveCompany(id))
  }
}
