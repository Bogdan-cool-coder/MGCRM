/**
 * "Deals without tasks" preview list (Э11 ТЗ §3.8).
 *
 * Fetches up to 5 deals for the current pipeline via the existing deals index
 * (`GET /api/deals?only_no_task=1`) — NO new endpoint. Own loading state so it
 * never blocks the rest of the dashboard; on failure the widget falls back to
 * the plain counter (ТЗ §4).
 */
import { ref, watch, type Ref } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { salesApi } from '@/api/sales'
import type { DealDto } from '@/entities/sales'

const PREVIEW_SIZE = 5

export interface UseNoTaskPreviewReturn {
  deals: Ref<DealDto[]>
  loading: Ref<boolean>
  failed: Ref<boolean>
  reload: () => Promise<void>
  /** Optimistically drop a deal after a task is created for it. */
  removeDeal: (id: number) => void
}

/**
 * @param pipelineId reactive current pipeline (null → no fetch, empty preview)
 */
export function useNoTaskPreview(
  pipelineId: () => number | null | undefined,
): UseNoTaskPreviewReturn {
  const resource = useAsyncResource<DealDto[]>(() => [])
  const failed = ref(false)

  const reload = async (): Promise<void> => {
    const pid = pipelineId()
    failed.value = false
    if (pid == null) {
      resource.reset([])
      return
    }
    try {
      await resource.run(async () => {
        const res = await salesApi.getDeals({
          pipeline_id: pid,
          only_no_task: true,
          per_page: PREVIEW_SIZE,
          sort_by: 'days_in_stage',
          sort_dir: 'desc',
        })
        return res.data
      })
    } catch {
      // Widget shows the counter-only fallback; no toast (secondary widget).
      failed.value = true
    }
  }

  const removeDeal = (id: number): void => {
    resource.data.value = resource.data.value.filter((d) => d.id !== id)
  }

  // Refetch whenever the pipeline changes (mirrors the main dashboard filter).
  watch(
    () => pipelineId(),
    () => {
      void reload()
    },
    { immediate: true },
  )

  return {
    deals: resource.data,
    loading: resource.loading,
    failed,
    reload,
    removeDeal,
  }
}
