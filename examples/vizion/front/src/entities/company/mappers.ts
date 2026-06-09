import type { CompanyDto } from '@/api/types'
import type { Company } from './types'

export const mapCompanyDtoToCompany = (companyDto: CompanyDto): Company => {
  return { ...companyDto }
}
