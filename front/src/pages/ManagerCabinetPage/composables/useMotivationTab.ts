/**
 * Motivation-tab data composable (cabinet screen 1).
 *
 * Owns: period (year/month via URL query `?year=&month=`), the viewed user
 * (director/admin cross-user), the card async resource, and the 30s live poll
 * for the team-bonus forecast (SPEC ОВ-2). No raw axios in components —
 * everything routes through `useAsyncResource` + `api/motivation`.
 */
import { watch, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getMotivationCard } from '@/api/motivation'
import type { MotivationCard } from '@/entities/motivation'
import { useMotivationMonths } from './useMotivationMonths'

const POLL_INTERVAL_MS = 30_000

export const useMotivationTab = (viewedUserId: () => number | null) => {
  const { t } = useI18n()
  const toast = useToast()

  // ─── Period (shared with the toolbar via URL ?year=&month=) ────────────────
  // Month nav/options live in the toolbar (useMotivationMonths); here we only
  // need the resolved period to drive the card fetch + poll.
  const { period } = useMotivationMonths()

  // ─── Card resource ─────────────────────────────────────────────────────────
  const cardResource = useAsyncResource<MotivationCard | null>(() => null)

  const loadCard = async (silent = false): Promise<void> => {
    // Silent background poll (30s forecast refresh): never route through
    // cardResource.run — that would flip loading/error and, on a transient
    // network blip, drop the UI into an error state over an already-loaded card.
    // Instead fetch directly and commit only on success; on failure keep the
    // last good card untouched and stay quiet.
    if (silent) {
      // Snapshot the identity we're polling for BEFORE the await, then commit
      // only if the period/user hasn't changed while the request was in flight.
      // Without this an in-flight poll for the OLD month/user could resolve after
      // the user switched months and silently overwrite the freshly-loaded card.
      const snapYear = period.value.year
      const snapMonth = period.value.month
      const snapUser = viewedUserId()
      try {
        const fresh = await getMotivationCard({
          year: snapYear,
          month: snapMonth,
          user_id: snapUser ?? undefined,
        })
        const stillCurrent =
          snapYear === period.value.year &&
          snapMonth === period.value.month &&
          snapUser === viewedUserId()
        if (stillCurrent) {
          cardResource.data.value = fresh
        }
      } catch {
        // Keep the last successful card; a failed background poll is non-fatal.
      }
      return
    }

    try {
      await cardResource.run(() =>
        getMotivationCard({
          year: period.value.year,
          month: period.value.month,
          user_id: viewedUserId() ?? undefined,
        }),
      )
    } catch (err) {
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: msg,
        life: 5000,
      })
    }
  }

  // ─── 30s live poll (forecast freshness, ОВ-2) ──────────────────────────────
  let pollTimer: ReturnType<typeof setInterval> | null = null

  const startPoll = (): void => {
    stopPoll()
    pollTimer = setInterval(() => {
      // Skip the poll while the tab is hidden — no point refreshing a forecast
      // nobody is looking at, and it avoids a wave of catch-up requests when
      // many backgrounded tabs wake together.
      if (typeof document !== 'undefined' && document.hidden) return
      void loadCard(true)
    }, POLL_INTERVAL_MS)
  }

  const stopPoll = (): void => {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  // ─── Reactions ─────────────────────────────────────────────────────────────
  watch(
    () => [period.value.year, period.value.month, viewedUserId()],
    () => void loadCard(),
  )

  onMounted(() => {
    void loadCard()
    startPoll()
  })

  onBeforeUnmount(stopPoll)

  return {
    card: cardResource.data,
    loading: cardResource.loading,
    error: cardResource.error,
    reload: () => loadCard(),
  }
}
