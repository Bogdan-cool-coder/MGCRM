import type { UserRole } from '@/entities/user'

export const DEFAULT_ROUTE_BY_ROLE: Record<UserRole, string> = {
  superadmin: '/company',
  admin: '/company',
  analyst: '/reports',
  viewer: '/reports',
}

export const getDefaultRoute = (role: UserRole): string => {
  return DEFAULT_ROUTE_BY_ROLE[role]
}
