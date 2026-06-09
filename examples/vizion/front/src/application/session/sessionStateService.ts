import { getActivePinia, type Pinia } from 'pinia'
import { useChatsStore } from '@/stores/chats'
import { useCompaniesStore } from '@/stores/companies'
import { useUserStore } from '@/stores/user'
import { iframeTokenStorage } from '@/storage'
import { startNewLocaleSession } from '@/application/locale'
import { resetSessionCoordinatorRuntime } from './sessionCoordinator'

interface SessionStateOptions {
  pinia?: Pinia
}

interface AuthenticatedSessionStateOptions extends SessionStateOptions {
  clearIframeToken?: boolean
}

const resolvePinia = (options?: SessionStateOptions): Pinia | null => {
  return options?.pinia ?? getActivePinia() ?? null
}

export const clearSessionState = (options?: SessionStateOptions): void => {
  const resolvedPinia = resolvePinia(options)
  if (!resolvedPinia) {
    return
  }

  resetSessionCoordinatorRuntime(resolvedPinia)
  useUserStore(resolvedPinia).clearCurrentUser()
  useCompaniesStore(resolvedPinia).clear()
  useChatsStore(resolvedPinia).clear()
}

export const resetAuthenticatedSessionState = (
  options?: AuthenticatedSessionStateOptions,
): void => {
  const resolvedPinia = resolvePinia(options)
  startNewLocaleSession()
  if (options?.clearIframeToken) {
    iframeTokenStorage.clear()
  }
  if (resolvedPinia) {
    useUserStore(resolvedPinia).clearAuthenticatedUserState()
  }
  clearSessionState(options)
}
