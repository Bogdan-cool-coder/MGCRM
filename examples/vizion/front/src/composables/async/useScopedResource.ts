import { computed, type ComputedRef, type Ref } from 'vue'
import { useAsyncResource, type AsyncResource } from './useAsyncResource'

type InitialValueFactory<T> = T | (() => T)
type Nullable<T> = T | null | undefined

interface UseScopedResourceOptions<TScope, TData> {
  scope: Ref<Nullable<TScope>>
  initialValue: InitialValueFactory<TData>
  load: (_scope: TScope) => Promise<TData>
}

export interface ScopedResource<TScope, TData> extends AsyncResource<TData> {
  scope: ComputedRef<TScope | null>
  hasScope: ComputedRef<boolean>
  sync: (_scope?: Nullable<TScope>) => Promise<TData | undefined>
  refresh: () => Promise<TData | undefined>
  clear: (_nextValue?: TData) => void
}

const normalizeScope = <TScope>(scope: Nullable<TScope>): TScope | null => {
  return scope ?? null
}

export const useScopedResource = <TScope, TData>(
  options: UseScopedResourceOptions<TScope, TData>,
): ScopedResource<TScope, TData> => {
  const resource = useAsyncResource(options.initialValue)
  const scope = computed(() => normalizeScope(options.scope.value))
  const hasScope = computed(() => scope.value !== null)

  const sync = async (nextScope?: Nullable<TScope>) => {
    const resolvedScope = normalizeScope(nextScope ?? scope.value)

    if (resolvedScope === null) {
      resource.reset()
      return undefined
    }

    return resource.run(() => options.load(resolvedScope))
  }

  const refresh = () => {
    return sync(scope.value)
  }

  const clear = (nextValue?: TData) => {
    resource.reset(nextValue)
  }

  return {
    ...resource,
    scope,
    hasScope,
    sync,
    refresh,
    clear,
  }
}
