import { DEFAULT_HOME_PATH, type User, type UserCompanyAccess } from '@/entities/user'
import { defineStore } from 'pinia'
import type { AvailableLocales } from '@/plugins/i18n'
import type { AuthSessionPayload } from '@/services/AuthService'

export const useUserStore = defineStore('user', {
  state: () => ({
    currentUser: null as User | null,
    token: null as string | null,
  }),

  getters: {
    getUser(): User | null {
      const user = this.currentUser
      if (!user || typeof user !== 'object') {
        return null
      }
      return Object.keys(user).length > 0 ? user : null
    },

    getUserName(): string {
      return this.currentUser?.name ?? ''
    },

    getUserEmail(): string {
      return this.currentUser?.email ?? ''
    },

    getUserRole(): string {
      return this.currentUser?.role ?? ''
    },

    getUserLocale(): AvailableLocales | null {
      // Only return user's locale from backend - no localStorage fallback
      // localStorage is handled by localeService as a separate persistence layer
      return this.currentUser?.locale ?? null
    },

    getHomePath(): string {
      return this.currentUser?.homePath ?? DEFAULT_HOME_PATH
    },

    getCompanyAccesses(): UserCompanyAccess[] {
      return this.currentUser?.company_accesses ?? []
    },

    getAvailableCompanyIds(): number[] {
      return this.getCompanyAccesses.map((ca) => ca.company_id)
    },

    getAuthCredential(): string | null {
      return this.token
    },

    getIsAuthenticated(): boolean {
      return !!this.getAuthCredential
    },
  },

  actions: {
    setAuthenticatedUserState(session: AuthSessionPayload) {
      this.token = session.token
      this.currentUser = session.user
    },

    setCurrentUser(user: User) {
      this.currentUser = user
    },

    setHomePath(homePath: string) {
      if (this.currentUser) {
        this.currentUser = { ...this.currentUser, homePath }
      }
    },

    clearCurrentUser() {
      this.currentUser = null
    },

    clearAuthCredential() {
      this.token = null
    },

    clearAuthenticatedUserState() {
      this.token = null
      this.currentUser = null
    },
  },

  persist: {
    paths: ['token'],
  },
})

export type { User }
