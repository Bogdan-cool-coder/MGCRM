/**
 * Pinia plugin — makes every store (incl. setup-syntax stores) resettable.
 *
 * Pinia 3 only ships `$reset()` for options stores; on a setup store it throws.
 * This plugin snapshots each store's code-initial writable state at creation time
 * and patches a working `$reset()` onto the store that restores that snapshot.
 *
 * ── Why the per-key, defensive approach ──────────────────────────────────────
 * On a *setup* store, `store.$state` is assembled from EVERY ref the setup fn
 * returns — including `computed()` getters, which are read-only refs wrapping a
 * function. `structuredClone(store.$state)` therefore throws a synchronous
 * DataCloneError on the first computed getter (e.g. userStore returns 10), which
 * previously crashed the whole app before `app.mount()`. The snapshot MUST:
 *   1. skip read-only (computed) keys — they are derived, never part of $reset;
 *   2. clone the remaining keys one-by-one, dropping any key that is not
 *      structured-cloneable (safe degradation: that slice just won't be reset);
 *   3. never throw — the entire plugin is wrapped so a store that defies these
 *      assumptions still boots the app (reset-of-that-store becomes a no-op).
 *
 * Registration order matters: this plugin MUST be registered BEFORE the persist
 * plugin so the snapshot captures the *code-defined* initial state rather than
 * the hydrated-from-localStorage state. See main.ts.
 */
import type { PiniaPluginContext, StateTree } from 'pinia'
import { isReadonly, toRaw } from 'vue'

declare module 'pinia' {
  // Ensure `$reset` is typed on setup stores too.
  export interface PiniaCustomProperties {
    $reset(): void
  }
}

/**
 * Snapshot only the writable, serialisable slices of a store's `$state`.
 *
 * `$state` on a setup store is a reactive object whose per-key *refs* live on
 * the store instance. A `computed()` getter surfaces as a read-only ref on the
 * store, so `isReadonly(store[key])` distinguishes derived getters (skip) from
 * writable state refs (snapshot). structuredClone is then attempted per-key so a
 * single non-cloneable value can never poison the whole snapshot.
 */
function snapshotWritableState(
  state: StateTree,
  store: PiniaPluginContext['store'],
): StateTree {
  const snapshot: StateTree = {}

  for (const key of Object.keys(state)) {
    // Skip computed getters: on a setup store they appear in $state as read-only
    // refs on the store instance. They are derived and must not be reset.
    const ref = (store as unknown as Record<string, unknown>)[key]
    if (ref !== undefined && isReadonly(ref)) continue

    try {
      // toRaw unwraps reactive proxies (structuredClone rejects proxies whose
      // targets are non-cloneable); preserves Map/Set/Date used by some stores.
      snapshot[key] = structuredClone(toRaw(state[key]))
    } catch {
      // Non-serialisable slice (function, symbol, DOM node, class instance…):
      // drop it from the snapshot. That key simply won't participate in $reset —
      // safe degradation, never a crash.
    }
  }

  return snapshot
}

export function storeResetPlugin({ store }: PiniaPluginContext): void {
  try {
    const initialState = snapshotWritableState(store.$state, store)
    const initialKeys = Object.keys(initialState)

    store.$reset = (): void => {
      try {
        store.$patch(($state) => {
          // Patch only the keys we actually snapshotted, re-cloning so nested
          // Maps/Sets/objects aren't shared with the frozen initial snapshot.
          for (const key of initialKeys) {
            ;($state as StateTree)[key] = structuredClone(initialState[key])
          }
        })
      } catch (error) {
        console.warn(`[storeReset] $reset failed for store "${store.$id}"`, error)
      }
    }
  } catch (error) {
    // Never let plugin registration crash bootstrap. Fall back to a no-op $reset
    // so callers of resetAllStores() keep working — the app is more important
    // than the reset feature.
    console.warn(`[storeReset] snapshot failed for store "${store.$id}"`, error)
    store.$reset = (): void => {}
  }
}
