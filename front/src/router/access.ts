import type { UserRole } from '@/entities/user'

/**
 * Матрица ролевого доступа для маршрутов.
 * Fail-closed: если роль не в списке → нет доступа.
 */
export function hasRoleAccess(userRole: UserRole, allowedRoles: UserRole[]): boolean {
  return allowedRoles.includes(userRole)
}

/**
 * Лэндинговая страница по роли.
 * Root `/` → динамически через эту функцию.
 */
export const DEFAULT_ROUTE_BY_ROLE: Record<UserRole, string> = {
  admin: '/dashboard',
  director: '/dashboard',
  lawyer: '/dashboard',
  manager: '/dashboard',
  accountant: '/dashboard',
  cfo: '/dashboard',
}

export function getDefaultRoute(role: UserRole | string): string {
  const validRole = role as UserRole
  return DEFAULT_ROUTE_BY_ROLE[validRole] ?? '/dashboard'
}
