import type { Pinia } from 'pinia'
import type { Router } from 'vue-router'
import type { UserSessionService } from '@/application/session'
import type { SessionCoordinator } from '@/application/session'
import type { AvailableLocales } from '@/plugins/i18n'
import { useUserStore } from '@/stores/user'
import { getDefaultRoute } from '@/router/navigation'
import { normalizePathname, parseRedirectTarget } from '@/router/redirect'
import { iframeTokenStorage } from '@/storage'
import { notificationCenter } from '@/application/notificationCenter'
import { resetAuthenticatedSessionState } from '@/application/session'
import { i18n } from '@/plugins/i18n'
import { isUnauthorizedError } from '@/utils/errors'

let bootstrapSessionId = crypto.randomUUID()

type BootstrapApplicationServices = {
  userSessionService: UserSessionService
  sessionCoordinator: SessionCoordinator
}

type UserStore = ReturnType<typeof useUserStore>

type BootstrapRequestContext = {
  redirectFromUrl: ReturnType<typeof parseRedirectTarget>
  iframeTokenFromUrl: string | null
  iframeToken: string | null
  normalizedPathname: string
  requestedPath: string
}

const createBootstrapRunGuard = () => {
  const currentSessionId = crypto.randomUUID()
  bootstrapSessionId = currentSessionId

  return () => currentSessionId === bootstrapSessionId
}

const resolveBootstrapRequestContext = (): BootstrapRequestContext => {
  const urlParams = new URLSearchParams(window.location.search)
  const redirectFromUrl = parseRedirectTarget(urlParams.get('redirect'))
  const iframeTokenFromUrl = urlParams.get('token')
  const iframeToken = iframeTokenFromUrl ?? redirectFromUrl?.iframeToken ?? iframeTokenStorage.get()

  urlParams.delete('token')
  if (redirectFromUrl?.sanitizedTarget) {
    urlParams.set('redirect', redirectFromUrl.sanitizedTarget)
  } else {
    urlParams.delete('redirect')
  }

  const requestSearch = urlParams.toString()
  const normalizedPathname = normalizePathname(window.location.pathname)
  const requestedPath =
    normalizedPathname + (requestSearch ? `?${requestSearch}` : '') + window.location.hash

  return {
    redirectFromUrl,
    iframeTokenFromUrl,
    iframeToken,
    normalizedPathname,
    requestedPath,
  }
}

const persistIframeTokenFromRequest = (request: BootstrapRequestContext): void => {
  if (
    !request.iframeToken ||
    (!request.iframeTokenFromUrl && !request.redirectFromUrl?.iframeToken)
  ) {
    return
  }

  iframeTokenStorage.save(request.iframeToken)
  history.replaceState(null, '', request.requestedPath)
}

const authenticateWithIframeToken = async (
  request: BootstrapRequestContext,
  userStore: UserStore,
  userSessionService: UserSessionService,
): Promise<void> => {
  if (!request.iframeToken) {
    return
  }

  persistIframeTokenFromRequest(request)

  if (userStore.getIsAuthenticated) {
    return
  }

  try {
    await userSessionService.loginWithIframeToken(request.iframeToken)
  } catch (e) {
    notificationCenter.error(i18n.global.t('errors.unauthorized'))
    if (import.meta.env.DEV) {
      console.warn('[bootstrap] Iframe auth failed:', e)
    }
  }
}

const getDefaultRouteForCurrentUser = (userStore: UserStore): string => {
  const user = userStore.getUser
  return user ? getDefaultRoute(user.role) : getDefaultRoute('viewer')
}

// `parseRedirectTarget('/')` returns `{ sanitizedTarget: '/' }`, which is truthy
// and short-circuits the `??` chain below. But `'/'` is not a real destination —
// the route table redirects it to the role default anyway. Replacing it with a
// real default here avoids:
//   1. `router.replace('/')` triggering the route-level `{ path: '/', redirect: '/reports' }`
//      mid-bootstrap, which fires `beforeEach` → `waitForBootstrapSession()` → deadlocks
//      against the in-flight bootstrap promise → `router.isReady()` never resolves →
//      `app.mount('#app')` never fires (grey screen).
//   2. An extra guard cycle even when no deadlock occurs.
const sanitizeRealTarget = (target: string | null | undefined): string | null => {
  if (!target || target === '/') return null
  return target
}

const resolveIframeLoginTarget = (
  request: BootstrapRequestContext,
  userStore: UserStore,
): string => {
  const target =
    sanitizeRealTarget(request.redirectFromUrl?.sanitizedTarget) ??
    sanitizeRealTarget(parseRedirectTarget(request.requestedPath)?.sanitizedTarget) ??
    getDefaultRouteForCurrentUser(userStore)

  // TODO(2026-05-19): remove after verifying iframe auth redirect in prod console.
  console.log('[bootstrap] resolveIframeLoginTarget', {
    requestedPath: request.requestedPath,
    normalizedPathname: request.normalizedPathname,
    redirectFromUrl: request.redirectFromUrl,
    parsedFromRequestedPath: parseRedirectTarget(request.requestedPath),
    userRole: userStore.getUser?.role,
    resolvedTarget: target,
  })

  return target
}

const redirectAfterAuthenticatedBootstrap = async (
  request: BootstrapRequestContext,
  userStore: UserStore,
  router: Router,
): Promise<void> => {
  // TODO(2026-05-19): remove after verifying iframe auth redirect in prod console.
  console.log('[bootstrap] redirectAfterAuthenticatedBootstrap entry', {
    hasIframeToken: !!request.iframeToken,
    normalizedPathname: request.normalizedPathname,
    requestedPath: request.requestedPath,
    userRole: userStore.getUser?.role,
    isAuthenticated: userStore.getIsAuthenticated,
  })

  // IMPORTANT: never `await` router navigations inside bootstrap.
  // The router's `beforeEach` guard calls `waitForBootstrapSession()`, which
  // awaits the very promise we are currently running inside. Awaiting the
  // navigation here creates a deadlock: bootstrap waits for navigation,
  // navigation waits for guard, guard waits for bootstrap → `router.isReady()`
  // never resolves → `app.mount('#app')` never fires → grey screen.
  //
  // Fire-and-forget instead. Bootstrap resolves immediately, which unblocks the
  // guard; the navigation then completes on its own and `router.isReady()`
  // resolves before `app.mount('#app')` runs in `main.ts`.
  if (request.iframeToken && request.normalizedPathname === '/login') {
    const target = resolveIframeLoginTarget(request, userStore)
    console.log('[bootstrap] branch: iframeToken + /login → router.replace', target)
    router.replace(target).catch(() => {})
    console.log('[bootstrap] router.replace dispatched (fire-and-forget)')
    return
  }

  if (request.iframeToken) {
    const target = resolveIframeLoginTarget(request, userStore)
    console.log('[bootstrap] branch: iframeToken → router.replace', target)
    router.replace(target).catch(() => {})
    console.log('[bootstrap] router.replace dispatched (fire-and-forget)')
    return
  }

  if (request.requestedPath === '/') {
    const target = getDefaultRouteForCurrentUser(userStore)
    console.log('[bootstrap] branch: requestedPath=/ → router.push', target)
    router.push(target).catch(() => {})
    console.log('[bootstrap] router.push dispatched (fire-and-forget)')
  }
}

const handleAuthenticatedBootstrapError = async (
  error: unknown,
  request: BootstrapRequestContext,
  pinia: Pinia,
  userStore: UserStore,
  userSessionService: UserSessionService,
  router: Router,
): Promise<void> => {
  if (!isUnauthorizedError(error)) {
    notificationCenter.error(i18n.global.t('errors.serverError'))
    if (import.meta.env.DEV) {
      console.error('[bootstrap] Failed to initialize app:', error)
    }
    return
  }

  try {
    if (userStore.getIsAuthenticated) {
      await userSessionService.logout()
    }
  } finally {
    resetAuthenticatedSessionState({ pinia })
    if (request.normalizedPathname !== '/login') {
      // Fire-and-forget — same deadlock concern as above
      // (see redirectAfterAuthenticatedBootstrap comment).
      router.push('/login').catch(() => {})
    }
  }
}

export const bootstrapApp = async (
  pinia: Pinia,
  applicationServices: BootstrapApplicationServices,
  router: Router,
  options?: { initialLocale?: AvailableLocales },
) => {
  const isCurrentSession = createBootstrapRunGuard()
  const userStore = useUserStore(pinia)
  const { sessionCoordinator, userSessionService } = applicationServices
  const request = resolveBootstrapRequestContext()

  await authenticateWithIframeToken(request, userStore, userSessionService)

  try {
    await sessionCoordinator.initializeSession({
      initialLocale: options?.initialLocale,
    })
    if (!isCurrentSession()) return

    if (userStore.getIsAuthenticated) {
      await redirectAfterAuthenticatedBootstrap(request, userStore, router)
    }
  } catch (error: unknown) {
    await handleAuthenticatedBootstrapError(
      error,
      request,
      pinia,
      userStore,
      userSessionService,
      router,
    )
  }

  sessionCoordinator.reconcileSession()
}
