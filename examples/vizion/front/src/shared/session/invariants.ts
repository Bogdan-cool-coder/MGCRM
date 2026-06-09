interface ResolveAllowedCompanyIdsOptions {
  role?: string | null
  availableCompanyIds: number[]
}

interface NormalizeActiveCompanyOptions {
  activeCompanyId: number | null
  availableCompanyIds?: number[]
  preferredId?: number | null
}

interface AuthenticatedSessionState {
  token: string | null
  currentUser: unknown
}

export const hasAuthenticatedSession = ({
  token,
  currentUser,
}: AuthenticatedSessionState): boolean => {
  return Boolean(token && currentUser)
}

export const shouldClearCompanyScope = (isAuthenticated: boolean): boolean => {
  return !isAuthenticated
}

export const resolveAllowedCompanyIds = ({
  role,
  availableCompanyIds,
}: ResolveAllowedCompanyIdsOptions): number[] | undefined => {
  if (role === 'superadmin') {
    return undefined
  }

  return availableCompanyIds
}

export const normalizeActiveCompany = ({
  activeCompanyId,
  availableCompanyIds,
  preferredId,
}: NormalizeActiveCompanyOptions): number | null => {
  const validCompanyIds = availableCompanyIds ?? []

  if (validCompanyIds.length === 0) {
    return null
  }

  if (preferredId && validCompanyIds.includes(preferredId)) {
    return preferredId
  }

  if (activeCompanyId && validCompanyIds.includes(activeCompanyId)) {
    return activeCompanyId
  }

  return validCompanyIds[0] ?? null
}

export const canSelectCompany = (
  companyId: number | null,
  availableCompanyIds: number[],
): boolean => {
  if (companyId === null) {
    return true
  }

  return availableCompanyIds.includes(companyId)
}
