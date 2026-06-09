import { createRouter, createWebHistory } from 'vue-router'
import type { Pinia } from 'pinia'

import { routes } from '@/router/routes'
import { waitForBootstrapSession } from '@/application'

import { useUserStore } from '@/stores/user'

import { resolveNavigation } from '@/router/policy'
import { resetAuthenticatedSessionState } from '@/application'
import {
  clearStaleAssetReloadFlag,
  isStaleAssetError,
  reloadOnceForStaleAssets,
} from '@/utils/staleAssetRecovery'
import { isUnauthorizedError } from '@/utils/errors'

export const createAppRouter = (pinia: Pinia) => {
  const userStore = useUserStore(pinia)

  const router = createRouter({
    history: createWebHistory(import.meta.env.BASE_URL),
    routes,
  })

  const ensureNavigationAccessState = async () => {
    try {
      await waitForBootstrapSession()
    } catch (error) {
      if (isUnauthorizedError(error)) {
        resetAuthenticatedSessionState({ pinia })
        return null
      }

      throw error
    }

    return userStore.getUser
  }

  router.beforeEach(async (to) => {
    const user = await ensureNavigationAccessState()
    const isAuthenticated = !!user

    return resolveNavigation({
      to,
      isAuthenticated,
      user,
    })
  })

  router.afterEach(() => {
    clearStaleAssetReloadFlag()
  })

  router.onError((error) => {
    if (isStaleAssetError(error)) {
      reloadOnceForStaleAssets()
    }
  })

  return router
}
