<template>
  <div class="pipeline-settings-page">
    <PageHeader :title="t('sales.pipelineEditor.pageTitle')" icon="pi pi-sliders-h" />

    <div class="pipeline-settings-page__content">
      <!-- Pipelines section -->
      <PipelineList
        :pipelines="pipelines"
        :selected-pipeline-id="selectedPipelineId"
        :loading="pipelinesLoading"
        @create="showCreatePipeline = true"
        @select="selectPipeline"
        @rename="handleRenamePipeline"
        @delete="handleDeletePipeline"
      />

      <!-- Stages editor section -->
      <StageEditorList
        v-if="selectedPipelineId !== null"
        :top-level-stages="topLevelStages"
        :substages-of="substagesOf"
        :pipeline-name="selectedPipeline?.name"
        :loading="stagesLoading"
        :automations-for="pipelineAutomations.getForStage"
        :automations-loading="pipelineAutomations.loading.value"
        :automations-error="pipelineAutomations.error.value"
        @add-stage="showCreateStage = true"
        @edit-stage="openEditDrawer"
        @delete-stage="handleDeleteStage"
        @rename-stage="handleRenameStage"
        @toggle-hidden="handleToggleHidden"
        @reorder="handleReorder"
        @add-automation="openWizardForStage"
        @edit-automation="openWizardForEdit"
        @delete-automation="handleDeleteAutomation"
        @toggle-automation="handleToggleAutomation"
        @refetch-automations="() => pipelineAutomations.invalidate()"
      />

      <!-- Automation list panel (all automations of pipeline) -->
      <AutomationListPanel
        v-if="selectedPipelineId !== null"
        :automations="pipelineAutomations.automations.value"
        :loading="pipelineAutomations.loading.value"
        @add-automation="openWizardForPipeline"
        @edit-automation="openWizardForEdit"
        @delete-automation="handleDeleteAutomation"
        @toggle="handleToggleAutomation"
      />
    </div>

    <!-- Create Pipeline Dialog -->
    <CreatePipelineDialog
      v-model:visible="showCreatePipeline"
      :saving="creatingPipeline"
      @create="handleCreatePipeline"
    />

    <!-- Create Stage Dialog -->
    <CreateStageDialog
      v-model:visible="showCreateStage"
      :stages="stages"
      :saving="creatingStage"
      :field-errors="stageFieldErrors"
      @create="handleCreateStage"
    />

    <!-- Stage Edit Drawer -->
    <StageEditDrawer
      v-model:visible="showEditDrawer"
      :stage="editingStage"
      :all-stages="stages"
      :saving="savingStage"
      @save="handleSaveStage"
    />

    <!-- Automation Wizard Dialog -->
    <AutomationWizardDialog
      v-if="selectedPipelineId !== null"
      :model-visible="showWizard"
      :pipeline-id="selectedPipelineId"
      :stage-id="wizardStageId"
      :stages="stages"
      :edit-automation="editingAutomation"
      @update:model-visible="showWizard = $event"
      @saved="handleAutomationSaved"
    />

    <!-- Confirm Dialog -->
    <ConfirmDialog />

    <!-- Toast -->
    <Toast />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import ConfirmDialog from 'primevue/confirmdialog'
import Toast from 'primevue/toast'
import { PageHeader } from '@/components/AppShell'
import PipelineList from './components/PipelineList.vue'
import StageEditorList from './components/StageEditorList.vue'
import CreatePipelineDialog from './components/CreatePipelineDialog.vue'
import CreateStageDialog from './components/CreateStageDialog.vue'
import StageEditDrawer from './components/StageEditDrawer.vue'
import AutomationListPanel from './components/AutomationListPanel.vue'
import AutomationWizardDialog from './components/AutomationWizardDialog.vue'
import { usePipelineSettings } from './composables/usePipelineSettings'
import { usePipelineAutomations } from './composables/usePipelineAutomations'
import type { PipelineStageDto, CreateStagePayload, UpdateStagePayload } from '@/entities/sales'
import type { AutomationDto } from '@/entities/automation'

const { t } = useI18n()
const confirm = useConfirm()
const toast = useToast()

function extractErrorMessage(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const err = e as Record<string, unknown>
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

function extractErrorStatus(e: unknown): number | null {
  if (typeof e === 'object' && e !== null) {
    const err = e as Record<string, unknown>
    if ('response' in err) {
      const response = err.response as Record<string, unknown> | null
      if (response && typeof response.status === 'number') return response.status
    }
  }
  return null
}

const {
  pipelines,
  selectedPipelineId,
  stages,
  pipelinesLoading,
  stagesLoading,
  selectedPipeline,
  topLevelStages,
  substagesOf,
  fetchPipelines,
  selectPipeline,
  createPipeline,
  renamePipeline,
  deletePipeline,
  createStage,
  updateStage,
  deleteStage,
  reorderStages,
} = usePipelineSettings()

const pipelineAutomations = usePipelineAutomations()

// ─── Dialog / Drawer state ────────────────────────────────────────────────────

const showCreatePipeline = ref(false)
const creatingPipeline = ref(false)

const showCreateStage = ref(false)
const creatingStage = ref(false)
const stageFieldErrors = ref<Record<string, string>>({})

const showEditDrawer = ref(false)
const editingStage = ref<PipelineStageDto | null>(null)
const savingStage = ref(false)

// Wizard
const showWizard = ref(false)
const wizardStageId = ref<number | null>(null)
const editingAutomation = ref<AutomationDto | null>(null)

// ─── Pipeline handlers ────────────────────────────────────────────────────────

async function handleCreatePipeline(name: string) {
  creatingPipeline.value = true
  let succeeded = false
  try {
    const result = await createPipeline(name)
    succeeded = result !== null
  } finally {
    creatingPipeline.value = false
    if (succeeded) showCreatePipeline.value = false
  }
}

async function handleRenamePipeline(id: number, name: string) {
  await renamePipeline(id, name)
}

function handleDeletePipeline(id: number) {
  confirm.require({
    header: t('sales.pipelineEditor.deletePipeline.confirmHeader'),
    message: t('sales.pipelineEditor.deletePipeline.confirmBody'),
    acceptLabel: t('sales.pipelineEditor.deletePipeline.confirmAccept'),
    rejectLabel: t('sales.pipelineEditor.deletePipeline.confirmReject'),
    acceptProps: { severity: 'danger' },
    accept: async () => {
      await deletePipeline(id)
      confirm.close()
    },
  })
}

// ─── Stage handlers ───────────────────────────────────────────────────────────

async function handleCreateStage(payload: CreateStagePayload) {
  creatingStage.value = true
  stageFieldErrors.value = {}
  let succeeded = false
  try {
    await createStage(payload)
    succeeded = true
  } catch (e: unknown) {
    const status = extractErrorStatus(e)
    if (status === 422) {
      if (typeof e === 'object' && e !== null) {
        const err = e as Record<string, unknown>
        if ('response' in err) {
          const response = err.response as Record<string, unknown> | null
          const data = response?.data as Record<string, unknown> | null
          const apiErrors = data?.errors as Record<string, string[]> | null
          if (apiErrors) {
            const fe: Record<string, string> = {}
            for (const [k, msgs] of Object.entries(apiErrors)) {
              fe[k] = Array.isArray(msgs) ? (msgs[0] ?? String(msgs)) : String(msgs)
            }
            stageFieldErrors.value = fe
          }
        }
      }
    } else {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: extractErrorMessage(e),
        life: 5000,
      })
    }
  } finally {
    creatingStage.value = false
    if (succeeded) showCreateStage.value = false
  }
}

function openEditDrawer(stage: PipelineStageDto) {
  editingStage.value = stage
  showEditDrawer.value = true
}

async function handleSaveStage(stageId: number, payload: UpdateStagePayload) {
  savingStage.value = true
  let succeeded = false
  try {
    await updateStage(stageId, payload)
    succeeded = true
  } catch (e: unknown) {
    const status = extractErrorStatus(e)
    if (status !== 422) {
      toast.add({
        severity: 'error',
        summary: t('sales.stageEditor.editDrawer.errorToast'),
        detail: extractErrorMessage(e),
        life: 5000,
      })
    }
  } finally {
    savingStage.value = false
    if (succeeded) showEditDrawer.value = false
  }
}

function handleDeleteStage(id: number) {
  confirm.require({
    header: t('sales.stageEditor.deleteStage.confirmHeader'),
    message: t('sales.stageEditor.deleteStage.confirmBody'),
    acceptLabel: t('sales.stageEditor.deleteStage.confirmAccept'),
    rejectLabel: t('sales.stageEditor.deleteStage.confirmReject'),
    acceptProps: { severity: 'danger' },
    accept: async () => {
      await deleteStage(id)
      confirm.close()
    },
  })
}

async function handleRenameStage(id: number, name: string) {
  try {
    await updateStage(id, { name })
  } catch (e: unknown) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: extractErrorMessage(e),
      life: 5000,
    })
  }
}

async function handleToggleHidden(id: number, value: boolean) {
  try {
    await updateStage(id, { hidden_by_default: value })
  } catch (e: unknown) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: extractErrorMessage(e),
      life: 5000,
    })
  }
}

async function handleReorder(ordered: PipelineStageDto[]) {
  await reorderStages(ordered)
}

// ─── Automation handlers ──────────────────────────────────────────────────────

function openWizardForStage(stageId: number) {
  editingAutomation.value = null
  wizardStageId.value = stageId
  showWizard.value = true
}

function openWizardForPipeline() {
  editingAutomation.value = null
  wizardStageId.value = null
  showWizard.value = true
}

function openWizardForEdit(automation: AutomationDto) {
  editingAutomation.value = automation
  wizardStageId.value = automation.stage_id ?? null
  showWizard.value = true
}

async function handleAutomationSaved() {
  toast.add({ severity: 'success', summary: t('automation.toast.saved'), life: 3000 })
  pipelineAutomations.invalidate()
}

async function handleDeleteAutomation(id: number) {
  confirm.require({
    header: t('automation.toast.deleteConfirm'),
    message: t('automation.toast.deleteBody'),
    acceptLabel: t('common.delete'),
    rejectLabel: t('common.cancel'),
    acceptProps: { severity: 'danger' },
    accept: async () => {
      try {
        await pipelineAutomations.deleteAutomation(id)
        toast.add({ severity: 'success', summary: t('automation.toast.deleted'), life: 3000 })
      } catch (e: unknown) {
        toast.add({ severity: 'error', summary: t('errors.server_error'), detail: extractErrorMessage(e), life: 5000 })
      }
    },
  })
}

async function handleToggleAutomation(id: number, isActive: boolean) {
  try {
    await pipelineAutomations.toggleActive(id, isActive)
    toast.add({
      severity: 'success',
      summary: isActive ? t('automation.toast.activated') : t('automation.toast.deactivated'),
      life: 2000,
    })
  } catch (e: unknown) {
    toast.add({ severity: 'error', summary: t('errors.server_error'), detail: extractErrorMessage(e), life: 5000 })
  }
}

// ─── Watch pipeline selection → fetch automations ─────────────────────────────

watch(selectedPipelineId, async (id) => {
  if (id !== null) {
    await pipelineAutomations.fetchForPipeline(id)
  }
})

// ─── Init ─────────────────────────────────────────────────────────────────────

onMounted(async () => {
  await fetchPipelines()
})
</script>

<style lang="scss" scoped>
.pipeline-settings-page {
  display: flex;
  flex-direction: column;
  height: 100%;

  &__content {
    display: flex;
    flex-direction: column;
    gap: $space-6;
    padding: $space-6;
    flex: 1;
    overflow-y: auto;
    min-height: 0;
    max-width: 900px;
  }
}
</style>
