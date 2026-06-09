import { createRequestGate } from '@/utils/requestGate'

interface CompanySyncServiceOptions {
  onEnterCompanyScope: (_companyId: number) => Promise<void> | void
  onLeaveCompanyScope?: () => Promise<void> | void
}

export interface CompanySyncService {
  sync: (_companyId: number | null, _force?: boolean) => Promise<number | null | undefined>
  reset: () => void
}

export const createCompanySyncService = (
  options: CompanySyncServiceOptions,
): CompanySyncService => {
  const scopeGate = createRequestGate()
  let syncedCompanyId: number | null = null

  const sync: CompanySyncService['sync'] = async (companyId, force = false) => {
    const requestToken = scopeGate.next()

    if (!force && syncedCompanyId === companyId) {
      return companyId
    }

    if (syncedCompanyId !== null && syncedCompanyId !== companyId) {
      await options.onLeaveCompanyScope?.()

      if (!scopeGate.isCurrent(requestToken)) {
        return companyId
      }

      syncedCompanyId = null
    }

    if (companyId === null) {
      syncedCompanyId = null
      return companyId
    }

    await options.onEnterCompanyScope(companyId)

    if (!scopeGate.isCurrent(requestToken)) {
      return companyId
    }

    syncedCompanyId = companyId
    return companyId
  }

  const reset = () => {
    scopeGate.invalidate()
    syncedCompanyId = null
  }

  return {
    sync,
    reset,
  }
}
