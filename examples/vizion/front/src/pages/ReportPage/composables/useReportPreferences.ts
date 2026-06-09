import { computed, onScopeDispose, ref, shallowRef, watch, type Ref } from 'vue'
import {
  reportPreferencesApi,
  type ReportColumnOrderPreference,
  type ReportPreferences,
  type ReportPreferencesPatch,
} from '@/api'
import { normalizeApiError } from '@/utils/errors'

/**
 * Per-report × per-user UI preferences synchronised with the backend.
 *
 * ── Architecture ────────────────────────────────────────────────────────────
 *
 * One canonical store per `reportId` (module-level Map with refcount).
 * `useColumnOrder` consumes the instance through
 * `acquireReportPreferences(reportId)`. The store now syncs a single field —
 * `column_order` (the dashboard-on-report view and its `view_mode` /
 * `dashboard_layout` / `hidden_widget_groups` fields were removed); the shape
 * is kept generic so re-adding fields later is a localized change.
 *
 * `localStorage` is preserved as a **first-paint cache only**: on mount we
 * seed reactive state from the cache for instant render, then fetch from the
 * API and overwrite with the server value (the source of truth). On every
 * subsequent state change we update both the cache and (debounced) the API.
 *
 * Strategy summary:
 *   1. Read localStorage → seed `state` (instant first paint, even offline).
 *   2. Fire `GET /api/.../preferences` → on success, write server value into
 *      both `state` and localStorage. On failure (network / 5xx) — keep
 *      cache, notify console, do not block UX.
 *   3. Wrappers mutate `state` directly through `update(patch)`. Each mutation
 *      schedules a debounced `PUT /api/.../preferences` with the changed
 *      fields. localStorage is updated synchronously for cross-tab and
 *      cold-reload fidelity.
 *   4. On dispose (all consumers unmount), the pending debounced PUT is
 *      flushed synchronously — beforeunload-safe in modern browsers via
 *      the `flush()` path called from `onScopeDispose`.
 *
 * Optimistic-update policy: `state` mutates immediately; PUT runs in the
 * background. If the PUT fails we keep the local state (the user's intent
 * survives) and surface a console warning. The next successful PUT — or
 * the next page reload — reconciles with the server.
 *
 * Concurrent writes across fields: backend partial-upsert merges per-field,
 * so simultaneous PUTs touching different fields are safe. PUTs touching the
 * same field follow last-write-wins, which is the same guarantee local
 * `localStorage` already had.
 */

// ────────────────────────────────────────────────────────────────────────────
// Module-level cache: one instance per reportId, refcounted by consumers.
// ────────────────────────────────────────────────────────────────────────────

interface PreferenceInstance {
  state: Ref<ReportPreferences>
  /** True after the initial GET has resolved (success OR failure). */
  loaded: Ref<boolean>
  /**
   * Apply a partial mutation. Mutates `state` synchronously and schedules
   * a debounced PUT. Pending changes are coalesced — only the latest patch
   * for each field is sent.
   */
  update: (_patch: ReportPreferencesPatch) => void
  /** Flush any pending debounced PUT immediately. */
  flush: () => void
  /** Increment the refcount (consumer attached). */
  retain: () => void
  /** Decrement; when it hits zero, dispose. */
  release: () => void
  /** True while the consumer count is > 0; consumers can introspect. */
  refcount: { value: number }
}

const instances = new Map<number, PreferenceInstance>()

// ────────────────────────────────────────────────────────────────────────────
// Global lifecycle listeners — flush every live instance's pending debounced
// PUT when the tab transitions away or unloads. Without this, a user who
// reorders columns and immediately switches browser tabs loses the last edit:
// background tabs throttle `setTimeout`, the 600ms debounce stretches into
// minutes, and on `pagehide` the in-flight request gets aborted before the
// debounce fires.
//
// Listeners are attached lazily on first acquisition and removed when the
// last instance disposes. Idempotent — guarded by `listenersAttached`.
// ────────────────────────────────────────────────────────────────────────────

let listenersAttached = false

const flushAllInstances = (): void => {
  for (const inst of instances.values()) {
    inst.flush()
  }
}

const onVisibilityChange = (): void => {
  if (typeof document === 'undefined') return
  if (document.visibilityState === 'hidden') {
    // Tab hidden: page is still alive, axios will deliver. This is the
    // common case (user switches tabs) and covers the original bug report.
    flushAllInstances()
  }
}

const onPageHide = (): void => {
  // Best-effort: page is unloading. axios may not finish (no keepalive on
  // XHR); we still call flush so the PUT at least starts. The
  // visibilitychange path above usually fires first on tab-close in modern
  // browsers, so this is a defence-in-depth for direct window/tab close.
  flushAllInstances()
}

const attachGlobalListeners = (): void => {
  if (listenersAttached || typeof window === 'undefined') return
  document.addEventListener('visibilitychange', onVisibilityChange)
  window.addEventListener('pagehide', onPageHide)
  listenersAttached = true
}

const detachGlobalListeners = (): void => {
  if (!listenersAttached || typeof window === 'undefined') return
  document.removeEventListener('visibilitychange', onVisibilityChange)
  window.removeEventListener('pagehide', onPageHide)
  listenersAttached = false
}

// ────────────────────────────────────────────────────────────────────────────
// localStorage cache — fast-render first-paint mirror of the synced
// `column_order` field. The shape matches what `useColumnOrder` reads so a
// fresh load (before the API resolves) renders the user's customisation
// instantly. After the API resolves, we rewrite the cache from the server
// payload, keeping it in the same shape.
// ────────────────────────────────────────────────────────────────────────────

const STORAGE_NAMESPACE_COLUMN_ORDER = 'vizion-column-order'

const buildKey = (
  namespace: string,
  reportId: number,
  userId: number | string,
): string => `${namespace}-${reportId}-${userId}`

const readLocalStorage = <T>(key: string, parse: (_raw: string) => T | null): T | null => {
  if (typeof window === 'undefined') return null
  try {
    const raw = window.localStorage.getItem(key)
    if (raw === null) return null
    return parse(raw)
  } catch {
    return null
  }
}

const writeLocalStorage = (key: string, value: string): void => {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.setItem(key, value)
  } catch {
    // private-browsing / quota — fail silent.
  }
}

const removeLocalStorage = (key: string): void => {
  if (typeof window === 'undefined') return
  try {
    window.localStorage.removeItem(key)
  } catch {
    // ignore
  }
}

/**
 * Build a default `ReportPreferences` skeleton seeded from localStorage.
 * A corrupt cache entry falls back to `null` (config defaults) rather than
 * throwing.
 */
const seedFromCache = (
  reportId: number,
  userId: number | string,
): ReportPreferences => {
  // column_order — shape `{order: string[], hidden?: string[]}`. Legacy
  // cache entries may carry a `groups` key from the pre-2026-05-21 column-
  // groups concept — we silently strip it (the field no longer exists in
  // the type).
  const columnOrder = readLocalStorage<ReportColumnOrderPreference | null>(
    buildKey(STORAGE_NAMESPACE_COLUMN_ORDER, reportId, userId),
    (raw) => {
      try {
        const parsed = JSON.parse(raw) as unknown
        if (typeof parsed !== 'object' || parsed === null) return null
        const entry = parsed as Record<string, unknown>
        if (!Array.isArray(entry.order)) return null
        const order = entry.order.filter(
          (value): value is string => typeof value === 'string',
        )
        const hidden: string[] = []
        if (Array.isArray(entry.hidden)) {
          for (const value of entry.hidden) {
            if (typeof value === 'string') hidden.push(value)
          }
        }
        // Empty envelope == no customisation. Treat as null so callers fall
        // through to config defaults instead of holding empty arrays.
        if (order.length === 0 && hidden.length === 0) {
          return null
        }
        const out: ReportColumnOrderPreference = { order }
        if (hidden.length > 0) out.hidden = hidden
        return out
      } catch {
        return null
      }
    },
  )

  return {
    report_id: reportId,
    column_order: columnOrder,
  }
}

/**
 * Mirror an authoritative `ReportPreferences` back into the localStorage
 * cache. Keeps the shape identical to what `useColumnOrder` reads so a
 * partial roll-back of API sync would still find working data in storage.
 */
const writeCache = (prefs: ReportPreferences, userId: number | string): void => {
  const rid = prefs.report_id

  // column_order — `null` means "user reset to config defaults"; we remove
  // the cached envelope so a cold reload doesn't replay a stale customisation
  // before the API responds.
  const columnOrderKey = buildKey(STORAGE_NAMESPACE_COLUMN_ORDER, rid, userId)
  if (prefs.column_order === null) {
    removeLocalStorage(columnOrderKey)
  } else {
    writeLocalStorage(columnOrderKey, JSON.stringify(prefs.column_order))
  }
}

// ────────────────────────────────────────────────────────────────────────────
// Debounced PUT plumbing.
// ────────────────────────────────────────────────────────────────────────────

/** PUT debounce window — covers column-reorder bursts without feeling laggy. */
const PUT_DEBOUNCE_MS = 600

const createInstance = (reportId: number, userId: number | string): PreferenceInstance => {
  const state = ref<ReportPreferences>(seedFromCache(reportId, userId))
  const loaded = ref<boolean>(false)
  const refcount = ref<number>(0)

  // Coalesced patch waiting to be flushed. Each `update()` merges its
  // payload into this object so multiple field changes during a single
  // debounce window go out as one request.
  let pendingPatch: ReportPreferencesPatch = {}
  let debounceTimer: ReturnType<typeof setTimeout> | null = null

  const sendPut = async (): Promise<void> => {
    if (Object.keys(pendingPatch).length === 0) return
    const patch = pendingPatch
    pendingPatch = {}

    try {
      const response = await reportPreferencesApi.update(reportId, patch)
      // Reconcile — server is the source of truth. Don't clobber any
      // optimistic edits the user made *after* this PUT was scheduled
      // (those will live in `pendingPatch` and be sent on the next tick).
      state.value = response
      writeCache(response, userId)
    } catch (error) {
      // Optimistic-update policy: keep local state, surface a warning.
      // Do NOT toast — preferences are non-critical and the user's intent
      // is already reflected in the UI.
      const normalized = normalizeApiError(error, 'Failed to sync preferences')
      console.warn('[reportPreferences] PUT failed', {
        reportId,
        status: normalized.status,
        message: normalized.message,
        patch,
      })
    }
  }

  const flush = (): void => {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
      debounceTimer = null
    }
    // Fire-and-forget — onScopeDispose can't await. The browser keepalive
    // semantics on axios are not configured; we rely on the disposing
    // component being the *last* consumer (e.g. page navigation), so the
    // request will continue in flight under the next route.
    void sendPut()
  }

  const scheduleFlush = (): void => {
    if (debounceTimer !== null) {
      clearTimeout(debounceTimer)
    }
    debounceTimer = setTimeout(() => {
      debounceTimer = null
      void sendPut()
    }, PUT_DEBOUNCE_MS)
  }

  const update = (patch: ReportPreferencesPatch): void => {
    // Mutate state optimistically — the consumer sees its write immediately.
    const next: ReportPreferences = { ...state.value }
    if ('column_order' in patch) {
      // `null` is meaningful here — represents "reset to config defaults".
      // Distinct from "omit", which would not appear in this branch at all.
      next.column_order = patch.column_order ?? null
    }
    state.value = next

    // Sync cache immediately — cross-tab reads and cold-reload first paint
    // both rely on localStorage being current.
    writeCache(next, userId)

    // Merge into pending patch (last-write-wins per field).
    pendingPatch = { ...pendingPatch, ...patch }

    scheduleFlush()
  }

  // Initial fetch. Always 200 per backend contract; failure is treated as
  // "use cache" (loaded still flips so consumers can rely on a single
  // hydration signal).
  void (async () => {
    try {
      const response = await reportPreferencesApi.get(reportId)
      // Only overwrite state if no user-driven mutation has been queued
      // since we started fetching. If the user reordered columns mid-fetch,
      // their intent wins — `pendingPatch` will be sent next.
      if (Object.keys(pendingPatch).length === 0) {
        state.value = response
        writeCache(response, userId)
      } else {
        // Merge server defaults underneath user edits.
        const next: ReportPreferences = {
          ...response,
          ...(pendingPatch as Partial<ReportPreferences>),
          report_id: reportId,
        }
        state.value = next
        writeCache(next, userId)
      }
    } catch (error) {
      const normalized = normalizeApiError(error, 'Failed to load preferences')
      console.warn('[reportPreferences] GET failed — using cache', {
        reportId,
        status: normalized.status,
        message: normalized.message,
      })
    } finally {
      loaded.value = true
    }
  })()

  const retain = (): void => {
    refcount.value += 1
  }

  const release = (): void => {
    refcount.value -= 1
    if (refcount.value <= 0) {
      flush()
      instances.delete(reportId)
      // Last instance gone → tear down global listeners so we don't leak
      // them across HMR reloads or test runs. They'll be re-attached on the
      // next acquisition.
      if (instances.size === 0) {
        detachGlobalListeners()
      }
    }
  }

  return {
    state,
    loaded,
    update,
    flush,
    retain,
    release,
    refcount,
  }
}

/**
 * Acquire (or create) the shared preference store for a given report. Each
 * caller must release the returned instance themselves — we no longer wire
 * `onScopeDispose` here because composers may swap instances mid-mount
 * (e.g. when `reportId` flips from 0 → real id) and need fine-grained
 * release control.
 *
 * `userId` is captured at acquisition time (typical Vizion sessions don't
 * switch users mid-report; if they do, the report page will remount and
 * acquire a fresh instance).
 */
const acquireReportPreferences = (
  reportId: number,
  userId: number | string,
): PreferenceInstance => {
  let instance = instances.get(reportId)
  if (!instance) {
    instance = createInstance(reportId, userId)
    instances.set(reportId, instance)
    // First instance in the page lifecycle → wire global flush-on-tab-hide
    // listeners. Subsequent acquisitions are no-ops via the idempotency guard.
    attachGlobalListeners()
  }
  instance.retain()
  return instance
}

/**
 * Fallback preferences returned by `useReportPreferences` while `reportId`
 * is still 0 (i.e. the parent page has not yet resolved which report to
 * load). Treated as a read-only snapshot — `update()` is a no-op in this
 * state to avoid PUT'ing against `/api/reports/0/preferences` (which 404s).
 */
const buildFallbackPreferences = (): ReportPreferences => ({
  report_id: 0,
  column_order: null,
})

// ────────────────────────────────────────────────────────────────────────────
// Public composable — consumed by `useColumnOrder`.
// ────────────────────────────────────────────────────────────────────────────

export interface UseReportPreferencesReturn {
  /** Reactive snapshot of the server-synced preferences. */
  state: Ref<ReportPreferences>
  /** True once the initial GET has resolved (success or failure). */
  loaded: Ref<boolean>
  /** Apply a partial patch. Mutates state immediately, PUT'd via debounce. */
  update: (_patch: ReportPreferencesPatch) => void
  /** Force-flush any pending debounced PUT. Used by tests and unmount paths. */
  flush: () => void
}

/**
 * Subscribe to the preference store for a given report. The composable
 * **must** be called from within a Vue effect scope (component setup or
 * another composable) — `onScopeDispose` handles refcount cleanup
 * automatically.
 *
 * Reactivity contract: callers may invoke this **before** `reportId` is
 * known (`reportId.value === 0`). In that case we hold off acquisition —
 * no instance is created and no GET fires. Once `reportId` flips to a real
 * value, we acquire lazily and the returned `state` (a `computed`)
 * automatically points at the new instance's reactive payload, so every
 * downstream `computed(() => prefs.state.value.<field>)` in the wrapper
 * composables re-resolves to the correct data without a remount.
 *
 * If `reportId` later changes again (rare — RouterView remounts on
 * navigation, so this is a defensive path), we release the previous
 * instance and re-acquire.
 */
export const useReportPreferences = (
  reportId: Ref<number>,
  userId: Ref<number | string>,
): UseReportPreferencesReturn => {
  // `shallowRef` because the value is an opaque instance — we don't want
  // Vue to walk its internal `state` ref and double-track everything.
  const currentInstance = shallowRef<PreferenceInstance | null>(null)
  const fallbackState = ref<ReportPreferences>(buildFallbackPreferences())

  const acquireForId = (id: number): void => {
    if (id <= 0) {
      // Release any prior instance — the caller has lost context (rare).
      if (currentInstance.value) {
        currentInstance.value.release()
        currentInstance.value = null
      }
      return
    }
    // Idempotent: if we already hold the right instance, skip.
    if (currentInstance.value && currentInstance.value.state.value.report_id === id) {
      return
    }
    if (currentInstance.value) {
      currentInstance.value.release()
    }
    currentInstance.value = acquireReportPreferences(id, userId.value)
  }

  // Initial attempt (if reportId is already known) + reactive re-acquisition
  // when it changes. `immediate: true` covers the synchronous-known case.
  watch(
    reportId,
    (next) => {
      acquireForId(next)
    },
    { immediate: true },
  )

  onScopeDispose(() => {
    if (currentInstance.value) {
      currentInstance.value.release()
      currentInstance.value = null
    }
  })

  // `state` is a computed proxy — it transparently routes reads to the
  // currently-acquired instance, or falls back to a read-only skeleton
  // while reportId is unknown. This is what fixes the original bug where
  // `state` was bound to a dead instance-0 ref.
  const state = computed<ReportPreferences>(() => {
    return currentInstance.value?.state.value ?? fallbackState.value
  })

  const loaded = computed<boolean>(() => {
    return currentInstance.value?.loaded.value ?? false
  })

  return {
    state,
    loaded,
    // No-op when no instance is held — prevents writes against report_id=0.
    update: (patch) => {
      currentInstance.value?.update(patch)
    },
    flush: () => {
      currentInstance.value?.flush()
    },
  }
}

// Test-only helpers — exposed for unit tests; not used by app code.
export const __resetReportPreferencesForTests = (): void => {
  for (const [, inst] of instances) {
    inst.flush()
  }
  instances.clear()
  detachGlobalListeners()
}
