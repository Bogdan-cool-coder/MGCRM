/**
 * «График» tab data composable — R2 income schedule (day calendar + cumulative).
 *
 * Owns the per-day kopeck series (план/факт/ожидаемое/дожим) + cumulative
 * plan/fact for the active hub period + funnel. Server-state routes through
 * `useAsyncResource` (no raw axios in components). Backend is built in parallel —
 * a 404 degrades gracefully to no-data + hint (established graceful-404 pattern).
 *
 * Note: R2 has no `manager_id` param in the contract (§6.5) — the schedule is a
 * pipeline-scoped day distribution; visibility is applied server-side.
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getIncomeSchedule } from '@/api/reports'
import { getApiErrorStatus } from '@/utils/errors'
import type { IncomeScheduleResponse } from '@/entities/reports'

export interface ScheduleTabDeps {
  year: () => number
  month: () => number
  pipelineId: () => number | null
}

export const useScheduleTab = (deps: ScheduleTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<IncomeScheduleResponse | null>(() => null)
  const dataReady = ref(false)
  /** True when the backend endpoint is not yet deployed (404) — shows a hint. */
  const endpointMissing = ref(false)

  const schedule = computed<IncomeScheduleResponse | null>(() => resource.data.value)

  const loading = computed<boolean>(() => !dataReady.value || resource.loading.value)

  const load = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await resource.run(() =>
        getIncomeSchedule({
          year: deps.year(),
          month: deps.month(),
          pipeline_id: deps.pipelineId(),
        }),
      )
    } catch (err) {
      if (getApiErrorStatus(err) === 404) {
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

  watch(
    () => [deps.year(), deps.month(), deps.pipelineId()],
    () => {
      void load()
    },
  )

  onMounted(() => {
    void load()
  })

  return {
    schedule,
    loading,
    endpointMissing,
    reload: load,
  }
}
