import { ref, shallowRef, type Ref } from 'vue'
import { useServices } from '@/services'
import type { WidgetData } from '@/entities/widget'

type PreviewStatus = 'idle' | 'loading' | 'loaded' | 'error'

interface PreviewEntry {
  status: Ref<PreviewStatus>
  data: Ref<WidgetData | null>
}

/**
 * Per-widget cache of `GET /api/widgets/{id}/data` for the library preview
 * mini-charts. One instance is created per open library modal (see
 * `WidgetLibraryModal`) and shared down to every preview card, so each widget's
 * data is fetched at most once for the lifetime of that modal — opening the
 * same library twice re-fetches (fresh numbers), but rendering the same widget
 * card N times within one open does not.
 *
 * Cards request lazily on mount (`ensure(id)`); concurrent calls for the same id
 * dedupe on the in-flight promise.
 */
export const useWidgetPreviewData = () => {
  const { widgetService } = useServices()

  const entries = new Map<number, PreviewEntry>()
  const inFlight = new Map<number, Promise<void>>()

  const getEntry = (widgetId: number): PreviewEntry => {
    let entry = entries.get(widgetId)
    if (!entry) {
      entry = {
        status: ref<PreviewStatus>('idle'),
        data: shallowRef<WidgetData | null>(null),
      }
      entries.set(widgetId, entry)
    }
    return entry
  }

  /**
   * Triggers a fetch for `widgetId` if it hasn't been requested yet. Safe to
   * call repeatedly — it's a no-op once the widget is loading/loaded, and joins
   * the existing promise while a request is in flight.
   */
  const ensure = (widgetId: number): Promise<void> => {
    const existing = inFlight.get(widgetId)
    if (existing) return existing

    const entry = getEntry(widgetId)
    if (entry.status.value === 'loaded') return Promise.resolve()

    entry.status.value = 'loading'
    const promise = widgetService
      .fetchWidgetData(widgetId)
      .then((data) => {
        entry.data.value = data
        entry.status.value = 'loaded'
      })
      .catch(() => {
        // Swallow — preview is best-effort; the card falls back to its text
        // tile and adding the widget is never blocked.
        entry.data.value = null
        entry.status.value = 'error'
      })
      .finally(() => {
        inFlight.delete(widgetId)
      })

    inFlight.set(widgetId, promise)
    return promise
  }

  const statusOf = (widgetId: number): Ref<PreviewStatus> => getEntry(widgetId).status
  const dataOf = (widgetId: number): Ref<WidgetData | null> => getEntry(widgetId).data

  /** Drops all cached entries — call when the modal closes. */
  const clear = (): void => {
    entries.clear()
    inFlight.clear()
  }

  return { ensure, statusOf, dataOf, clear }
}

export type WidgetPreviewDataApi = ReturnType<typeof useWidgetPreviewData>
