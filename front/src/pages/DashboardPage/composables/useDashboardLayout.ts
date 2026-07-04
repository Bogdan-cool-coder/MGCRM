/**
 * Dashboard "Overview" widget layout — variant (Б) of Э11 ТЗ.
 *
 * Configurable layout WITHOUT a drag/resize dependency: the user reorders
 * widgets (↑/↓) and toggles visibility (eye) in "Edit" mode. State persists in
 * localStorage under `mg-dash-layout-v2`.
 *
 * Schema is forward-compatible with a later grid-layout-plus upgrade (variant А):
 * every entry keeps the geometry fields `x/y/w/h` from DEFAULT_LAYOUT untouched,
 * and adds `visible` + `order` used by the CSS-grid renderer. Upgrading to (А)
 * only starts *reading* x/y/w/h — no key migration needed.
 */
import { ref, computed, watch, type Ref, type ComputedRef } from 'vue'

export type WidgetId =
  | 'kpi-active'
  | 'kpi-won'
  | 'kpi-lost'
  | 'kpi-total'
  | 'funnel'
  | 'forecast'
  | 'top'
  | 'notask'

/** Column span presets (out of 12) driving the CSS grid in variant (Б). */
export const WIDGET_SPAN: Record<WidgetId, number> = {
  'kpi-active': 3,
  'kpi-won': 3,
  'kpi-lost': 3,
  'kpi-total': 3,
  funnel: 7,
  forecast: 5,
  top: 7,
  notask: 5,
}

export interface LayoutEntry {
  /** Widget id (grid-layout-plus compatible `i`). */
  i: WidgetId
  /** Geometry (kept for variant А upgrade; unused by the CSS-grid renderer). */
  x: number
  y: number
  w: number
  h: number
  /** Variant Б: render order (ascending) and visibility. */
  order: number
  visible: boolean
}

const LS_KEY = 'mg-dash-layout-v2'

/**
 * Canonical default layout (Э11 ТЗ §1). `order` follows the y/x reading order of
 * the mockup's DEFAULT_LAYOUT; `visible` defaults to true.
 */
const DEFAULT_LAYOUT: readonly LayoutEntry[] = [
  { i: 'kpi-active', x: 0, y: 0, w: 3, h: 5, order: 0, visible: true },
  { i: 'kpi-won', x: 3, y: 0, w: 3, h: 5, order: 1, visible: true },
  { i: 'kpi-lost', x: 6, y: 0, w: 3, h: 5, order: 2, visible: true },
  { i: 'kpi-total', x: 9, y: 0, w: 3, h: 5, order: 3, visible: true },
  { i: 'funnel', x: 0, y: 5, w: 7, h: 12, order: 4, visible: true },
  { i: 'forecast', x: 7, y: 5, w: 5, h: 12, order: 5, visible: true },
  { i: 'top', x: 0, y: 17, w: 7, h: 10, order: 6, visible: true },
  { i: 'notask', x: 7, y: 17, w: 5, h: 10, order: 7, visible: true },
]

const cloneDefault = (): LayoutEntry[] => DEFAULT_LAYOUT.map((l) => ({ ...l }))

const VALID_IDS = new Set<WidgetId>(DEFAULT_LAYOUT.map((l) => l.i))

/**
 * Load + validate persisted layout. Falls back to default when the payload is
 * malformed, has the wrong length, or references unknown ids (Э11 ТЗ §1:
 * «массив длины DEFAULT_LAYOUT.length — иначе fallback на дефолт»).
 */
function loadLayout(): LayoutEntry[] {
  try {
    const raw = localStorage.getItem(LS_KEY)
    if (!raw) return cloneDefault()
    const parsed: unknown = JSON.parse(raw)
    if (!Array.isArray(parsed) || parsed.length !== DEFAULT_LAYOUT.length) {
      return cloneDefault()
    }
    const seen = new Set<string>()
    const result: LayoutEntry[] = []
    for (const item of parsed) {
      if (typeof item !== 'object' || item === null) return cloneDefault()
      const rec = item as Record<string, unknown>
      const id = rec.i
      if (typeof id !== 'string' || !VALID_IDS.has(id as WidgetId) || seen.has(id)) {
        return cloneDefault()
      }
      seen.add(id)
      const def = DEFAULT_LAYOUT.find((d) => d.i === id)!
      result.push({
        i: id as WidgetId,
        // Geometry falls back to defaults (variant Б never edits it).
        x: typeof rec.x === 'number' ? rec.x : def.x,
        y: typeof rec.y === 'number' ? rec.y : def.y,
        w: typeof rec.w === 'number' ? rec.w : def.w,
        h: typeof rec.h === 'number' ? rec.h : def.h,
        order: typeof rec.order === 'number' ? rec.order : def.order,
        visible: typeof rec.visible === 'boolean' ? rec.visible : true,
      })
    }
    return result
  } catch {
    return cloneDefault()
  }
}

export interface UseDashboardLayoutReturn {
  /** Raw layout entries (persisted). */
  layout: Ref<LayoutEntry[]>
  /** Entries sorted by `order`, ready to render (includes hidden ones for edit UI). */
  ordered: ComputedRef<LayoutEntry[]>
  /** Move a widget up one slot in the order. */
  moveUp: (id: WidgetId) => void
  /** Move a widget down one slot in the order. */
  moveDown: (id: WidgetId) => void
  /** Toggle a widget's visibility. */
  toggleVisible: (id: WidgetId) => void
  /** Reset to the canonical default layout (clears localStorage). */
  reset: () => void
  isFirst: (id: WidgetId) => boolean
  isLast: (id: WidgetId) => boolean
}

export function useDashboardLayout(): UseDashboardLayoutReturn {
  const layout = ref<LayoutEntry[]>(loadLayout())

  // Persist on any change. Non-fatal on quota/private-mode errors.
  watch(
    layout,
    (val) => {
      try {
        localStorage.setItem(LS_KEY, JSON.stringify(val))
      } catch {
        /* storage unavailable — layout stays in-memory for the session */
      }
    },
    { deep: true },
  )

  const ordered = computed<LayoutEntry[]>(() =>
    [...layout.value].sort((a, b) => a.order - b.order),
  )

  /** Normalise `order` to a dense 0..n-1 range after a swap. */
  const renumber = (): void => {
    const sorted = [...layout.value].sort((a, b) => a.order - b.order)
    sorted.forEach((entry, idx) => {
      const target = layout.value.find((l) => l.i === entry.i)
      if (target) target.order = idx
    })
  }

  const swap = (id: WidgetId, dir: -1 | 1): void => {
    const sorted = ordered.value
    const idx = sorted.findIndex((l) => l.i === id)
    if (idx < 0) return
    const neighbourIdx = idx + dir
    if (neighbourIdx < 0 || neighbourIdx >= sorted.length) return
    const a = layout.value.find((l) => l.i === sorted[idx]!.i)
    const b = layout.value.find((l) => l.i === sorted[neighbourIdx]!.i)
    if (!a || !b) return
    const tmp = a.order
    a.order = b.order
    b.order = tmp
    renumber()
  }

  const moveUp = (id: WidgetId): void => swap(id, -1)
  const moveDown = (id: WidgetId): void => swap(id, 1)

  const toggleVisible = (id: WidgetId): void => {
    const entry = layout.value.find((l) => l.i === id)
    if (entry) entry.visible = !entry.visible
  }

  const reset = (): void => {
    try {
      localStorage.removeItem(LS_KEY)
    } catch {
      /* ignore */
    }
    layout.value = cloneDefault()
  }

  const isFirst = (id: WidgetId): boolean => ordered.value[0]?.i === id
  const isLast = (id: WidgetId): boolean =>
    ordered.value[ordered.value.length - 1]?.i === id

  return { layout, ordered, moveUp, moveDown, toggleVisible, reset, isFirst, isLast }
}

export { WIDGET_SPAN as widgetSpan }
