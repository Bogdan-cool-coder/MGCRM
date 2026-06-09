import type { RouteLocationNormalized } from 'vue-router'

import { DEFAULT_HOME_PATH, type User } from '@/entities/user'
import { canUseDocuments } from '@/shared/auth/capabilities'
import { hasRoleAccess } from './access'
import { getDefaultRoute } from './navigation'
import { buildLoginRedirect } from './redirect'

export interface ResolveNavigationParams {
  to: RouteLocationNormalized
  isAuthenticated: boolean
  user: User | null
}

export type ResolveNavigationResult = ReturnType<typeof buildLoginRedirect> | string | true

export const resolveNavigation = ({
  to,
  isAuthenticated,
  user,
}: ResolveNavigationParams): ResolveNavigationResult => {
  if (to.meta.requiresAuth && !isAuthenticated) {
    return buildLoginRedirect(to.fullPath)
  }

  // Root path is dynamic: an authenticated user lands on their personal home
  // page (default `/reports`). Only the exact root `/` is rewritten — deep
  // links (e.g. `/reports/42` from a bookmark) are never overridden. If the
  // stored home page is itself `/` we fall back to the default to avoid a
  // redirect loop. When the user object isn't ready yet, use the safe default.
  if (to.path === '/' && isAuthenticated) {
    const homePath = user?.homePath || DEFAULT_HOME_PATH
    return homePath === '/' ? DEFAULT_HOME_PATH : homePath
  }

  if (to.path === '/login' && isAuthenticated && user) {
    return getDefaultRoute(user.role)
  }

  // Build-time feature gate. When the Documents section is disabled in this
  // build, a direct URL / bookmark to `/documents` (or `/documents/:id`) is
  // redirected away so the page is never reachable. Falls back to the default
  // route (the user object is guaranteed here — these routes also require
  // auth, handled above).
  if (to.meta.requiresFeature === 'documents' && !canUseDocuments(user?.role)) {
    return user ? getDefaultRoute(user.role) : DEFAULT_HOME_PATH
  }

  if (to.meta.roles?.length && user) {
    if (!hasRoleAccess(user.role, to.meta.roles)) {
      return getDefaultRoute(user.role)
    }
  }

  return true
}
