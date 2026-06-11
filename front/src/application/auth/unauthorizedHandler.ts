import type { Router } from 'vue-router'
import type { Pinia } from 'pinia'
import { useUserStore } from '@/stores/user'

/**
 * Обработчик 401 от axios middleware.
 * Очищает стейт и редиректит на /login (fire-and-forget).
 */
export const createUnauthorizedHandler = (pinia: Pinia, router: Router) => {
  return () => {
    const userStore = useUserStore(pinia)
    userStore.clearAuthenticatedUserState()
    // Fire-and-forget — не await (см. bootstrap-deadlock gotcha)
    router.push('/login').catch(() => {})
  }
}
