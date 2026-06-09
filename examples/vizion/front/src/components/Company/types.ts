export interface CompanyFormData {
  id?: number
  name: string
  crm_url: string
  /**
   * ISO 4217 3-letter currency code, uppercase. Empty string = "no preference"
   * → backend receives `null` (clears the stored code, formatter falls back
   * to RUB).
   */
  currency_code: string
  /**
   * IANA timezone identifier (e.g. "Europe/Moscow"). Empty string = "no
   * preference" → backend receives `null`, formatter falls back to "UTC".
   */
  timezone: string
  macrodata_host: string
  macrodata_port: string
  macrodata_database: string
  macrodata_username: string
  macrodata_password: string
}

export interface CompanyFormErrors {
  name?: string
  currency_code?: string
  timezone?: string
}
