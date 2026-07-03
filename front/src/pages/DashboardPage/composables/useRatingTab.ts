/**
 * «Рейтинг» tab data composable — R3 best-manager (yearly rating).
 *
 * Owns the yearly manager rating (rows + leader) for the active year, scoring
 * mode (standard|absolute) and funnel. Server-state routes through
 * `useAsyncResource` (no raw axios in components). Backend is built in parallel —
 * a 404 (aggregator not yet shipped) degrades gracefully to no-data + hint (the
 * established graceful-404 pattern, mirrors useScheduleTab/useRegistryTab).
 *
 * The rating is annual: it reads the shared hub year through a getter dep and
 * re-fetches when the year / mode / funnel change (single source of truth, §1.1).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getBestManagerReport } from '@/api/reports'
import { getApiErrorStatus } from '@/utils/errors'
import type { BestManagerMode, BestManagerResponse } from '@/entities/reports'

export interface RatingTabDeps {
  year: () => number
  mode: () => BestManagerMode
  pipelineId: () => number | null
}

export const useRatingTab = (deps: RatingTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<BestManagerResponse | null>(() => null)
  const dataReady = ref(false)
  /** True when the backend endpoint is not yet deployed (404) — shows a hint. */
  const endpointMissing = ref(false)

  const report = computed<BestManagerResponse | null>(() => resource.data.value)

  const loading = computed<boolean>(() => !dataReady.value || resource.loading.value)

  const load = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await resource.run(() =>
        getBestManagerReport({
          year: deps.year(),
          mode: deps.mode(),
          pipeline_id: deps.pipelineId(),
        }),
      )
    } catch (err) {
      if (getApiErrorStatus(err) === 404) {
        // Endpoint not yet shipped — degrade to no-data + hint (no toast).
        endpointMissing.value = true
        resource.reset(null)
        return
      }
      const msg = err instanceof Error ? err.message : String(err)
      toast.add({ severity: 'error', summary: t('errors.server_error'), detail: msg, life: 5000 })
    } finally {
      dataReady.value = true
    }
  }

  // Re-fetch when the shared hub year / mode / funnel change.
  watch(
    () => [deps.year(), deps.mode(), deps.pipelineId()],
    () => {
      void load()
    },
  )

  onMounted(() => {
    void load()
  })

  return {
    report,
    loading,
    endpointMissing,
    reload: load,
  }
}
