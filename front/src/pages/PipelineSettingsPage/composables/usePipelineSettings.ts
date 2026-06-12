/**
 * usePipelineSettings — orchestrator for S1.5 Pipeline Settings page.
 * Manages pipeline CRUD, stage list for selected pipeline, and all mutations.
 * All stage mutations call salesStore.invalidateStagesCache so Kanban picks up changes.
 */
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { salesApi } from '@/api/sales'
import { useSalesStore } from '@/stores/salesStore'
import type {
  PipelineDto,
  PipelineStageDto,
  CreateStagePayload,
  UpdateStagePayload,
  ReorderStageItem,
} from '@/entities/sales'

export function usePipelineSettings() {
  const { t } = useI18n()
  const toast = useToast()
  const salesStore = useSalesStore()

  // ─── State ────────────────────────────────────────────────────────────────

  const pipelines = ref<PipelineDto[]>([])
  const selectedPipelineId = ref<number | null>(null)
  const stages = ref<PipelineStageDto[]>([])

  const pipelinesLoading = ref(false)
  const stagesLoading = ref(false)
  const pipelinesError = ref<string | null>(null)
  const stagesError = ref<string | null>(null)

  // ─── Computed ─────────────────────────────────────────────────────────────

  const selectedPipeline = computed<PipelineDto | null>(() =>
    pipelines.value.find((p) => p.id === selectedPipelineId.value) ?? null,
  )

  const topLevelStages = computed<PipelineStageDto[]>(() => {
    const all = stages.value.filter((s) => s.parent_stage_id === null)
    // System stages always render at the bottom: is_won before is_lost
    return all.sort((a, b) => {
      const aSystem = a.is_won || a.is_lost
      const bSystem = b.is_won || b.is_lost
      if (!aSystem && !bSystem) return a.sort_order - b.sort_order
      if (aSystem && bSystem) {
        // is_won before is_lost
        if (a.is_won && b.is_lost) return -1
        if (a.is_lost && b.is_won) return 1
        return 0
      }
      // Normal stages before system stages
      return aSystem ? 1 : -1
    })
  })

  function substagesOf(parentId: number): PipelineStageDto[] {
    return stages.value
      .filter((s) => s.parent_stage_id === parentId)
      .sort((a, b) => a.sort_order - b.sort_order)
  }

  // ─── Load ─────────────────────────────────────────────────────────────────

  async function fetchPipelines(): Promise<void> {
    pipelinesLoading.value = true
    pipelinesError.value = null
    try {
      const list = await salesApi.getPipelines('sales')
      pipelines.value = list
      // Auto-select first pipeline if none selected
      if (!selectedPipelineId.value && list.length > 0 && list[0]) {
        selectedPipelineId.value = list[0].id
        await fetchStages(list[0].id)
      } else if (selectedPipelineId.value) {
        await fetchStages(selectedPipelineId.value)
      }
    } catch (e: unknown) {
      pipelinesError.value = extractMessage(e)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: pipelinesError.value,
        life: 5000,
      })
    } finally {
      pipelinesLoading.value = false
    }
  }

  async function fetchStages(pipelineId: number): Promise<void> {
    stagesLoading.value = true
    stagesError.value = null
    try {
      stages.value = await salesApi.getPipelineStages(pipelineId)
    } catch (e: unknown) {
      stagesError.value = extractMessage(e)
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: stagesError.value,
        life: 5000,
      })
    } finally {
      stagesLoading.value = false
    }
  }

  async function selectPipeline(id: number): Promise<void> {
    selectedPipelineId.value = id
    await fetchStages(id)
  }

  // ─── Pipeline mutations ────────────────────────────────────────────────────

  async function createPipeline(name: string): Promise<PipelineDto | null> {
    try {
      const created = await salesApi.createPipeline({ name, kind: 'sales' })
      pipelines.value = [...pipelines.value, created]
      toast.add({
        severity: 'success',
        summary: t('sales.pipelineEditor.createPipelineDialog.successToast', { name: created.name }),
        life: 3000,
      })
      // Auto-select created pipeline
      await selectPipeline(created.id)
      return created
    } catch (e: unknown) {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: extractMessage(e),
        life: 5000,
      })
      return null
    }
  }

  async function renamePipeline(id: number, name: string): Promise<boolean> {
    try {
      const updated = await salesApi.updatePipeline(id, { name })
      pipelines.value = pipelines.value.map((p) => (p.id === id ? { ...p, name: updated.name } : p))
      toast.add({
        severity: 'success',
        summary: t('sales.pipelineEditor.renamePipeline.successToast'),
        life: 2000,
      })
      return true
    } catch (e: unknown) {
      toast.add({
        severity: 'error',
        summary: t('sales.pipelineEditor.renamePipeline.errorToast'),
        detail: extractMessage(e),
        life: 5000,
      })
      return false
    }
  }

  async function deletePipeline(id: number): Promise<boolean> {
    try {
      await salesApi.deletePipeline(id)
      salesStore.invalidateStagesCache(id)
      pipelines.value = pipelines.value.filter((p) => p.id !== id)
      // If deleted pipeline was active, select first remaining
      if (selectedPipelineId.value === id) {
        if (pipelines.value.length > 0 && pipelines.value[0]) {
          await selectPipeline(pipelines.value[0].id)
        } else {
          selectedPipelineId.value = null
          stages.value = []
        }
      }
      toast.add({
        severity: 'success',
        summary: t('sales.pipelineEditor.deletePipeline.successToast'),
        life: 2000,
      })
      return true
    } catch (e: unknown) {
      const status = extractStatus(e)
      if (status === 409) {
        toast.add({
          severity: 'warn',
          summary: t('sales.pipelineEditor.deletePipeline.errorWithDeals'),
          life: 5000,
        })
      } else if (status === 422) {
        toast.add({
          severity: 'warn',
          summary: t('errors.validation'),
          detail: t('sales.pipelineEditor.deletePipeline.errorLastSales'),
          life: 5000,
        })
      } else {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: extractMessage(e),
          life: 5000,
        })
      }
      return false
    }
  }

  // ─── Stage mutations ───────────────────────────────────────────────────────

  async function createStage(payload: CreateStagePayload): Promise<PipelineStageDto> {
    if (!selectedPipelineId.value) throw new Error('No pipeline selected')
    const created = await salesApi.createStage(selectedPipelineId.value, payload)
    stages.value = [...stages.value, created]
    salesStore.invalidateStagesCache(selectedPipelineId.value)
    toast.add({
      severity: 'success',
      summary: t('sales.stageEditor.createDialog.successToast'),
      life: 2000,
    })
    return created
  }

  async function updateStage(stageId: number, payload: UpdateStagePayload): Promise<PipelineStageDto> {
    if (!selectedPipelineId.value) throw new Error('No pipeline selected')
    const updated = await salesApi.updateStage(selectedPipelineId.value, stageId, payload)
    stages.value = stages.value.map((s) => (s.id === stageId ? { ...s, ...updated } : s))
    salesStore.invalidateStagesCache(selectedPipelineId.value)
    toast.add({
      severity: 'success',
      summary: t('sales.stageEditor.editDrawer.successToast'),
      life: 2000,
    })
    return updated
  }

  async function deleteStage(stageId: number): Promise<boolean> {
    if (!selectedPipelineId.value) return false
    try {
      await salesApi.deleteStage(selectedPipelineId.value, stageId)
      stages.value = stages.value.filter((s) => s.id !== stageId)
      salesStore.invalidateStagesCache(selectedPipelineId.value)
      toast.add({
        severity: 'success',
        summary: t('sales.stageEditor.deleteStage.successToast'),
        life: 2000,
      })
      return true
    } catch (e: unknown) {
      const status = extractStatus(e)
      if (status === 422) {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: t('sales.stageEditor.deleteStage.errorSystem'),
          life: 5000,
        })
      } else if (status === 409) {
        const msg = extractMessage(e)
        const msgLower = msg.toLowerCase()
        const localizedMsg = msgLower.includes('sub') || msgLower.includes('подстатус') || msgLower.includes('children')
          ? t('sales.stageEditor.deleteStage.errorWithSubstages')
          : t('sales.stageEditor.deleteStage.errorWithDeals')
        toast.add({
          severity: 'warn',
          summary: localizedMsg,
          life: 5000,
        })
      } else {
        toast.add({
          severity: 'error',
          summary: t('errors.server_error'),
          detail: extractMessage(e),
          life: 5000,
        })
      }
      return false
    }
  }

  async function reorderStages(ordered: PipelineStageDto[]): Promise<void> {
    if (!selectedPipelineId.value) return
    // Snapshot for rollback
    const snapshot = [...stages.value]
    // Compute reorder payload
    const payload: ReorderStageItem[] = ordered.map((s, idx) => ({
      id: s.id,
      sort_order: idx + 1,
    }))
    try {
      const updated = await salesApi.reorderStages(selectedPipelineId.value, payload)
      stages.value = updated
      salesStore.invalidateStagesCache(selectedPipelineId.value)
    } catch {
      // Rollback
      stages.value = snapshot
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: t('sales.stageEditor.reorder.errorToast'),
        life: 5000,
      })
    }
  }

  // ─── Helpers ──────────────────────────────────────────────────────────────

  function extractMessage(e: unknown): string {
    if (typeof e === 'object' && e !== null) {
      const err = e as Record<string, unknown>
      // Axios error shape
      if ('response' in err) {
        const response = err.response as Record<string, unknown> | null
        if (response && typeof response === 'object') {
          const data = response.data as Record<string, unknown> | null
          if (data && typeof data.message === 'string') return data.message
        }
      }
      if ('message' in err && typeof err.message === 'string') return err.message
    }
    return String(e)
  }

  function extractStatus(e: unknown): number | null {
    if (typeof e === 'object' && e !== null) {
      const err = e as Record<string, unknown>
      if ('response' in err) {
        const response = err.response as Record<string, unknown> | null
        if (response && typeof response.status === 'number') return response.status
      }
    }
    return null
  }

  return {
    // State
    pipelines,
    selectedPipelineId,
    stages,
    pipelinesLoading,
    stagesLoading,
    pipelinesError,
    stagesError,
    // Computed
    selectedPipeline,
    topLevelStages,
    substagesOf,
    // Load
    fetchPipelines,
    fetchStages,
    selectPipeline,
    // Pipeline mutations
    createPipeline,
    renamePipeline,
    deletePipeline,
    // Stage mutations
    createStage,
    updateStage,
    deleteStage,
    reorderStages,
  }
}
