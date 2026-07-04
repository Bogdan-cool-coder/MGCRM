import { createRouter, createWebHistory } from 'vue-router'
import type { Pinia } from 'pinia'
import { routes } from '@/router/routes'
import { waitForBootstrapSession } from '@/application/bootstrap'
import { useUserStore } from '@/stores/user'
import { resolveNavigation } from '@/router/policy'
import { isUnauthorizedError } from '@/utils/errors'
import { i18n } from '@/plugins/i18n'

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

  // Set document.title from the route's meta.title (an i18n key). Falls back to
  // the plain app name when a route declares no title.
  router.afterEach((to) => {
    const { t } = i18n.global
    const titleKey = to.meta.title
    document.title = titleKey
      ? t('app.titleTemplate', { page: t(titleKey) })
      : t('app.title')
  })

  return router
}
