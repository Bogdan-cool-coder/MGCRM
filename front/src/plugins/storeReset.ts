/**
 * Pinia plugin — makes every store (incl. setup-syntax stores) resettable.
 *
 * Pinia 3 only ships `$reset()` for options stores; on a setup store it throws.
 * This plugin snapshots each store's code-initial `$state` at creation time and
 * patches a working `$reset()` onto the store that restores that snapshot.
 *
 * Registration order matters: this plugin MUST be registered BEFORE the persist
 * plugin so the snapshot captures the *code-defined* initial state rather than
 * the hydrated-from-localStorage state. See main.ts.
 */
import type { PiniaPluginContext } from 'pinia'

declare module 'pinia' {
  // Ensure `$reset` is typed on setup stores too.
  export interface PiniaCustomProperties {
    $reset(): void
  }
}

export function storeResetPlugin({ store }: PiniaPluginContext): void {
  // structuredClone preserves Map/Set/Date used in some stores (e.g. salesStore).
  const initialState = structuredClone(store.$state)

  store.$reset = (): void => {
    store.$patch(($state) => {
      Object.assign($state, structuredClone(initialState))
    })
  }
}
