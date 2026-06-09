import { DEFAULT_LOCALE, isValidLocale } from '@/plugins/i18n'
import type { UserDto } from '@/api/types'
import { mapCompanyDtoToCompany } from '@/entities/company'
import { DEFAULT_HOME_PATH, DEFAULT_USER_ROLES } from './types'
import type { User, UserRole } from './types'

const VALID_ROLES: UserRole[] = Object.keys(DEFAULT_USER_ROLES) as UserRole[]

export const isValidUserRole = (role: string): role is UserRole => {
  return VALID_ROLES.includes(role as UserRole)
}

export const mapUserDtoToUser = (userDto: UserDto): User => {
  const role = isValidUserRole(userDto.role) ? userDto.role : 'viewer'

  if (!isValidLocale(userDto.locale) && import.meta.env.DEV) {
    console.warn(
      `[userEntity] Unknown locale from backend: "${userDto.locale}". Falling back to "${DEFAULT_LOCALE}"`,
    )
  }

  return {
    ...userDto,
    role,
    locale: isValidLocale(userDto.locale) ? userDto.locale : DEFAULT_LOCALE,
    homePath: normalizeHomePath(userDto.home_path),
    active_company_id: userDto.active_company_id ?? null,
    active_company: userDto.active_company ? mapCompanyDtoToCompany(userDto.active_company) : null,
  }
}

/**
 * Backend guarantees a relative `home_path` string, but older payloads or
 * MSW mocks may omit it / send null. Falls back to the shared default so the
 * home-star and root redirect always have a usable target.
 */
export const normalizeHomePath = (path: string | null | undefined): string => {
  const trimmed = typeof path === 'string' ? path.trim() : ''
  return trimmed || DEFAULT_HOME_PATH
}
