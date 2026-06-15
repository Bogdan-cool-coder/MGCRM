<template>
  <Dialog
    v-model:visible="visible"
    :header="isEdit ? t('automation.wizard.titleEdit') : t('automation.wizard.titleNew')"
    :style="{ width: '640px' }"
    modal
    :close-on-escape="false"
  >
    <!-- Error banner -->
    <Message v-if="apiError" severity="error" :closable="false" class="mb-3">
      {{ apiError }}
    </Message>

    <Stepper :value="currentStep" linear class="wizard-stepper">
      <StepList>
        <Step :value="1">{{ t('automation.wizard.step1.label') }}</Step>
        <Step :value="2">{{ t('automation.wizard.step2.label') }}</Step>
        <Step :value="3">{{ t('automation.wizard.step3.label') }}</Step>
      </StepList>

      <StepPanels>
        <!-- Step 1: Action Picker -->
        <StepPanel :value="1">
          <div class="wizard-panel">
            <ActionPickerStep v-model="selectedAction" />
          </div>
          <div class="wizard-footer">
            <Button
              :label="t('automation.wizard.next')"
              icon="pi pi-arrow-right"
              icon-pos="right"
              :disabled="!selectedAction"
              @click="goToStep(2)"
            />
          </div>
        </StepPanel>

        <!-- Step 2: Action Config -->
        <StepPanel :value="2">
          <div class="wizard-panel">
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
          <div class="wizard-footer">
            <Button
              :label="t('automation.wizard.back')"
              icon="pi pi-arrow-left"
              severity="secondary"
              text
              @click="goToStep(1)"
            />
            <Button
              :label="t('automation.wizard.next')"
              icon="pi pi-arrow-right"
              icon-pos="right"
              @click="onStep2Next"
            />
          </div>
        </StepPanel>

        <!-- Step 3: Trigger Config -->
        <StepPanel :value="3">
          <div class="wizard-panel">
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
          <div class="wizard-footer">
            <Button
              :label="t('automation.wizard.back')"
              icon="pi pi-arrow-left"
              severity="secondary"
              text
              @click="goToStep(2)"
            />
            <Button
              :label="isEdit ? t('automation.wizard.save') : t('automation.wizard.create')"
              icon="pi pi-check"
              :loading="saving"
              @click="onSubmit"
            />
          </div>
        </StepPanel>
      </StepPanels>
    </Stepper>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Stepper from 'primevue/stepper'
import StepList from 'primevue/steplist'
import Step from 'primevue/step'
import StepPanels from 'primevue/steppanels'
import StepPanel from 'primevue/steppanel'
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
}>()

const emit = defineEmits<{
  'update:modelVisible': [v: boolean]
  saved: []
}>()

const { t } = useI18n()

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
.wizard-panel {
  padding: $space-4 0;
  min-height: 280px;
}

.wizard-footer {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-3;
  border-top: 1px solid var(--p-surface-200);

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.wizard-stepper {
  :deep(.p-stepper-nav) {
    margin-bottom: $space-4;
  }
}
</style>
