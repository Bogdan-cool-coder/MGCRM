import { useMutation } from './useMutation'
import type { MutationHooks, MutationState } from './contracts'
import { useApplicationServices } from '@/application'
import { runSessionMutationEffects } from '@/application/session'
import type { SessionMutationSync } from '@/shared/session/contracts'

type SessionAffectsResolver<TResult> = boolean | ((_result: TResult) => boolean)

export interface SessionMutationHooks<TResult> extends MutationHooks<TResult> {
  sync?: SessionMutationSync
  affectsSession?: SessionAffectsResolver<TResult>
  refreshScopedData?: () => Promise<void>
}

export interface SessionMutationState<TResult>
  extends Omit<MutationState<TResult>, 'run'> {
  run: (
    _mutation: () => Promise<TResult>,
    _hooks?: SessionMutationHooks<TResult>,
  ) => Promise<TResult>
}

export const useSessionMutation = <TResult = void>(): SessionMutationState<TResult> => {
  const baseMutation = useMutation<TResult>()
  const { sessionCoordinator } = useApplicationServices()

  const run: SessionMutationState<TResult>['run'] = async (
    mutation,
    hooks,
  ): Promise<TResult> => {
    const {
      sync = 'none',
      affectsSession,
      refreshScopedData,
      onSuccess,
      onError,
      onFinally,
    } = (hooks ?? {}) as SessionMutationHooks<TResult>

    return await baseMutation.run(mutation, {
      onSuccess: async (result) => {
        await runSessionMutationEffects(result, {
          sessionCoordinator,
          sync,
          affectsSession,
          refreshScopedData,
          onSuccess,
        })
      },
      onError,
      onFinally,
    })
  }

  return {
    ...baseMutation,
    run,
  }
}
