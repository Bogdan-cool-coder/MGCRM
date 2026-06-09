import type { Pinia } from 'pinia'
import { createCompanySelectionService } from '@/application/company/companySelectionService'
import { localeManager } from '@/application/locale'
import type { SessionMutationOptions, SessionOptions } from '@/shared/session/contracts'
import type { Services } from '@/services'
import type { UserSessionService } from '@/application/session/userSessionService'
import { hasAuthenticatedSession, shouldClearCompanyScope } from '@/shared/session/invariants'

type SingleFlightRunner<T> = (_task: () => Promise<T>) => Promise<T>

type SessionRuntimeState = {
  flights: {
    initializeSession: SingleFlightRunner<void>
    initializeAuthenticatedSession: SingleFlightRunner<void>
    ensureCompanySelected: SingleFlightRunner<number | null>
  }
  hydratedAuthToken: string | null
  hasInitializedAnonymousScope: boolean
}

const sessionRuntimeByPinia = new WeakMap<Pinia, SessionRuntimeState>()

const createSingleFlight = <T>(): SingleFlightRunner<T> => {
  let inFlight: Promise<T> | null = null

  return (task) => {
    if (!inFlight) {
      inFlight = task().finally(() => {
        inFlight = null
      })
    }

    return inFlight
  }
}

const createSessionRuntimeState = (): SessionRuntimeState => ({
  flights: {
    initializeSession: createSingleFlight<void>(),
    initializeAuthenticatedSession: createSingleFlight<void>(),
    ensureCompanySelected: createSingleFlight<number | null>(),
  },
  hydratedAuthToken: null,
  hasInitializedAnonymousScope: false,
})

const getSessionRuntime = (pinia: Pinia): SessionRuntimeState => {
  const existingRuntime = sessionRuntimeByPinia.get(pinia)
  if (existingRuntime) {
    return existingRuntime
  }

  const runtime = createSessionRuntimeState()
  sessionRuntimeByPinia.set(pinia, runtime)
  return runtime
}

export interface SessionCoordinator {
  initializeSession(_options?: SessionOptions): Promise<void>
  ensureCompanySelected(_options?: SessionOptions): Promise<number | null>
  refreshUser(): Promise<void>
  refreshCompanies(): Promise<void>
  refreshAfterCompanyMutation(): Promise<number | null>
  refreshAfterUserMutation(_options?: SessionMutationOptions): Promise<number | null>
  reconcileSession(): void
}

export const resetSessionCoordinatorRuntime = (pinia: Pinia): void => {
  sessionRuntimeByPinia.delete(pinia)
}

export const createSessionCoordinator = (options: {
  pinia: Pinia
  services: Services
  userSessionService: UserSessionService
}): SessionCoordinator => {
  const userSession = options.userSessionService
  const companySelection = createCompanySelectionService({
    pinia: options.pinia,
    services: options.services,
  })
  const runtime = getSessionRuntime(options.pinia)

  const syncSessionLocale = (initialLocale?: SessionOptions['initialLocale']) => {
    localeManager.syncOnce(initialLocale)
  }

  const refreshUser = async () => {
    await userSession.refreshCurrentUser()
  }

  const refreshCompanies = async () => {
    await companySelection.refreshCompanies()
  }

  const reconcileSession = () => {
    companySelection.reconcile()
  }

  const hydrateAuthenticatedSession = async () => {
    await refreshUser()
    await refreshCompanies()
  }

  const canReuseAuthenticatedSession = (authToken: string | null): boolean => {
    return (
      hasAuthenticatedSession({
        token: authToken,
        currentUser: userSession.currentUser,
      }) &&
      runtime.hydratedAuthToken === authToken &&
      companySelection.hasCompanies
    )
  }

  const initializeAnonymousSession = (sessionOptions?: SessionOptions) => {
    if (runtime.hasInitializedAnonymousScope) {
      companySelection.clear()
      return
    }

    syncSessionLocale(sessionOptions?.initialLocale)
    companySelection.clear()
    runtime.hydratedAuthToken = null
    runtime.hasInitializedAnonymousScope = true
  }

  const initializeAuthenticatedSession = async (sessionOptions?: SessionOptions) => {
    const authToken = userSession.authToken

    if (canReuseAuthenticatedSession(authToken)) {
      syncSessionLocale(sessionOptions?.initialLocale)
      return
    }

    await runtime.flights.initializeAuthenticatedSession(async () => {
      await hydrateAuthenticatedSession()
      syncSessionLocale(sessionOptions?.initialLocale)
      runtime.hydratedAuthToken = authToken
      runtime.hasInitializedAnonymousScope = false
    })
  }

  const initializeSession = async (sessionOptions?: SessionOptions) => {
    await runtime.flights.initializeSession(async () => {
      if (shouldClearCompanyScope(userSession.isAuthenticated)) {
        initializeAnonymousSession(sessionOptions)
        return
      }

      await initializeAuthenticatedSession(sessionOptions)
    })
  }

  const ensureCompanySelected = async (sessionOptions?: SessionOptions): Promise<number | null> => {
    return runtime.flights.ensureCompanySelected(async () => {
      await initializeSession(sessionOptions)
      reconcileSession()
      return companySelection.activeCompanyId
    })
  }

  const refreshAfterCompanyMutation = async (): Promise<number | null> => {
    await refreshCompanies()
    return companySelection.activeCompanyId
  }

  const refreshAfterUserMutation = async (
    mutationOptions?: SessionMutationOptions,
  ): Promise<number | null> => {
    if (!mutationOptions?.affectsSession) {
      reconcileSession()
      return companySelection.activeCompanyId
    }

    await hydrateAuthenticatedSession()
    return companySelection.activeCompanyId
  }

  return {
    initializeSession,
    ensureCompanySelected,
    refreshUser,
    refreshCompanies,
    refreshAfterCompanyMutation,
    refreshAfterUserMutation,
    reconcileSession,
  }
}
