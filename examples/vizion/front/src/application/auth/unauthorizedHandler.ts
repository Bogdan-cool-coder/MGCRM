import type { Pinia } from 'pinia'
import type { Router } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { resetAuthenticatedSessionState } from '@/application/session'

interface UnauthorizedHandlerOptions {
  pinia: Pinia
  router: Router
}

export const createUnauthorizedHandler = (options: UnauthorizedHandlerOptions): (() => void) => {
  let handlingUnauthorized = false

  return () => {
    if (handlingUnauthorized) {
      return
    }

    const userStore = useUserStore(options.pinia)
    if (!userStore.getAuthCredential) {
      return
    }

    handlingUnauthorized = true
    resetAuthenticatedSessionState({ pinia: options.pinia, clearIframeToken: true })
    void options.router.push('/login').finally(() => {
      handlingUnauthorized = false
    })
  }
}
