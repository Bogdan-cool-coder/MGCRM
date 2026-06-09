import type { Pinia } from 'pinia'
import type { LoginRequest } from '@/api/types'
import type { AvailableLocales } from '@/plugins/i18n'
import { getApiErrorStatus, isUnauthorizedStatus } from '@/utils/errors'
import type { Services } from '@/services'
import { useUserStore, type User } from '@/stores/user'

export interface UserSessionService {
  readonly isAuthenticated: boolean
  readonly authToken: string | null
  readonly currentUser: User | null
  login(_data: LoginRequest): Promise<void>
  loginWithIframeToken(_iframeToken: string): Promise<void>
  logout(): Promise<void>
  refreshCurrentUser(): Promise<void>
  updateCurrentUserLocale(_locale: AvailableLocales): Promise<void>
  setHomePath(_path: string): Promise<string>
}

export const createUserSessionService = (options: {
  pinia: Pinia
  services: Services
}): UserSessionService => {
  const userStore = useUserStore(options.pinia)

  const login = async (data: LoginRequest): Promise<void> => {
    userStore.setAuthenticatedUserState(await options.services.authService.login(data))
  }

  const loginWithIframeToken = async (iframeToken: string): Promise<void> => {
    userStore.setAuthenticatedUserState(
      await options.services.authService.loginWithIframeToken(iframeToken),
    )
  }

  const logout = async (): Promise<void> => {
    let logoutError: unknown = null

    try {
      await options.services.authService.logout()
    } catch (error) {
      const status = getApiErrorStatus(error)

      if (!isUnauthorizedStatus(status)) {
        logoutError = error
      }
    } finally {
      userStore.clearAuthenticatedUserState()
    }

    if (logoutError) {
      throw logoutError
    }
  }

  const refreshCurrentUser = async (): Promise<void> => {
    userStore.setCurrentUser(await options.services.userService.fetchCurrentUser())
  }

  const updateCurrentUserLocale = async (locale: AvailableLocales): Promise<void> => {
    if (!userStore.getUser) {
      return
    }

    userStore.setCurrentUser(await options.services.userService.updateCurrentUserLocale(locale))
  }

  const setHomePath = async (path: string): Promise<string> => {
    const homePath = await options.services.userService.setHomePath(path)
    userStore.setHomePath(homePath)
    return homePath
  }

  return {
    get isAuthenticated(): boolean {
      return userStore.getIsAuthenticated
    },

    get authToken(): string | null {
      return userStore.getAuthCredential
    },

    get currentUser(): User | null {
      return userStore.getUser
    },

    login,
    loginWithIframeToken,
    logout,
    refreshCurrentUser,
    updateCurrentUserLocale,
    setHomePath,
  }
}
