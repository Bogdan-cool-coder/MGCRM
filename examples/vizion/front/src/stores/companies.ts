import { defineStore } from 'pinia'
import type { Company } from '@/entities/company'
import {
  canSelectCompany,
  normalizeActiveCompany,
} from '@/shared/session/invariants'
import { companiesApi } from '@/api/companies'
import { mapUserDtoToUser } from '@/entities/user'
import { useUserStore } from '@/stores/user'
import { getApiErrorStatus } from '@/utils/errors'

interface ReconcileCompanyOptions {
  availableCompanyIds?: number[]
  preferredId?: number | null
}

/**
 * Outcome of `switchActiveCompany`. The store deliberately returns a typed
 * result instead of pushing a toast itself — display logic (i18n + notify)
 * lives in the caller (`CompanySwitcher`) via
 * `notifyCompanySwitchError(status, fallback)` from `application/session/`.
 *
 * `reason: 'in_progress'` means another switch is already running, the call
 * was rejected; `reason: 'invalid_target'` covers the not-in-list / null-id
 * guard. Both can be ignored silently by the UI.
 */
export type CompanySwitchResult =
  | { ok: true }
  | { ok: false; reason: 'in_progress' }
  | { ok: false; reason: 'invalid_target' }
  | { ok: false; reason: 'request_failed'; status: number | null; error: unknown }

export const useCompaniesStore = defineStore('companies', {
  state: () => ({
    activeCompanyId: null as number | null,
    companies: [] as Company[],
    isSwitching: false as boolean,
  }),

  getters: {
    getActiveCompanyId(): number | null {
      return this.activeCompanyId ?? null
    },

    getCurrentCompany(): Company | null {
      if (!this.activeCompanyId) {
        return null
      }

      return this.companies.find((company) => company.id === this.activeCompanyId) ?? null
    },

    getCompanies(): Company[] {
      return this.companies ?? []
    },

    getAvailableCompanyIds(): number[] {
      return this.getCompanies.map((c) => c.id)
    },

    getHasCompanies(): boolean {
      return this.getCompanies.length > 0
    },

    getIsCurrentCompanySelected(): boolean {
      return this.getCurrentCompany !== null
    },

    getIsSwitching(): boolean {
      return this.isSwitching
    },
  },

  actions: {
    setCompanies(companies: Company[], options?: ReconcileCompanyOptions) {
      this.companies = companies
      this.reconcileActiveCompany(options?.availableCompanyIds, options?.preferredId)
    },

    /**
     * Local-only mutation: validates id against available companies and writes
     * it into state. Use this ONLY for internal flows (reconcile after server
     * response, hydration, clear). For user-initiated switching call
     * `switchActiveCompany` instead — that hits the backend and is the source
     * of truth.
     */
    setActiveCompanyLocal(companyId: number | null) {
      const availableCompanyIds = this.getAvailableCompanyIds

      if (!canSelectCompany(companyId, availableCompanyIds)) {
        this.activeCompanyId = null
        return false
      }

      this.activeCompanyId = companyId
      return true
    },

    /**
     * Switches active company on the server (`POST /api/active-company/{id}`),
     * updates user store with the response and only then writes the new id
     * into local state. On 403/404/network error nothing is mutated locally —
     * the UI selection naturally falls back to whatever `getActiveCompanyId`
     * already was. Single-flight guarded via `isSwitching` flag.
     *
     * Returns a `CompanySwitchResult` discriminated union — the caller decides
     * how (or whether) to surface the failure. The store itself does NOT
     * import vue-i18n or notificationCenter (display layer concern).
     */
    async switchActiveCompany(companyId: number): Promise<CompanySwitchResult> {
      if (this.isSwitching) {
        return { ok: false, reason: 'in_progress' }
      }

      if (this.activeCompanyId === companyId) {
        return { ok: true }
      }

      if (!canSelectCompany(companyId, this.getAvailableCompanyIds)) {
        return { ok: false, reason: 'invalid_target' }
      }

      this.isSwitching = true
      const userStore = useUserStore()

      try {
        const userDto = await companiesApi.switchActiveCompany(companyId)
        const user = mapUserDtoToUser(userDto)
        userStore.setCurrentUser(user)
        this.activeCompanyId = user.active_company_id ?? companyId
        return { ok: true }
      } catch (error: unknown) {
        const status = getApiErrorStatus(error) ?? null

        if (import.meta.env.DEV) {
          console.error('[companies] switchActiveCompany failed', {
            companyId,
            status,
            error,
          })
        }

        return { ok: false, reason: 'request_failed', status, error }
      } finally {
        this.isSwitching = false
      }
    },

    reconcileActiveCompany(availableCompanyIds?: number[], preferredId?: number | null) {
      this.activeCompanyId = normalizeActiveCompany({
        activeCompanyId: this.activeCompanyId,
        availableCompanyIds: availableCompanyIds ?? this.getAvailableCompanyIds,
        preferredId,
      })
    },

    upsertCompany(company: Company) {
      const existingIndex = this.companies.findIndex((c) => c.id === company.id)
      if (existingIndex >= 0) {
        this.companies[existingIndex] = company
      } else {
        this.companies.push(company)
      }
    },

    clear() {
      this.setActiveCompanyLocal(null)
      this.companies = []
      this.isSwitching = false
    },

    removeCompany(id: number) {
      this.companies = this.companies.filter((company) => company.id !== id)
      this.reconcileActiveCompany()
    },
  },

  persist: {
    paths: ['activeCompanyId'],
  },
})

export type { Company }
