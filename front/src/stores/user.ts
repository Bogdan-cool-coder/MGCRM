import { ref, computed } from 'vue'
import { defineStore } from 'pinia'
import type { AvailableLocales } from '@/plugins/i18n'
import { type User, type UserRole, mapUser, DEFAULT_HOME_PATH } from '@/entities/user'
import type { UserData } from '@/api/types/auth'

export type { User, UserRole }

export interface AuthSessionPayload {
  token: string
  user: User
}

export const useUserStore = defineStore(
  'user',
  () => {
    // ─── State ────────────────────────────────────────────────────────────
    const currentUser = ref<User | null>(null)
    const token = ref<string | null>(null)

    // ─── Getters ──────────────────────────────────────────────────────────
    const getUser = computed<User | null>(() => currentUser.value)

    const getUserName = computed<string>(() => currentUser.value?.full_name ?? '')

    const getUserEmail = computed<string>(() => currentUser.value?.email ?? '')

    const getUserRole = computed<UserRole | null>(() => currentUser.value?.role ?? null)

    const getUserLocale = computed<AvailableLocales | null>(() => {
      const locale = currentUser.value?.locale
      if (locale === 'ru' || locale === 'en') return locale
      return null
    })

    const getHomePath = computed<string>(() => DEFAULT_HOME_PATH)

    const isTotpEnabled = computed<boolean>(() => currentUser.value?.totp_enabled ?? false)

    const getAvatarPath = computed<string | null>(() => currentUser.value?.avatar_path ?? null)

    const getAuthCredential = computed<string | null>(() => token.value)

    const getIsAuthenticated = computed<boolean>(() => !!token.value)

    // ─── Actions ──────────────────────────────────────────────────────────
    function setAuthenticatedUserState(session: AuthSessionPayload): void {
      token.value = session.token
      currentUser.value = session.user
    }

    function setCurrentUser(userData: UserData): void {
      currentUser.value = mapUser(userData)
    }

    function clearCurrentUser(): void {
      currentUser.value = null
    }

    function clearAuthCredential(): void {
      token.value = null
    }

    function clearAuthenticatedUserState(): void {
      token.value = null
      currentUser.value = null
    }

    return {
      // State (exposed for $patch and persist)
      currentUser,
      token,
      // Getters
      getUser,
      getUserName,
      getUserEmail,
      getUserRole,
      getUserLocale,
      getHomePath,
      isTotpEnabled,
      getAvatarPath,
      getAuthCredential,
      getIsAuthenticated,
      // Actions
      setAuthenticatedUserState,
      setCurrentUser,
      clearCurrentUser,
      clearAuthCredential,
      clearAuthenticatedUserState,
    }
  },
  {
    persist: {
      pick: ['token'],
    },
  },
)
