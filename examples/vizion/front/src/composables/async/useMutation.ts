import { ref } from 'vue'
import type { MutationHooks, MutationState } from './contracts'

export const useMutation = <TResult = void>(): MutationState<TResult> => {
  const isPending = ref(false)
  const error = ref<unknown | null>(null)

  const run = async (
    mutation: () => Promise<TResult>,
    hooks?: MutationHooks<TResult>,
  ): Promise<TResult> => {
    isPending.value = true
    error.value = null

    try {
      const result = await mutation()
      await hooks?.onSuccess?.(result)
      return result
    } catch (nextError: unknown) {
      error.value = nextError
      await hooks?.onError?.(nextError)
      throw nextError
    } finally {
      isPending.value = false
      await hooks?.onFinally?.()
    }
  }

  const reset = () => {
    isPending.value = false
    error.value = null
  }

  return {
    isPending,
    error,
    run,
    reset,
  }
}
