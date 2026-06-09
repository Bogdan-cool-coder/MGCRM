import type { PiniaPluginContext, StateTree } from 'pinia'

type PersistState = Record<string, unknown>

const fallbackStorage = (): Storage => {
  const data = new Map<string, string>()

  return {
    get length() {
      return data.size
    },
    clear() {
      data.clear()
    },
    getItem(key) {
      return data.get(key) ?? null
    },
    key(index) {
      return Array.from(data.keys())[index] ?? null
    },
    removeItem(key) {
      data.delete(key)
    },
    setItem(key, value) {
      data.set(key, value)
    },
  }
}

const inMemoryStorage = fallbackStorage()

const resolveStorage = (storage?: Storage): Storage => {
  if (storage) {
    return storage
  }

  if (typeof window === 'undefined') {
    return inMemoryStorage
  }

  try {
    const probeKey = '__pinia_persist_probe__'
    window.localStorage.setItem(probeKey, probeKey)
    window.localStorage.removeItem(probeKey)
    return window.localStorage
  } catch {
    return inMemoryStorage
  }
}

const isPersistState = (value: unknown): value is StateTree => {
  return value !== null && typeof value === 'object'
}

export function createPersistPlugin() {
  return ({ store, options }: PiniaPluginContext) => {
    const persist = options.persist

    if (!persist) return

    const config = typeof persist === 'object' ? persist : {}
    const storage = resolveStorage(config.storage)
    const key = config.key ?? `vizion:${store.$id}`
    const paths = config.paths ?? null
    const serialize = config.serialize ?? JSON.stringify
    const deserialize = config.deserialize ?? JSON.parse

    try {
      const savedState = storage.getItem(key)

      if (savedState) {
        const parsedState = deserialize(savedState)

        if (isPersistState(parsedState)) {
          store.$patch(parsedState)
        } else {
          storage.removeItem(key)
        }
      }
    } catch {
      storage.removeItem(key)
    }

    store.$subscribe((_, state) => {
      const nextState: PersistState =
        paths === null
          ? { ...state }
          : Object.fromEntries(
              paths
                .filter((path) => path in state)
                .map((path) => [path, state[path]]),
            )

      try {
        storage.setItem(key, serialize(nextState))
      } catch {
        storage.removeItem(key)
      }
    })
  }
}
