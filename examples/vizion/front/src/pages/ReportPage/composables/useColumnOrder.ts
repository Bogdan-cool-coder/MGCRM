import { computed, type ComputedRef, type Ref } from 'vue'
import type { ReportColumnOrderPreference } from '@/api'
import { useUserStore } from '@/stores/user'
import type { PresentationColumn } from './useReportPresentation'
import { useReportPreferences } from './useReportPreferences'

/**
 * Reactive column-order + per-column visibility state for a single
 * report × user. Powers the column manager popover (drag-and-drop reorder
 * + show/hide checkboxes) plus PrimeVue DataTable's built-in column
 * reordering on the report page.
 *
 * Persistence shape (mirrored 1-to-1 to the backend `column_order` field):
 *   {
 *     order:  string[]    // column `_key`s in user-curated order
 *     hidden?: string[]   // hidden column `_key`s (omit / empty = all visible)
 *   }
 *
 * ── Why `_key` and not `field` ──────────────────────────────────────────────
 * Several columns can share the same `field` (e.g. `estateSells.estate_sell_id`
 * reused for "Номер договора" / "Номер объекта" / "ID объекта", differing only
 * by `label_field` / `is_crm_id`). Keying order / visibility / Map lookups by
 * `field` collapsed those columns into one — `byField.get(field)` returned the
 * last column for all of them, so the table rendered N identical headers + cells.
 * The stable, unique `_key` (built in `useReportPresentation`) is the identity
 * used throughout this composable instead.
 *
 * `order` is the source of truth for column position. Keys present in the
 * report config but missing from `order` (e.g. AI regenerated the config and
 * added a new column) are appended at the end, preserving user-curated
 * positions for everything else.
 *
 * ── Back-compat with `field`-based persisted prefs ──────────────────────────
 * Prefs saved before the `_key` migration stored `field` names. On load we map
 * each persisted identifier: if it matches a column's `_key`, use it directly;
 * otherwise, if it matches exactly one column's `field` (no `field` collision),
 * translate it to that column's `_key`. Ambiguous (collided) or unmatched
 * legacy entries are dropped — those columns fall back to the config-default
 * order rather than crashing. New writes always persist `_key`s.
 *
 * ── Persistence layer ──────────────────────────────────────────────────────
 * Backend-synced via the shared `useReportPreferences` store
 * (field `column_order`). `localStorage` under
 * `vizion-column-order-{reportId}-{userId}` is retained as a fast-render
 * cache so the first paint after navigation already reflects the user's
 * customisation, even before the GET response lands. Once the API
 * responds, the cache is overwritten with the server value.
 *
 * Writes hit `prefs.update({ column_order })` synchronously; the shared
 * store handles the debounced PUT, optimistic local mutation, and
 * localStorage mirroring. Resetting sends explicit `null` so the backend
 * clears any prior row.
 *
 * Fail-safe semantics:
 *   - Grouped (master/detail) reports: caller passes `disableWhen=true`. The
 *     composable returns the columns unchanged — DnD is suppressed at the
 *     page level too.
 *   - reportId still 0 (parent page hasn't resolved): `useReportPreferences`
 *     holds off acquisition; the fallback state has `column_order: null`,
 *     so we render the config default order until the real id arrives.
 *   - Corrupt or stale localStorage entry: silently fall back to the config
 *     default order. We never throw.
 */

const EMPTY_ORDER: ReportColumnOrderPreference = { order: [] }

export interface UseColumnOrderReturn {
  /** Columns in the user-curated order. */
  displayColumns: ComputedRef<PresentationColumn[]>
  /**
   * Persist a new ordering after a drag-drop. Indices refer to positions in
   * `displayColumns`. No-op when `disableWhen` (e.g. grouped report) is active.
   */
  applyReorder: (_dragIndex: number, _dropIndex: number) => void
  /** Per-column visibility by `_key` — `true` shows, `false` hides. */
  setColumnVisibility: (_key: string, _visible: boolean) => void
  /** Reactive set of hidden column `_key`s. */
  hiddenFields: ComputedRef<Set<string>>
  /** Reset to config default (clears the backend row + localStorage entry). */
  reset: () => void
  /** True when the user has customised order or visibility. */
  isCustomised: ComputedRef<boolean>
}

export const useColumnOrder = (
  reportId: Ref<number>,
  columns: Ref<PresentationColumn[]>,
  disableWhen: Ref<boolean>,
): UseColumnOrderReturn => {
  const userStore = useUserStore()
  const userId = computed<number | string>(() => userStore.currentUser?.id ?? 'anon')

  // The shared store does its own watch(reportId, {immediate:true}) with a
  // guard on `id <= 0`, so we don't need to gate anything ourselves — the
  // initial state will be the fallback skeleton (column_order: null) until
  // the real id resolves, at which point the cache + GET kick in.
  const prefs = useReportPreferences(reportId, userId)

  /**
   * Reactive read of `column_order` from the shared store. `null` is the
   * "no customisation" sentinel; we materialise an empty envelope so
   * downstream computeds don't need to null-check on every access.
   */
  const stored = computed<ReportColumnOrderPreference>(() => {
    return prefs.state.value.column_order ?? EMPTY_ORDER
  })

  /**
   * Translate a persisted identifier (either a current `_key` or a legacy
   * `field` name) to the `_key` of the column it refers to.
   *   - exact `_key` match → return it
   *   - legacy `field` match that is unambiguous (exactly one column carries
   *     that `field`) → return that column's `_key`
   *   - collided `field` (≥2 columns) or no match → return `null` (drop the
   *     entry; the affected columns fall back to config-default position)
   * `fieldCounts` lets us detect collisions without re-scanning per call.
   */
  const normalizePersistedId = (
    id: string,
    byKey: Map<string, PresentationColumn>,
    fieldToKey: Map<string, string>,
    fieldCounts: Map<string, number>,
  ): string | null => {
    if (byKey.has(id)) return id
    if ((fieldCounts.get(id) ?? 0) === 1) {
      return fieldToKey.get(id) ?? null
    }
    return null
  }

  /**
   * Effective ordering by `_key`: starts from the config order, lifts known
   * keys into `stored.order`'s position (with legacy-`field` back-compat),
   * appends unknown new keys at the tail. Persisted entries that no longer
   * resolve to a config column are dropped silently.
   */
  const orderedKeys = computed<string[]>(() => {
    const configKeys = columns.value.map((col) => col._key)
    if (stored.value.order.length === 0) return configKeys

    const byKey = new Map(columns.value.map((col) => [col._key, col]))
    const fieldToKey = new Map<string, string>()
    const fieldCounts = new Map<string, number>()
    for (const col of columns.value) {
      fieldCounts.set(col.field, (fieldCounts.get(col.field) ?? 0) + 1)
      // First-write-wins for the field→key map; only used when the field is
      // unambiguous (count === 1), so the single entry is always the right one.
      if (!fieldToKey.has(col.field)) fieldToKey.set(col.field, col._key)
    }

    const seen = new Set<string>()
    const out: string[] = []
    for (const rawId of stored.value.order) {
      const key = normalizePersistedId(rawId, byKey, fieldToKey, fieldCounts)
      if (key !== null && byKey.has(key) && !seen.has(key)) {
        out.push(key)
        seen.add(key)
      }
    }
    for (const key of configKeys) {
      if (!seen.has(key)) {
        out.push(key)
        seen.add(key)
      }
    }
    return out
  })

  const displayColumns = computed<PresentationColumn[]>(() => {
    if (disableWhen.value) return columns.value
    const byKey = new Map(columns.value.map((col) => [col._key, col]))
    return orderedKeys.value
      .map((key) => byKey.get(key) ?? null)
      .filter((col): col is PresentationColumn => col !== null)
  })

  const applyReorder = (dragIndex: number, dropIndex: number): void => {
    if (disableWhen.value) return
    if (dragIndex === dropIndex) return

    const currentOrder = orderedKeys.value
    if (dragIndex < 0 || dragIndex >= currentOrder.length) return
    if (dropIndex < 0 || dropIndex >= currentOrder.length) return

    const movedKey = currentOrder[dragIndex]
    if (!movedKey) return
    const nextOrder = [...currentOrder]
    nextOrder.splice(dragIndex, 1)
    nextOrder.splice(dropIndex, 0, movedKey)

    // Persist the hidden set normalised to current `_key`s too — otherwise a
    // reorder would re-save stale legacy `field` entries that we can no longer
    // disambiguate against the now-reordered columns.
    const patch: ReportColumnOrderPreference = { order: nextOrder }
    const hiddenKeys = currentHiddenKeys()
    if (hiddenKeys.length > 0) patch.hidden = hiddenKeys
    prefs.update({ column_order: patch })
  }

  /**
   * Resolve `stored.hidden` (which may hold current `_key`s or legacy `field`
   * names) to the set of current column `_key`s that are hidden. Unresolvable
   * legacy entries (collided / removed) are dropped so a stale `field` can
   * never hide an unrelated column.
   */
  const hiddenFields = computed<Set<string>>(() => {
    const hidden = stored.value.hidden
    if (!hidden || hidden.length === 0) return new Set<string>()

    const byKey = new Map(columns.value.map((col) => [col._key, col]))
    const fieldToKey = new Map<string, string>()
    const fieldCounts = new Map<string, number>()
    for (const col of columns.value) {
      fieldCounts.set(col.field, (fieldCounts.get(col.field) ?? 0) + 1)
      if (!fieldToKey.has(col.field)) fieldToKey.set(col.field, col._key)
    }

    const out = new Set<string>()
    for (const rawId of hidden) {
      const key = normalizePersistedId(rawId, byKey, fieldToKey, fieldCounts)
      if (key !== null && byKey.has(key)) out.add(key)
    }
    return out
  })

  /**
   * Current hidden set as a config-ordered `_key[]` — used when persisting so
   * the stored `hidden` array is always written in current-`_key` form
   * (migrating away from any legacy `field` entries) and in a stable order.
   */
  const currentHiddenKeys = (): string[] => {
    const hidden = hiddenFields.value
    if (hidden.size === 0) return []
    return columns.value.map((col) => col._key).filter((key) => hidden.has(key))
  }

  const setColumnVisibility = (key: string, visible: boolean): void => {
    if (disableWhen.value) return

    const current = hiddenFields.value
    if (visible) {
      if (!current.has(key)) return
    } else {
      if (current.has(key)) return
    }

    const next = new Set(current)
    if (visible) next.delete(key)
    else next.add(key)

    // Preserve a stable order — walk config columns so the persisted array
    // doesn't reshuffle on every toggle.
    const nextHidden = columns.value.map((col) => col._key).filter((k) => next.has(k))

    const patch: ReportColumnOrderPreference = {
      order: [...stored.value.order],
    }
    if (nextHidden.length > 0) patch.hidden = nextHidden

    prefs.update({ column_order: patch })
  }

  const reset = (): void => {
    // Explicit `null` tells the backend to clear the row; the shared store
    // also clears the localStorage envelope so a cold reload doesn't replay
    // the old customisation before the GET response lands.
    prefs.update({ column_order: null })
  }

  const isCustomised = computed<boolean>(() => {
    const current = prefs.state.value.column_order
    if (!current) return false
    return current.order.length > 0 || (current.hidden?.length ?? 0) > 0
  })

  return {
    displayColumns,
    applyReorder,
    setColumnVisibility,
    hiddenFields,
    reset,
    isCustomised,
  }
}
