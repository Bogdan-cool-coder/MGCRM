import type { Pinia } from 'pinia'
import type { Router } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { authApi } from '@/api/auth'
import { isUnauthorizedError } from '@/utils/errors'
import { initEcho } from '@/composables/realtime/echo'

/**
 * Bootstrap-promise: инициализирует сессию при старте приложения.
 *
 * ВАЖНО: router.replace/push здесь всегда fire-and-forget (без await).
 * Причина: router.beforeEach ожидает bootstrapPromise, и await nav здесь
 * создаёт deadlock → router.isReady() не резолвится → серый экран.
 */
export const bootstrapApp = async (pinia: Pinia, router: Router): Promise<void> => {
  const userStore = useUserStore(pinia)

  // Если нет токена — анонимная сессия, редиректа не нужно
  if (!userStore.getIsAuthenticated) {
    return
  }

  try {
    // Токен есть — загружаем текущего пользователя
    const meResponse = await authApi.me()
    userStore.setCurrentUser(meResponse.data)

    // Инициализируем Echo WebSocket после успешного восстановления сессии.
    // Graceful-degradation: если VITE_REVERB_* не заданы или сервер недоступен,
    // initEcho является no-op и приложение продолжает работу без live-обновлений.
    const token = userStore.getAuthCredential
    if (token) {
      initEcho(token)
    }

    // Если на корне — редиректим на дашборд (fire-and-forget)
    const currentPath = window.location.pathname
    if (currentPath === '/') {
      router.push('/dashboard').catch(() => {})
    }
  } catch (error: unknown) {
    if (isUnauthorizedError(error)) {
      // Токен протух — очищаем и идём на логин
      userStore.clearAuthenticatedUserState()
      const currentPath = window.location.pathname
      if (currentPath !== '/login') {
        router.push('/login').catch(() => {})
      }
    } else {
      // Прочая ошибка — логируем, но не ломаем приложение
      if (import.meta.env.DEV) {
        console.warn('[bootstrap] Failed to initialize session:', error)
      }
    }
  }
}
