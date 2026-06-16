<template>
  <Dialog
    v-model:visible="visible"
    :header="isEdit ? t('automation.wizard.titleEdit') : t('automation.wizard.titleNew')"
    :style="{ width: '640px' }"
    modal
    :closable="true"
    @hide="onDialogHide"
  >
    <!-- Error banner -->
    <Message v-if="apiError" severity="error" :closable="false" class="mb-3">
      {{ apiError }}
    </Message>

    <!-- Step indicator (visual only — no StepPanels, no slot isolation) -->
    <div class="wizard-steps-indicator mb-4">
      <div
        v-for="step in STEPS"
        :key="step.value"
        :class="['wizard-step-item', { 'is-active': currentStep === step.value, 'is-done': currentStep > step.value }]"
      >
        <div class="wizard-step-item__circle">
          <i v-if="currentStep > step.value" class="pi pi-check" />
          <span v-else>{{ step.value }}</span>
        </div>
        <span class="wizard-step-item__label">{{ step.label }}</span>
        <div v-if="step.value < STEPS.length" class="wizard-step-item__connector" />
      </div>
    </div>

    <!-- Step 1: Action Picker -->
    <div v-show="currentStep === 1" class="wizard-panel">
      <ActionPickerStep v-model="selectedAction" />
    </div>

    <!-- Step 2: Action Config -->
    <div v-show="currentStep === 2" class="wizard-panel">
      <ActionConfigStep
        v-if="selectedAction"
        ref="configStepRef"
        :action-kind="selectedAction"
        :model-name="automationName"
        :model-config="actionConfig"
        :stages="stages"
        :stage-id="stageId"
        @update:model-name="automationName = $event"
        @update:model-config="actionConfig = $event"
      />
    </div>

    <!-- Step 3: Trigger Config -->
    <div v-show="currentStep === 3" class="wizard-panel">
      <TriggerConfigStep
        ref="triggerStepRef"
        :model-trigger="triggerKind"
        :model-config="triggerConfig"
        :model-is-active="isActive"
        @update:model-trigger="triggerKind = $event"
        @update:model-config="triggerConfig = $event"
        @update:model-is-active="isActive = $event"
      />
    </div>

    <!-- Navigation footer -->
    <div class="wizard-footer">
      <!-- Back button (steps 2 and 3) -->
      <Button
        v-if="currentStep > 1"
        :label="t('automation.wizard.back')"
        icon="pi pi-arrow-left"
        severity="secondary"
        text
        @click="goToStep(currentStep - 1)"
      />
      <span v-else />

      <!-- Next / Save -->
      <Button
        v-if="currentStep === 1"
        :label="t('automation.wizard.next')"
        icon="pi pi-arrow-right"
        icon-pos="right"
        :disabled="!selectedAction"
        @click="goToStep(2)"
      />
      <Button
        v-else-if="currentStep === 2"
        :label="t('automation.wizard.next')"
        icon="pi pi-arrow-right"
        icon-pos="right"
        @click="onStep2Next"
      />
      <Button
        v-else
        :label="isEdit ? t('automation.wizard.save') : t('automation.wizard.create')"
        icon="pi pi-check"
        :loading="saving"
        @click="onSubmit"
      />
    </div>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Message from 'primevue/message'
import ActionPickerStep from './wizard/ActionPickerStep.vue'
import ActionConfigStep from './wizard/ActionConfigStep.vue'
import TriggerConfigStep from './wizard/TriggerConfigStep.vue'
import type { ActionKind, TriggerKind, AutomationDto } from '@/entities/automation'
import type { PipelineStageDto } from '@/entities/sales'

const props = defineProps<{
  modelVisible: boolean
  pipelineId: number
  stageId: number | null
  stages: PipelineStageDto[]
  editAutomation: AutomationDto | null
  /** When set (drag from ToolPalette), wizard opens at step 2 with this action pre-selected */
  initialActionKind?: ActionKind | null
}>()

const emit = defineEmits<{
  'update:modelVisible': [v: boolean]
  saved: []
}>()

const { t } = useI18n()

// ─── Step metadata ────────────────────────────────────────────────────────────

const STEPS = computed(() => [
  { value: 1, label: t('automation.wizard.step1.label') },
  { value: 2, label: t('automation.wizard.step2.label') },
  { value: 3, label: t('automation.wizard.step3.label') },
])

// ─── State ────────────────────────────────────────────────────────────────────

const visible = ref(props.modelVisible)
const currentStep = ref(1)
const saving = ref(false)
const apiError = ref<string | null>(null)

const selectedAction = ref<ActionKind | null>(null)
const automationName = ref('')
const actionConfig = ref<Record<string, unknown>>({})
const triggerKind = ref<TriggerKind | null>(null)
const triggerConfig = ref<Record<string, unknown>>({})
const isActive = ref(true)

const isEdit = ref(false)
const editId = ref<number | null>(null)

// These refs now reliably resolve because the child components are always
// mounted (v-show, not v-if inside StepPanel slots).
const configStepRef = ref<{ validate: () => boolean } | null>(null)
const triggerStepRef = ref<{ validate: () => boolean } | null>(null)

// ─── Sync visible ─────────────────────────────────────────────────────────────

watch(
  () => props.modelVisible,
  (v) => {
    visible.value = v
    if (v) initFromProps()
  },
)

watch(visible, (v) => {
  // onDialogHide handles the false → close path authoritatively.
  // Only emit when visible goes true (open), or when it closes via programmatic
  // assignment (e.g. visible.value = false inside onSubmit). The @hide handler
  // takes care of X / Escape closes so we avoid a double-reset there.
  emit('update:modelVisible', v)
  if (!v) resetState()
})

// ─── Init / Prefill ───────────────────────────────────────────────────────────

function initFromProps() {
  resetState()
  if (props.editAutomation) {
    isEdit.value = true
    editId.value = props.editAutomation.id
    selectedAction.value = props.editAutomation.action_kind
    automationName.value = props.editAutomation.name
    actionConfig.value = { ...props.editAutomation.action_config }
    triggerKind.value = props.editAutomation.trigger_kind
    triggerConfig.value = { ...props.editAutomation.trigger_config }
    isActive.value = props.editAutomation.is_active
    // Start at step 1 so user can review / change action
    currentStep.value = 1
  } else if (props.initialActionKind) {
    // Drag-from-palette: pre-select action, jump to step 2
    selectedAction.value = props.initialActionKind
    currentStep.value = 2
  }
}

function resetState() {
  currentStep.value = 1
  selectedAction.value = null
  automationName.value = ''
  actionConfig.value = {}
  triggerKind.value = null
  triggerConfig.value = {}
  isActive.value = true
  isEdit.value = false
  editId.value = null
  apiError.value = null
  saving.value = false
}

// PrimeVue Dialog emits @hide when the dialog finishes closing (X button, Escape,
// or programmatic close). Using @hide as the authoritative close handler means
// the wizard always resets to step 1 and notifies the parent — regardless of
// which step was active when the user clicked X. The visible watcher below still
// runs but is now guarded against redundant resets.
function onDialogHide() {
  visible.value = false
  emit('update:modelVisible', false)
  resetState()
}

// ─── Navigation ───────────────────────────────────────────────────────────────

function goToStep(n: number) {
  currentStep.value = n
  apiError.value = null
}

function onStep2Next() {
  if (configStepRef.value && !configStepRef.value.validate()) return
  goToStep(3)
}

// ─── Submit ───────────────────────────────────────────────────────────────────

async function onSubmit() {
  if (triggerStepRef.value && !triggerStepRef.value.validate()) return
  if (!selectedAction.value || !triggerKind.value) return

  saving.value = true
  apiError.value = null

  try {
    // Lazy import to avoid circular deps
    const { automationsApi } = await import('@/api/automation')

    if (isEdit.value && editId.value !== null) {
      await automationsApi.update(editId.value, {
        name: automationName.value,
        trigger_kind: triggerKind.value,
        trigger_config: triggerConfig.value,
        action_kind: selectedAction.value,
        action_config: actionConfig.value,
        is_active: isActive.value,
      })
    } else {
      await automationsApi.create({
        name: automationName.value,
        pipeline_id: props.pipelineId,
        stage_id: props.stageId ?? null,
        trigger_kind: triggerKind.value,
        trigger_config: triggerConfig.value,
        action_kind: selectedAction.value,
        action_config: actionConfig.value,
        is_active: isActive.value,
      })
    }

    visible.value = false
    emit('saved')
  } catch (e: unknown) {
    apiError.value = extractMessage(e)
  } finally {
    saving.value = false
  }
}

function extractMessage(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const err = e as Record<string, unknown>
    const response = err.response as Record<string, unknown> | null
    const data = response?.data as Record<string, unknown> | null
    if (data?.message && typeof data.message === 'string') return data.message
    if (typeof err.message === 'string') return err.message
  }
  return String(e)
}
</script>

<style lang="scss" scoped>
// ─── Step indicator ───────────────────────────────────────────────────────────

.wizard-steps-indicator {
  display: flex;
  align-items: center;
}

.wizard-step-item {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-shrink: 0;

  &__circle {
    width: 28px;
    height: 28px;
    border-radius: 50%;
    border: 2px solid var(--p-surface-300);
    background: var(--p-surface-0);
    color: var(--p-text-muted-color);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    flex-shrink: 0;
    transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast), color var(--app-transition-fast);

    .app-dark & {
      border-color: var(--p-surface-600);
      background: var(--p-surface-800);
    }
  }

  &__label {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
    white-space: nowrap;
    transition: color var(--app-transition-fast);
  }

  &__connector {
    flex: 1;
    min-width: 24px;
    height: 2px;
    background: var(--p-surface-200);
    margin: 0 $space-2;
    border-radius: 1px;

    .app-dark & {
      background: var(--p-surface-700);
    }
  }

  // Active step
  &.is-active {
    .wizard-step-item__circle {
      border-color: var(--p-primary-color);
      background: var(--p-primary-color);
      color: #fff;
    }

    .wizard-step-item__label {
      color: var(--p-text-color);
      font-weight: $font-weight-medium;
    }
  }

  // Completed step
  &.is-done {
    .wizard-step-item__circle {
      border-color: var(--p-primary-color);
      background: var(--p-primary-50);
      color: var(--p-primary-color);

      .app-dark & {
        background: var(--p-primary-900);
        color: var(--p-primary-300);
      }
    }

    .wizard-step-item__label {
      color: var(--p-text-muted-color);
    }
  }
}

// ─── Panel & footer ───────────────────────────────────────────────────────────

.wizard-panel {
  min-height: 280px;
  padding: $space-2 0 $space-4;
}

.wizard-footer {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid var(--p-surface-200);

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}
</style>
