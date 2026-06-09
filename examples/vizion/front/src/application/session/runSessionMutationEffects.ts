import type { SessionCoordinator } from './sessionCoordinator'
import type { SessionMutationSync } from '@/shared/session/contracts'

type SessionAffectsResolver<TResult> = boolean | ((_result: TResult) => boolean)

export interface RunSessionMutationEffectsOptions<TResult> {
  sessionCoordinator: SessionCoordinator
  sync?: SessionMutationSync
  affectsSession?: SessionAffectsResolver<TResult>
  refreshScopedData?: () => Promise<void>
  onSuccess?: (_result: TResult) => Promise<void> | void
}

const resolveAffectsSession = <TResult>(
  affectsSession: SessionAffectsResolver<TResult> | undefined,
  result: TResult,
): boolean => {
  if (typeof affectsSession === 'function') {
    return affectsSession(result)
  }

  return affectsSession ?? false
}

export const runSessionMutationEffects = async <TResult>(
  result: TResult,
  options: RunSessionMutationEffectsOptions<TResult>,
): Promise<void> => {
  const {
    sessionCoordinator,
    sync = 'none',
    affectsSession,
    refreshScopedData,
    onSuccess,
  } = options

  if (sync === 'company') {
    await sessionCoordinator.refreshAfterCompanyMutation()
  } else if (sync === 'user') {
    await sessionCoordinator.refreshAfterUserMutation({
      affectsSession: resolveAffectsSession(affectsSession, result),
    })
  }

  await refreshScopedData?.()
  await onSuccess?.(result)
}

