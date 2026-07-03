/**
 * «Реестр» tab data composable — R1 registry (expected + squeeze).
 *
 * Owns the two registry lists (ожидаемые поступления / дожим) for the active
 * hub period + filters. Server-state routes through `useAsyncResource` (no raw
 * axios in components). Backend is built in parallel — a 404 (aggregator not yet
 * shipped) degrades gracefully to an empty report + hint, so the tab renders
 * instead of erroring (the established graceful-404 pattern, mirrors usePlansTab).
 *
 * Reads the cross-cutting hub filters through getter deps so it re-fetches when
 * the shared period/funnel/manager change (single source of truth, TZ §1.1).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getRegistryReport } from '@/api/reports'
import { getApiErrorStatus } from '@/utils/errors'
import type { RegistryReportResponse } from '@/entities/reports'

export interface RegistryTabDeps {
  year: () => number
  month: () => number
  pipelineId: () => number | null
  managerId: () => number | null
}

export const useRegistryTab = (deps: RegistryTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<RegistryReportResponse | null>(() => null)
  const dataReady = ref(false)
  /** True when the backend endpoint is not yet deployed (404) — shows a hint. */
  const endpointMissing = ref(false)

  const report = computed<RegistryReportResponse | null>(() => resource.data.value)

  const loading = computed<boolean>(() => !dataReady.value || resource.loading.value)

  const load = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await resource.run(() =>
        getRegistryReport({
          year: deps.year(),
          month: deps.month(),
          pipeline_id: deps.pipelineId(),
          manager_id: deps.managerId(),
        }),
      )
    } catch (err) {
      if (getApiErrorStatus(err) === 404) {
        // Endpoint not yet shipped — degrade to an empty report + hint (no toast).
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

  // Re-fetch when the shared hub filters change.
  watch(
    () => [deps.year(), deps.month(), deps.pipelineId(), deps.managerId()],
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
