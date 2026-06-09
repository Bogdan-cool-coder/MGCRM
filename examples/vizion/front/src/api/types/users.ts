import type { CompanyDto } from './companies'

export interface UserCompanyAccessDto {
  company_id: number
  role: string
}

export interface UserDto {
  id: number
  name: string
  email: string
  role: string
  locale: string
  /**
   * User's preferred home page — a relative route path (e.g. `/reports`).
   * Backend always returns a string and normalises `null` to the default
   * `/reports`. The root path `/` redirects here after login.
   */
  home_path: string
  company_id: number
  active_company_id: number | null
  active_company: CompanyDto | null
  company_accesses: UserCompanyAccessDto[]
}

export interface UserIframeLinkResponse {
  iframe_url: string | null
}

export interface SetHomePathRequest {
  path: string
}

export interface SetHomePathResponse {
  home_path: string
}

export interface CreateUserRequest {
  name: string
  email: string
  password: string
  role: string
  company_id: number
  locale?: string
}

export interface UpdateUserRequest {
  name?: string
  email?: string
  password?: string
  role?: string
  company_id?: number
  locale?: string
}
