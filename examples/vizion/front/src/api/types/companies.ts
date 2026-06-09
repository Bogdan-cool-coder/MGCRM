export interface CompanyDto {
  id: number
  name: string
  is_system: boolean
  currency_code: string | null
  timezone: string | null
  crm_url?: string | null
  macrodata_host?: string
  macrodata_port?: number
  macrodata_database?: string
  macrodata_username?: string
  macrodata_password?: string
}

export interface CreateCompanyRequest {
  name: string
  currency_code?: string | null
  timezone?: string | null
  crm_url?: string | null
  macrodata_host?: string
  macrodata_port?: number
  macrodata_database?: string
  macrodata_username?: string
  macrodata_password?: string
}

export type UpdateCompanyRequest = Partial<CreateCompanyRequest>
