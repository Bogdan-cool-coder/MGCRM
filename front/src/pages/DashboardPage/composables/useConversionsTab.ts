/**
 * «Планы» tab · «Конверсии» (conversion) metric data composable — R5.
 *
 * Fetches the R5 conversions report (`GET /api/reports/conversions`): plan-scored
 * pairs (`general` auto task-ratios + `custom` user pairs) and honest stage
 * conversions (`stage`, fact-only from deal_stage_history — our advantage). This
 * tab is READ-ONLY in Ф4: conversion plan values are derived/authored elsewhere;
 * here we surface план%/факт%/% per pair and a stage funnel of conversion-% between
 * adjacent stages. The pair constructor is out of Ф4 scope (contract ОВ-2).
 *
 * Server-state routes through `useAsyncResource` (no raw axios in components).
 * Backend is built in parallel — a 404 degrades gracefully to an empty report +
 * hint (the established graceful-404 pattern, mirrors usePlansTab / useRegistryTab).
 */
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { getConversionsReport } from '@/api/reports'
import { getApiErrorStatus } from '@/utils/errors'
import type {
  ConversionReportResponse,
  ConversionPair,
  StageConversion,
} from '@/entities/reports'
import type { PlanLayer } from '@/entities/planTargets'

export interface ConversionsTabDeps {
  year: () => number
  layer: () => PlanLayer
  pipelineId: () => number | null
}

export const useConversionsTab = (deps: ConversionsTabDeps) => {
  const { t } = useI18n()
  const toast = useToast()

  const resource = useAsyncResource<ConversionReportResponse | null>(() => null)
  const dataReady = ref(false)
  const endpointMissing = ref(false)

  const report = computed<ConversionReportResponse | null>(() => resource.data.value)

  /**
   * Plan-scored pairs shown in the «Пары» table (general first, then custom).
   * Keep only well-formed pairs (a `cells` map) so an unexpected shape degrades
   * to an empty section instead of crashing the read-only table.
   */
  const pairs = computed<ConversionPair[]>(() =>
    [...(report.value?.general ?? []), ...(report.value?.custom ?? [])].filter(
      (p): p is ConversionPair =>
        p != null && p.cells != null && typeof p.cells === 'object',
    ),
  )

  /**
   * Honest stage conversions (fact-only period-aggregate). Keep only well-formed
   * rows (numeric num/den) — the funnel reads these flat fields, so a malformed
   * shape degrades to an empty stage section rather than a render crash.
   */
  const stageConversions = computed<StageConversion[]>(() =>
    (report.value?.stage ?? []).filter(
      (s): s is StageConversion =>
        s != null && typeof s.num_count === 'number' && typeof s.den_count === 'number',
    ),
  )

  const hasData = computed<boolean>(
    () => pairs.value.length > 0 || stageConversions.value.length > 0,
  )

  const load = async (): Promise<void> => {
    endpointMissing.value = false
    try {
      await resource.run(() =>
        getConversionsReport({
          year: deps.year(),
          layer: deps.layer(),
          pipeline_id: deps.pipelineId(),
          scope_type: 'user',
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

  const loading = computed<boolean>(() => !dataReady.value || resource.loading.value)

  watch(
    () => [deps.year(), deps.layer(), deps.pipelineId()],
    () => {
      void load()
    },
  )

  onMounted(() => {
    void load()
  })

  return {
    report,
    pairs,
    stageConversions,
    hasData,
    loading,
    endpointMissing,
    reload: load,
  }
}
