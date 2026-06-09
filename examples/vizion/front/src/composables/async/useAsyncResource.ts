import { ref, type Ref } from 'vue'
import { createRequestGate } from '@/utils/requestGate'
import type { AsyncResourceCommit } from './contracts'

type InitialValueFactory<T> = T | (() => T)

interface RunAsyncResourceOptions<T> {
  commit?: AsyncResourceCommit<T>
}

const resolveInitialValue = <T>(initialValue: InitialValueFactory<T>): T => {
  return typeof initialValue === 'function'
    ? (initialValue as () => T)()
    : initialValue
}

export interface AsyncResource<T> {
  data: Ref<T>
  loading: Ref<boolean>
  error: Ref<unknown | null>
  run: (
    _loader: () => Promise<T>,
    _options?: RunAsyncResourceOptions<T>,
  ) => Promise<T | undefined>
  reset: (_nextValue?: T) => void
  invalidate: () => void
}

export const useAsyncResource = <T>(initialValue: InitialValueFactory<T>): AsyncResource<T> => {
  const requestGate = createRequestGate()
  const data = ref(resolveInitialValue(initialValue)) as Ref<T>
  const loading = ref(false)
  const error = ref<unknown | null>(null)

  const run = async (
    loader: () => Promise<T>,
    options?: RunAsyncResourceOptions<T>,
  ): Promise<T | undefined> => {
    const requestToken = requestGate.next()
    loading.value = true
    error.value = null

    try {
      const result = await loader()

      if (!requestGate.isCurrent(requestToken)) {
        return undefined
      }

      if (options?.commit) {
        options.commit(result)
      } else {
        data.value = result
      }

      return result
    } catch (nextError: unknown) {
      if (!requestGate.isCurrent(requestToken)) {
        return undefined
      }

      error.value = nextError
      throw nextError
    } finally {
      if (requestGate.isCurrent(requestToken)) {
        loading.value = false
      }
    }
  }

  const reset = (nextValue?: T) => {
    requestGate.invalidate()
    data.value = nextValue ?? resolveInitialValue(initialValue)
    loading.value = false
    error.value = null
  }

  const invalidate = () => {
    requestGate.invalidate()
    loading.value = false
  }

  return {
    data,
    loading,
    error,
    run,
    reset,
    invalidate,
  }
}
