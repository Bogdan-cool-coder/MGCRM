<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.stageEditor.createDialog.title')"
    modal
    style="width: 480px"
    :closable="!saving"
  >
    <div class="create-stage-dialog">
      <!-- Name -->
      <div class="create-stage-dialog__field">
        <label class="create-stage-dialog__label">
          {{ t('sales.stageEditor.fields.name') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.name"
          class="w-full"
          :class="{ 'p-invalid': errors.name }"
          :disabled="saving"
          @keydown.enter.prevent="submit"
        />
        <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
      </div>

      <!-- Code -->
      <div class="create-stage-dialog__field">
        <label class="create-stage-dialog__label">
          {{ t('sales.stageEditor.fields.code') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.code"
          class="w-full"
          :class="{ 'p-invalid': errors.code }"
          :disabled="saving"
          placeholder="qualify"
          @input="sanitizeCode"
        />
        <small v-if="errors.code" class="p-error">{{ errors.code }}</small>
        <small v-else class="create-stage-dialog__hint">a-z, 0-9, _, -</small>
      </div>

      <!-- Color -->
      <div class="create-stage-dialog__field">
        <label class="create-stage-dialog__label">{{ t('sales.stageEditor.fields.color') }}</label>
        <div class="create-stage-dialog__color-row">
          <ColorPicker v-model="form.colorHex" format="hex" />
          <span
            class="create-stage-dialog__color-preview"
            :style="{ backgroundColor: `#${form.colorHex}` }"
          />
          <span class="create-stage-dialog__color-val">#{{ form.colorHex.toUpperCase() }}</span>
        </div>
      </div>

      <!-- Hidden on board -->
      <div class="create-stage-dialog__field create-stage-dialog__field--inline">
        <label class="create-stage-dialog__label">
          {{ t('sales.stageEditor.fields.hiddenByDefault') }}
        </label>
        <ToggleSwitch v-model="form.hidden_by_default" />
      </div>

      <!-- Parent stage -->
      <div class="create-stage-dialog__field">
        <label class="create-stage-dialog__label">
          {{ t('sales.stageEditor.fields.parentStageId') }}
        </label>
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
      <Button
        :label="t('sales.stageEditor.createDialog.cancel')"
        severity="secondary"
        text
        :disabled="saving"
        @click="cancel"
      />
      <Button
        :label="t('sales.stageEditor.createDialog.save')"
        icon="pi pi-check"
        :loading="saving"
        severity="primary"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Button from 'primevue/button'
import ColorPicker from 'primevue/colorpicker'
import ToggleSwitch from 'primevue/toggleswitch'
import Select from 'primevue/select'
import type { PipelineStageDto, CreateStagePayload } from '@/entities/sales'

const props = defineProps<{
  visible: boolean
  stages: PipelineStageDto[]
  saving?: boolean
  fieldErrors?: Record<string, string>
}>()

const emit = defineEmits<{
  'update:visible': [value: boolean]
  create: [payload: CreateStagePayload]
}>()

const { t } = useI18n()

interface StageForm {
  name: string
  code: string
  colorHex: string
  hidden_by_default: boolean
  parent_stage_id: number | null
}

const defaultForm = (): StageForm => ({
  name: '',
  code: '',
  colorHex: '9B9C9F',
  hidden_by_default: false,
  parent_stage_id: null,
})

const form = ref<StageForm>(defaultForm())
const errors = ref({ name: '', code: '' })

// Reset on open
watch(
  () => props.visible,
  (v) => {
    if (v) {
      form.value = defaultForm()
      errors.value = { name: '', code: '' }
    }
  },
)

// External field errors from API 422
watch(
  () => props.fieldErrors,
  (fe) => {
    if (fe?.code) errors.value.code = fe.code
    if (fe?.name) errors.value.name = fe.name
  },
)

const visible = ref(props.visible)
watch(() => props.visible, (v) => { visible.value = v })
watch(visible, (v) => emit('update:visible', v))

// Only top-level stages (no substages as parents)
const parentableStages = computed<PipelineStageDto[]>(() =>
  props.stages.filter((s) => s.parent_stage_id === null),
)

function sanitizeCode() {
  form.value.code = form.value.code.toLowerCase().replace(/[^a-z0-9_-]/g, '')
}

function validate(): boolean {
  let valid = true
  errors.value = { name: '', code: '' }

  if (!form.value.name.trim()) {
    errors.value.name = t('errors.validation')
    valid = false
  }
  if (!form.value.code.trim()) {
    errors.value.code = t('errors.validation')
    valid = false
  } else if (!/^[a-z0-9_-]+$/.test(form.value.code)) {
    errors.value.code = 'Только a-z, 0-9, _, -'
    valid = false
  }
  return valid
}

function submit() {
  if (!validate()) return
  const payload: CreateStagePayload = {
    name: form.value.name.trim(),
    code: form.value.code,
    color: `#${form.value.colorHex.toUpperCase()}`,
    hidden_by_default: form.value.hidden_by_default,
    parent_stage_id: form.value.parent_stage_id,
  }
  emit('create', payload)
}

function cancel() {
  emit('update:visible', false)
}
</script>

<style lang="scss" scoped>
.create-stage-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;

    &--inline {
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
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

  &__hint {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
  }

  &__color-row {
    display: flex;
    align-items: center;
    gap: $space-3;
  }

  &__color-preview {
    width: 28px;
    height: 28px;
    border-radius: $radius-sm;
    border: 1px solid var(--p-surface-300);
    flex-shrink: 0;
  }

  &__color-val {
    font-size: $font-size-sm;
    font-family: $font-family-mono;
    color: var(--p-text-muted-color);
  }
}
</style>
