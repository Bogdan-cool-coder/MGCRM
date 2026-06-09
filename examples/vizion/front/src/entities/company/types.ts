export interface CompanyAccess {
  company_id: number
  role: string
}

export interface Company {
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
