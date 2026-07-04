import type { Router } from 'vue-router'
import type { Pinia } from 'pinia'
import { useUserStore } from '@/stores/user'
import { destroyEcho } from '@/composables/realtime/echo'
import { resetAllStores } from '@/application/session'

/**
 * Обработчик 401 от axios middleware.
 * Очищает стейт, разрывает WebSocket и редиректит на /login (fire-and-forget).
 */
export const createUnauthorizedHandler = (pinia: Pinia, router: Router) => {
  return () => {
    const userStore = useUserStore(pinia)
    // Полный сброс user-scoped сторов + persisted recentRoutes, чтобы данные
    // истёкшей сессии не протекли в следующую (единая точка teardown).
    resetAllStores(pinia)
    userStore.clearAuthenticatedUserState()
    // Закрыть Echo WebSocket при истечении токена
    destroyEcho()
    // Fire-and-forget — не await (см. bootstrap-deadlock gotcha)
    router.push('/login').catch(() => {})
  }
}
