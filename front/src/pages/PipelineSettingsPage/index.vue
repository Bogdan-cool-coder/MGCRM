<template>
  <div class="pipeline-settings-page">
    <!-- Page header — hidden in canvas mode to reclaim vertical space -->
    <PageHeader v-if="viewMode !== 'canvas'" :title="t('sales.pipelineEditor.pageTitle')" icon="pi pi-sliders-h" />

    <div
      class="pipeline-settings-page__content"
      :class="{ 'pipeline-settings-page__content--canvas': viewMode === 'canvas' }"
    >
      <!-- ── CANVAS compact chrome ──────────────────────────────────────────── -->
      <!-- In canvas mode the full PipelineList card + PageHeader are hidden.    -->
      <!-- Instead we show a single slim bar: pipeline switcher + mode toggle.   -->
      <div v-if="viewMode === 'canvas'" class="pipeline-settings-page__canvas-bar">
        <!-- Pipeline switcher (compact Select — triggers full selectPipeline) -->
        <Select
          :model-value="selectedPipelineId"
          :options="pipelines"
          option-label="name"
          option-value="id"
          :placeholder="t('sales.pipelineEditor.selectPlaceholder')"
          size="small"
          class="pipeline-settings-page__canvas-pipeline-select"
          @change="(e: SelectChangeEvent) => selectPipeline(e.value as number)"
        />
        <!-- Spacer -->
        <span class="pipeline-settings-page__canvas-bar-spacer" />
        <!-- Mode toggle -->
        <SelectButton
          v-model="viewMode"
          :options="viewModeOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
        />
      </div>

      <!-- ── FORM MODE chrome ───────────────────────────────────────────────── -->
      <!-- Pipelines section (full card — form mode only) -->
      <PipelineList
        v-if="viewMode !== 'canvas'"
        :pipelines="pipelines"
        :selected-pipeline-id="selectedPipelineId"
        :loading="pipelinesLoading"
        :duplicating-id="duplicatingPipelineId"
        :highlighted-id="highlightedPipelineId"
        @create="showCreatePipeline = true"
        @select="selectPipeline"
        @rename="handleRenamePipeline"
        @duplicate="handleDuplicatePipeline"
        @delete="handleDeletePipeline"
      />

      <!-- View mode toggle — form mode only, shown when a pipeline is selected -->
      <div v-if="viewMode !== 'canvas' && selectedPipelineId !== null" class="pipeline-settings-page__mode-bar">
        <SelectButton
          v-model="viewMode"
          :options="viewModeOptions"
          option-label="label"
          option-value="value"
          :allow-empty="false"
        />
      </div>

      <!-- FORM MODE: Stages editor + Automation panel -->
      <template v-if="viewMode === 'form'">
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
      </template>

      <!-- CANVAS MODE -->
      <div
        v-else-if="viewMode === 'canvas' && selectedPipelineId !== null"
        class="pipeline-settings-page__canvas-area"
      >
        <PipelineCanvas
          :key="`canvas-${selectedPipelineId}-${canvasMountSeq}`"
          :pipeline-id="selectedPipelineId"
          :stages="stages"
          :automations="pipelineAutomations.automations.value"
          :graph-layout="selectedPipeline?.graph_layout ?? null"
          @switch-to-form="viewMode = 'form'"
          @add-automation="openWizardForStage"
          @add-automation-with-action="openWizardWithAction"
          @edit-automation="openWizardForEdit"
          @delete-automation="handleDeleteAutomation"
          @toggle-automation="handleToggleAutomation"
        />
      </div>
    </div>

    <!-- Create Pipeline Dialog -->
    <CreatePipelineDialog
      v-model:visible="showCreatePipeline"
      :saving="creatingPipeline"
      :pipelines="pipelines"
      @create="handleCreatePipeline"
      @duplicate="handleDuplicateFromTemplate"
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
      :initial-action-kind="wizardInitialActionKind"
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
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import ConfirmDialog from 'primevue/confirmdialog'
import Toast from 'primevue/toast'
import SelectButton from 'primevue/selectbutton'
import Select from 'primevue/select'
import type { SelectChangeEvent } from 'primevue/select'
import { PageHeader } from '@/components/AppShell'
import PipelineList from './components/PipelineList.vue'
import StageEditorList from './components/StageEditorList.vue'
import CreatePipelineDialog from './components/CreatePipelineDialog.vue'
import CreateStageDialog from './components/CreateStageDialog.vue'
import StageEditDrawer from './components/StageEditDrawer.vue'
import AutomationListPanel from './components/AutomationListPanel.vue'
import AutomationWizardDialog from './components/AutomationWizardDialog.vue'
import PipelineCanvas from './canvas/PipelineCanvas.vue'
import { usePipelineSettings } from './composables/usePipelineSettings'
import { usePipelineAutomations } from './composables/usePipelineAutomations'
import type { PipelineStageDto, CreateStagePayload, UpdateStagePayload } from '@/entities/sales'
import type { AutomationDto, ActionKind } from '@/entities/automation'

const { t } = useI18n()
const confirm = useConfirm()
const toast = useToast()

// ─── View mode (form | canvas) ────────────────────────────────────────────────

type ViewMode = 'form' | 'canvas'
const viewMode = ref<ViewMode>('form')

// canvasMountSeq increments every time we enter canvas mode (or switch pipeline
// while already in canvas mode). The :key on PipelineCanvas is derived from
// this counter so Vue fully destroys and re-creates the component on each open,
// giving a fresh Vue Flow store, a new canvasId, and a clean fitView cycle.
const canvasMountSeq = ref(0)

watch(viewMode, (v) => {
  if (v === 'canvas') canvasMountSeq.value++
})

const viewModeOptions = computed(() => [
  { label: t('automation.canvas.modeForm'), value: 'form' },
  { label: t('automation.canvas.modeCanvas'), value: 'canvas' },
])

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
  highlightedPipelineId,
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
  duplicatePipeline,
  createStage,
  updateStage,
  deleteStage,
  reorderStages,
} = usePipelineSettings()

const pipelineAutomations = usePipelineAutomations()

// ─── Dialog / Drawer state ────────────────────────────────────────────────────

const showCreatePipeline = ref(false)
const creatingPipeline = ref(false)
const duplicatingPipelineId = ref<number | null>(null)

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
const wizardInitialActionKind = ref<ActionKind | null>(null)

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

/** Duplicate triggered from the inline "Copy" button on a list item */
async function handleDuplicatePipeline(id: number) {
  duplicatingPipelineId.value = id
  try {
    await duplicatePipeline(id)
  } finally {
    duplicatingPipelineId.value = null
  }
}

/** Duplicate triggered from the "Create" dialog's "from template" mode */
async function handleDuplicateFromTemplate(sourceId: number) {
  creatingPipeline.value = true
  try {
    const result = await duplicatePipeline(sourceId)
    if (result !== null) showCreatePipeline.value = false
  } finally {
    creatingPipeline.value = false
  }
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

function openWizardForStage(stageId: number | null) {
  editingAutomation.value = null
  wizardInitialActionKind.value = null
  wizardStageId.value = stageId
  showWizard.value = true
}

function openWizardWithAction(stageId: number | null, actionKind: ActionKind) {
  editingAutomation.value = null
  wizardInitialActionKind.value = actionKind
  wizardStageId.value = stageId
  showWizard.value = true
}

function openWizardForPipeline() {
  editingAutomation.value = null
  wizardInitialActionKind.value = null
  wizardStageId.value = null
  showWizard.value = true
}

function openWizardForEdit(automation: AutomationDto) {
  editingAutomation.value = automation
  wizardInitialActionKind.value = null
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
    // If we are already in canvas mode, switching pipeline must also force a
    // fresh PipelineCanvas mount (new graph layout, new Vue Flow store).
    if (viewMode.value === 'canvas') canvasMountSeq.value++
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
  // flex:1 + min-height:0 makes this page fill the available height in the
  // layout__content flex-column context (layout now sets display:flex).
  // height:100% is NOT used because layout__content is a scroll-container;
  // percentage heights resolve to scroll-content height there, not viewport.
  flex: 1;
  min-height: 0;

  &__content {
    display: flex;
    flex-direction: column;
    gap: $space-6;
    padding: $space-6;
    flex: 1;
    overflow-y: auto;
    min-height: 0;
    max-width: 900px;

    &--canvas {
      max-width: none;
      overflow: hidden;
      padding: $space-3 $space-6 0;
      gap: $space-3;
      // Нейтрализуем padding layout__content, чтобы canvas мог занять всю высоту
      margin-bottom: calc(-1 * $space-4);
    }
  }

  &__mode-bar {
    display: flex;
    align-items: center;
  }

  // ── Compact chrome shown only in canvas mode ────────────────────────────────
  // Replaces the full PageHeader (~56px) + PipelineList card (~120px+) with a
  // single 40px bar, giving the canvas ~176px+ of extra vertical space.

  &__canvas-bar {
    display: flex;
    align-items: center;
    gap: $space-3;
    flex-shrink: 0;
  }

  &__canvas-pipeline-select {
    // Compact width — enough for a pipeline name (≤ 200 chars typical)
    width: 220px;
  }

  &__canvas-bar-spacer {
    flex: 1;
  }

  &__canvas-area {
    // Must be a flex-column container so that PipelineCanvas (its sole child)
    // can use flex:1 to fill the available height instead of height:100%
    // (which would resolve to scroll-content height in a non-flex parent).
    display: flex;
    flex-direction: column;
    flex: 1;
    // Safety floor lowered from 560px → 480px now that the compact bar saves
    // ~176px of chrome. On a typical 900px-tall viewport the canvas gains that
    // extra vertical space automatically via flex:1; the floor only kicks in
    // when the flex chain is broken by an intermediate non-flex ancestor.
    min-height: 480px;
    border: 1px solid var(--p-surface-border);
    border-radius: var(--p-border-radius);
    overflow: hidden;
    background: var(--p-surface-ground);
  }
}
</style>
