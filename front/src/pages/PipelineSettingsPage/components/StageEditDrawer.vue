<template>
  <Drawer
    v-model:visible="visible"
    position="right"
    style="width: 560px"
    :show-close-icon="false"
  >
    <template #header>
      <div class="stage-drawer__header">
        <span class="stage-drawer__header-title">{{ t('sales.stageEditor.editDrawer.title') }}</span>
        <Button
          icon="pi pi-times"
          severity="secondary"
          text
          rounded
          :disabled="saving"
          :aria-label="t('common.close')"
          @click="visible = false"
        />
      </div>
    </template>
    <div v-if="stage" class="stage-drawer">
      <!-- Name -->
      <div class="stage-drawer__field">
        <label class="stage-drawer__label">
          {{ t('sales.stageEditor.fields.name') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.name"
          class="w-full"
          :class="{ 'p-invalid': errors.name }"
          :disabled="saving"
        />
        <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
      </div>

      <!-- Code (always read-only after creation) -->
      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.code') }}</label>
        <InputText
          :model-value="stage.code"
          class="w-full"
          disabled
        />
        <small class="stage-drawer__hint">{{ t('sales.stageEditor.fields.codeReadonlyHint') }}</small>
      </div>

      <!-- Color -->
      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.color') }}</label>
        <StageColorPicker v-model="form.color" />
      </div>

      <Divider />

      <!-- Поведение -->
      <p class="stage-drawer__section-label">{{ t('sales.stageEditor.fields.hiddenByDefault') }}</p>

      <div class="stage-drawer__field stage-drawer__field--inline">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.hiddenByDefault') }}</label>
        <ToggleSwitch v-model="form.hidden_by_default" />
      </div>

      <div class="stage-drawer__field stage-drawer__field--inline">
        <div class="stage-drawer__label-group">
          <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.wonGate') }}</label>
          <small class="stage-drawer__hint">{{ t('sales.stageEditor.fields.wonGateHint') }}</small>
        </div>
        <ToggleSwitch v-model="form.won_gate" />
      </div>

      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.slaHours') }}</label>
        <InputNumber
          v-model="form.sla_hours"
          :min="1"
          suffix=" ч"
          show-clear
          :placeholder="t('sales.stageEditor.fields.slaPlaceholder')"
          class="w-full"
        />
      </div>

      <Divider />

      <!-- Stage features -->
      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.stageFeatures') }}</label>
        <MultiSelect
          v-model="form.stage_features"
          :options="stageFeaturesOptions"
          option-label="label"
          option-value="value"
          display="chip"
          class="w-full"
        />
      </div>

      <!-- Task types -->
      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.taskTypes') }}</label>
        <MultiSelect
          v-model="form.task_types"
          :options="activityTypeOptions"
          option-label="label"
          option-value="value"
          display="chip"
          class="w-full"
        />
        <small class="stage-drawer__hint">{{ t('sales.stageEditor.fields.taskTypesHint') }}</small>
      </div>

      <Divider />

      <!-- Required fields -->
      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.requiredFieldsDeal') }}</label>
        <MultiSelect
          v-model="form.required_fields_deal"
          :options="dealFieldOptions"
          option-label="label"
          option-value="value"
          display="chip"
          class="w-full"
        />
      </div>

      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.requiredFieldsCompany') }}</label>
        <MultiSelect
          v-model="form.required_fields_company"
          :options="companyFieldOptions"
          option-label="label"
          option-value="value"
          display="chip"
          class="w-full"
        />
      </div>

      <Divider />

      <!-- Parent stage -->
      <div class="stage-drawer__field">
        <label class="stage-drawer__label">{{ t('sales.stageEditor.fields.parentStageId') }}</label>
        <Select
          v-model="form.parent_stage_id"
          :options="parentableStages"
          option-label="name"
          option-value="id"
          show-clear
          :placeholder="t('sales.stageEditor.fields.parentStagePlaceholder')"
          class="w-full"
        />
      </div>
    </div>

    <template #footer>
      <div class="stage-drawer__footer">
        <Button
          :label="t('sales.stageEditor.editDrawer.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          :label="t('sales.stageEditor.editDrawer.save')"
          icon="pi pi-check"
          :loading="saving"
          severity="primary"
          @click="submit"
        />
      </div>
    </template>
  </Drawer>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Drawer from 'primevue/drawer'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import ToggleSwitch from 'primevue/toggleswitch'
import MultiSelect from 'primevue/multiselect'
import Select from 'primevue/select'
import Divider from 'primevue/divider'
import StageColorPicker from './StageColorPicker.vue'
import type { PipelineStageDto, UpdateStagePayload } from '@/entities/sales'

const props = defineProps<{
  visible: boolean
  stage: PipelineStageDto | null
  allStages: PipelineStageDto[]
  saving?: boolean
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  save: [stageId: number, payload: UpdateStagePayload]
}>()

const { t } = useI18n()

// ─── Dictionaries ─────────────────────────────────────────────────────────────

const stageFeaturesOptions = [
  { label: t('sales.stageEditor.stageFeatureOptions.send_presentation'), value: 'send_presentation' },
  { label: t('sales.stageEditor.stageFeatureOptions.meeting_report'),    value: 'meeting_report' },
  { label: t('sales.stageEditor.stageFeatureOptions.generate_document'), value: 'generate_document' },
]

const activityTypeOptions = [
  { label: t('sales.stageEditor.activityTypeOptions.call'),    value: 'call' },
  { label: t('sales.stageEditor.activityTypeOptions.meeting'), value: 'meeting' },
  { label: t('sales.stageEditor.activityTypeOptions.task'),    value: 'task' },
  { label: t('sales.stageEditor.activityTypeOptions.note'),    value: 'note' },
]

const dealFieldOptions = [
  { label: t('sales.stageEditor.dealFieldOptions.title'),                 value: 'title' },
  { label: t('sales.stageEditor.dealFieldOptions.currency'),              value: 'currency' },
  { label: t('sales.stageEditor.dealFieldOptions.contract_id'),           value: 'contract_id' },
  { label: t('sales.stageEditor.dealFieldOptions.expected_close_date'),   value: 'expected_close_date' },
  { label: t('sales.stageEditor.dealFieldOptions.expected_sign_date'),    value: 'expected_sign_date' },
  { label: t('sales.stageEditor.dealFieldOptions.expected_payment_date'), value: 'expected_payment_date' },
]

const companyFieldOptions = [
  { label: t('sales.stageEditor.companyFieldOptions.name'),  value: 'name' },
  { label: t('sales.stageEditor.companyFieldOptions.inn'),   value: 'inn' },
  { label: t('sales.stageEditor.companyFieldOptions.phone'), value: 'phone' },
  { label: t('sales.stageEditor.companyFieldOptions.email'), value: 'email' },
]

// ─── Form ─────────────────────────────────────────────────────────────────────

interface DrawerForm {
  name: string
  color: string | null
  hidden_by_default: boolean
  won_gate: boolean
  sla_hours: number | null
  stage_features: string[]
  task_types: string[]
  required_fields_deal: string[]
  required_fields_company: string[]
  parent_stage_id: number | null
}

const form = ref<DrawerForm>({
  name: '',
  color: null,
  hidden_by_default: false,
  won_gate: false,
  sla_hours: null,
  stage_features: [],
  task_types: [],
  required_fields_deal: [],
  required_fields_company: [],
  parent_stage_id: null,
})

const errors = ref({ name: '' })

// Populate form when stage changes
watch(
  () => props.stage,
  (s) => {
    if (!s) return
    form.value = {
      name: s.name,
      color: s.color ?? null,
      hidden_by_default: s.hidden_by_default,
      won_gate: s.won_gate,
      sla_hours: s.sla_hours,
      stage_features: [...(s.stage_features ?? [])],
      task_types: [...(s.task_types ?? [])],
      required_fields_deal: [...(s.required_fields?.deal ?? [])],
      required_fields_company: [...(s.required_fields?.company ?? [])],
      parent_stage_id: s.parent_stage_id,
    }
    errors.value = { name: '' }
  },
  { immediate: true },
)

// Proxy visible
const visible = ref(props.visible)
watch(() => props.visible, (v) => { visible.value = v })
watch(visible, (v) => emit('update:visible', v))

// Top-level stages except current
const parentableStages = computed<PipelineStageDto[]>(() => {
  const currentId = props.stage?.id
  return props.allStages.filter((s) => s.parent_stage_id === null && s.id !== currentId)
})

// ─── Submit ───────────────────────────────────────────────────────────────────

function validate(): boolean {
  errors.value.name = ''
  if (!form.value.name.trim()) {
    errors.value.name = t('errors.validation')
    return false
  }
  return true
}

function submit() {
  if (!validate() || !props.stage) return

  const payload: UpdateStagePayload = {
    name: form.value.name.trim(),
    color: form.value.color,
    hidden_by_default: form.value.hidden_by_default,
    won_gate: form.value.won_gate,
    sla_hours: form.value.sla_hours,
    stage_features: form.value.stage_features,
    task_types: form.value.task_types,
    required_fields: {
      deal: form.value.required_fields_deal,
      company: form.value.required_fields_company,
    },
    parent_stage_id: form.value.parent_stage_id,
  }

  emit('save', props.stage.id, payload)
}
</script>

<style lang="scss" scoped>
.stage-drawer {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;

  &__header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    width: 100%;
    gap: $space-2;
  }

  &__header-title {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    flex: 1;
    min-width: 0;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;

    &--inline {
      flex-direction: row;
      align-items: flex-start;
      justify-content: space-between;
      gap: $space-4;
    }
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);

    .req {
      color: var(--p-red-500);
      margin-left: 2px;
    }
  }

  &__label-group {
    display: flex;
    flex-direction: column;
    gap: 2px;
    flex: 1;
  }

  &__hint {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
  }

  &__section-label {
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    color: var(--p-text-muted-color);
    margin: 0;
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
  }
}

:deep(.p-drawer-close-button) {
  display: none !important;
}
</style>
