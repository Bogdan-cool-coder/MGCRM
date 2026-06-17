import { createRouter, createWebHistory } from 'vue-router'
import type { Pinia } from 'pinia'
import { routes } from '@/router/routes'
import { waitForBootstrapSession } from '@/application/bootstrap'
import { useUserStore } from '@/stores/user'
import { resolveNavigation } from '@/router/policy'
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
        userStore.clearAuthenticatedUserState()
        return null
      }
      throw error
    }
    return userStore.getUser
  }

  router.beforeEach(async (to) => {
    const user = await ensureNavigationAccessState()
    const isAuthenticated = !!user

    return resolveNavigation({ to, isAuthenticated, user })
  })

  return router
}
