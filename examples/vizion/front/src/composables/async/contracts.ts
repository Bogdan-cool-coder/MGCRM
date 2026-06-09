import type { Ref } from 'vue'

export interface MutationHooks<TResult> {
  onSuccess?: (_result: TResult) => void | Promise<void>
  onError?: (_error: unknown) => void | Promise<void>
  onFinally?: () => void | Promise<void>
}

export interface MutationState<TResult> {
  isPending: Ref<boolean>
  error: Ref<unknown | null>
  run: (
    _mutation: () => Promise<TResult>,
    _hooks?: MutationHooks<TResult>,
  ) => Promise<TResult>
  reset: () => void
}

export type AsyncResourceCommit<T> = (_value: T) => void
