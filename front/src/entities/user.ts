// User entity — типы и маппер из API ответа
// Синхронизировано с UserResource.php

export const USER_ROLES = [
  'admin',
  'director',
  'lawyer',
  'manager',
  'accountant',
  'cfo',
] as const

export type UserRole = (typeof USER_ROLES)[number]

export const DEFAULT_HOME_PATH = '/dashboard'

export function isValidUserRole(role: string): role is UserRole {
  return USER_ROLES.includes(role as UserRole)
}

export interface User {
  id: number
  email: string
  full_name: string
  role: UserRole
  telegram_user_id: string | null
  avatar_path: string | null
  department_id: number | null
  manager_id: number | null
  is_active: boolean
  locale: string | null
  totp_enabled: boolean
  created_at: string
  nav_quick_actions: string[]
}

/**
 * Смаппить API-ответ UserData → типизированный User
 */
export function mapUser(data: {
  id: number
  email: string
  full_name: string
  role: string | null
  telegram_user_id: string | null
  avatar_path: string | null
  department_id: number | null
  manager_id: number | null
  is_active: boolean
  locale: string | null
  totp_enabled: boolean
  created_at: string
  nav_quick_actions?: string[] | null
}): User {
  const role = data.role && isValidUserRole(data.role) ? data.role : 'manager'
  return {
    id: data.id,
    email: data.email,
    full_name: data.full_name,
    role,
    telegram_user_id: data.telegram_user_id,
    avatar_path: data.avatar_path,
    department_id: data.department_id,
    manager_id: data.manager_id,
    is_active: data.is_active,
    locale: data.locale,
    totp_enabled: data.totp_enabled,
    created_at: data.created_at,
    nav_quick_actions: data.nav_quick_actions ?? [],
  }
}
