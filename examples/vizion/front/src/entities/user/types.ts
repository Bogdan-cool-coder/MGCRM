import type { AvailableLocales } from '@/plugins/i18n'
import type { Company } from '@/entities/company'

export const DEFAULT_USER_ROLES = {
  superadmin: 'superadmin',
  admin: 'admin',
  analyst: 'analyst',
  viewer: 'viewer',
} as const

export type UserRole = keyof typeof DEFAULT_USER_ROLES

export interface UserCompanyAccess {
  company_id: number
  role: string
}

export interface User {
  id: number
  name: string
  email: string
  role: UserRole
  locale: AvailableLocales
  /**
   * Preferred home page as a relative route path (default `/reports`).
   * The root `/` redirects here after login. Camel-cased from the
   * backend's `home_path` in `mapUserDtoToUser`.
   */
  homePath: string
  company_id: number
  active_company_id: number | null
  active_company: Company | null
  company_accesses: UserCompanyAccess[]
}

export const DEFAULT_HOME_PATH = '/reports'
