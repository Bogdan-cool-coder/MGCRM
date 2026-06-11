import type { RouteLocationNormalized } from 'vue-router'
import type { User } from '@/entities/user'
import { hasRoleAccess, getDefaultRoute } from './access'

export interface ResolveNavigationParams {
  to: RouteLocationNormalized
  isAuthenticated: boolean
  user: User | null
}

export type ResolveNavigationResult = { name: string; query?: Record<string, string> } | string | true

/**
 * Политика навигации (fail-closed).
 * Вызывается из router.beforeEach после bootstrapPromise.
 */
export const resolveNavigation = ({
  to,
  isAuthenticated,
  user,
}: ResolveNavigationParams): ResolveNavigationResult => {
  // Требует авторизации — нет токена → на логин
  if (to.meta.requiresAuth && !isAuthenticated) {
    const query: Record<string, string> = {}
    if (to.fullPath !== '/') {
      query['redirect'] = to.fullPath
    }
    return { name: 'Login', query }
  }

  // Root `/` — динамически по роли пользователя
  // НЕ статичный redirect: тот применяется до beforeEach и ломает deadlock guard
  if (to.path === '/' && isAuthenticated && user) {
    return getDefaultRoute(user.role)
  }

  // Уже авторизован → с логина на дашборд
  if (to.path === '/login' && isAuthenticated && user) {
    return getDefaultRoute(user.role)
  }

  // Ролевой доступ — fail-closed
  if (to.meta.roles && user) {
    const roles = to.meta.roles as string[]
    if (!hasRoleAccess(user.role, roles as import('@/entities/user').UserRole[])) {
      return getDefaultRoute(user.role)
    }
  }

  return true
}
