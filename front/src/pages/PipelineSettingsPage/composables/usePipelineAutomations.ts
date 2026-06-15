/**
 * usePipelineAutomations — server-state composable for a pipeline's automations.
 *
 * Loads all automations for the currently-selected pipeline and groups them by
 * stage_id so StageEditorItem can render the per-stage accordion without extra
 * fetches. Create/update/toggle/delete go through useMutation per the architecture
 * (no raw axios in components).
 *
 * Cache strategy: one fetch per pipeline_id. Switching pipelines or calling
 * invalidate() forces a fresh fetch.
 */

import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { automationsApi } from '@/api/automation'
import type {
  AutomationDto,
  CreateAutomationPayload,
  UpdateAutomationPayload,
} from '@/entities/automation'

export function usePipelineAutomations() {
  const activePipelineId = ref<number | null>(null)

  // ─── Server state ──────────────────────────────────────────────────────────
  const automationsResource = useAsyncResource<AutomationDto[]>(() => [])

  // ─── Derived: grouped by stage_id ─────────────────────────────────────────
  const automationsByStage = computed<Map<number | null, AutomationDto[]>>(() => {
    const map = new Map<number | null, AutomationDto[]>()
    for (const a of automationsResource.data.value) {
      const key = a.stage_id ?? null
      const existing = map.get(key) ?? []
      existing.push(a)
      map.set(key, existing)
    }
    return map
  })

  function getForStage(stageId: number): AutomationDto[] {
    return automationsByStage.value.get(stageId) ?? []
  }

  // ─── Load ──────────────────────────────────────────────────────────────────
  async function fetchForPipeline(pipelineId: number): Promise<void> {
    activePipelineId.value = pipelineId
    await automationsResource.run(() =>
      automationsApi.list({ pipeline_id: pipelineId }),
    )
  }

  function invalidate(): void {
    automationsResource.invalidate()
    if (activePipelineId.value !== null) {
      void fetchForPipeline(activePipelineId.value)
    }
  }

  // ─── Mutations ─────────────────────────────────────────────────────────────

  const createMutation = useMutation<AutomationDto>()
  const updateMutation = useMutation<AutomationDto>()
  const deleteMutation = useMutation<void>()
  const toggleMutation = useMutation<AutomationDto>()

  async function createAutomation(payload: CreateAutomationPayload): Promise<AutomationDto> {
    const result = await createMutation.run(() => automationsApi.create(payload))
    // Optimistically append
    automationsResource.data.value = [...automationsResource.data.value, result]
    return result
  }

  async function updateAutomation(
    id: number,
    payload: UpdateAutomationPayload,
  ): Promise<AutomationDto> {
    const result = await updateMutation.run(() => automationsApi.update(id, payload))
    automationsResource.data.value = automationsResource.data.value.map((a) =>
      a.id === id ? result : a,
    )
    return result
  }

  async function toggleActive(id: number, isActive: boolean): Promise<AutomationDto> {
    const result = await toggleMutation.run(() =>
      automationsApi.update(id, { is_active: isActive }),
    )
    automationsResource.data.value = automationsResource.data.value.map((a) =>
      a.id === id ? result : a,
    )
    return result
  }

  async function deleteAutomation(id: number): Promise<void> {
    await deleteMutation.run(() => automationsApi.remove(id))
    automationsResource.data.value = automationsResource.data.value.filter((a) => a.id !== id)
  }

  return {
    // State
    automations: automationsResource.data,
    loading: automationsResource.loading,
    error: automationsResource.error,
    activePipelineId,
    // Derived
    automationsByStage,
    getForStage,
    // Actions
    fetchForPipeline,
    invalidate,
    createAutomation,
    updateAutomation,
    toggleActive,
    deleteAutomation,
    // Mutation states
    createPending: createMutation.isPending,
    updatePending: updateMutation.isPending,
    deletePending: deleteMutation.isPending,
    togglePending: toggleMutation.isPending,
  }
}

export type PipelineAutomationsComposable = ReturnType<typeof usePipelineAutomations>
