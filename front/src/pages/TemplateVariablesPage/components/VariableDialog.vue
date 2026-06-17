<template>
  <Dialog
    v-model:visible="visible"
    :header="editing ? t('templateVariables.dialog.editTitle') : t('templateVariables.dialog.createTitle')"
    modal
    :style="{ width: '36rem' }"
    :draggable="false"
  >
    <div class="row g-3">
      <!-- Key (readonly on edit) -->
      <div class="col-12">
        <label class="var-dialog__label">{{ t('templateVariables.dialog.key') }} *</label>
        <InputText
          v-model="form.key"
          :disabled="!!editing"
          class="w-100 mt-1"
          placeholder="company_name"
        />
      </div>

      <!-- Label -->
      <div class="col-12">
        <label class="var-dialog__label">{{ t('templateVariables.dialog.label') }} *</label>
        <InputText v-model="form.label" class="w-100 mt-1" />
      </div>

      <!-- Help text -->
      <div class="col-12">
        <label class="var-dialog__label">{{ t('templateVariables.dialog.helpText') }}</label>
        <InputText v-model="form.help_text" class="w-100 mt-1" />
      </div>

      <!-- Type -->
      <div class="col-md-6">
        <label class="var-dialog__label">{{ t('templateVariables.dialog.varType') }} *</label>
        <Select
          v-model="form.var_type"
          :options="typeOptions"
          option-label="label"
          option-value="value"
          class="w-100 mt-1"
        />
      </div>

      <!-- Group -->
      <div class="col-md-6">
        <label class="var-dialog__label">{{ t('templateVariables.dialog.group') }}</label>
        <InputText v-model="form.group" class="w-100 mt-1" />
      </div>

      <!-- Options (only for select) -->
      <div v-if="form.var_type === 'select'" class="col-12">
        <label class="var-dialog__label">{{ t('templateVariables.dialog.options') }}</label>
        <div v-for="(opt, i) in form.options" :key="i" class="d-flex gap-2 mt-1">
          <InputText v-model="opt.value" placeholder="value" class="flex-1" />
          <InputText v-model="opt.name" placeholder="Название" class="flex-1" />
          <Button
            icon="pi pi-trash"
            text
            severity="danger"
            size="small"
            @click="form.options.splice(i, 1)"
          />
        </div>
        <Button
          :label="t('templateVariables.dialog.addOption')"
          icon="pi pi-plus"
          text
          severity="secondary"
          size="small"
          class="mt-1"
          @click="form.options.push({ value: '', name: '' })"
        />
      </div>

      <!-- Required + Active -->
      <div class="col-md-6 d-flex align-items-center gap-2">
        <Checkbox v-model="form.required" :binary="true" input-id="var-required" />
        <label for="var-required" class="mb-0 var-dialog__label">
          {{ t('templateVariables.dialog.required') }}
        </label>
      </div>
      <div class="col-md-6 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_active" />
        <label class="mb-0 var-dialog__label">{{ t('templateVariables.dialog.isActive') }}</label>
      </div>

      <!-- Sort order -->
      <div class="col-md-6">
        <label class="var-dialog__label">{{ t('templateVariables.dialog.sortOrder') }}</label>
        <InputNumber v-model="form.sort_order" :min="0" class="w-100 mt-1" />
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="cancel" />
      <Button
        :label="t('common.save')"
        :loading="loading"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Checkbox from 'primevue/checkbox'
import ToggleSwitch from 'primevue/toggleswitch'
import type { TemplateVariableDto, TemplateVariableType, CreateTemplateVariablePayload } from '@/entities/templateVariable'

const props = defineProps<{
  modelValue: boolean
  editing: TemplateVariableDto | null
  loading: boolean
  typeOptions: { label: string; value: TemplateVariableType }[]
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: CreateTemplateVariablePayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const form = ref<{
  key: string
  label: string
  help_text: string
  var_type: TemplateVariableType
  options: { value: string; name: string }[]
  required: boolean
  group: string
  sort_order: number
  is_active: boolean
}>({
  key: '',
  label: '',
  help_text: '',
  var_type: 'text',
  options: [],
  required: false,
  group: '',
  sort_order: 0,
  is_active: true,
})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      if (props.editing) {
        form.value = {
          key: props.editing.key,
          label: props.editing.label,
          help_text: props.editing.help_text ?? '',
          var_type: props.editing.var_type,
          options: [...(props.editing.options ?? [])],
          required: props.editing.required,
          group: props.editing.group ?? '',
          sort_order: props.editing.sort_order,
          is_active: props.editing.is_active,
        }
      } else {
        form.value = {
          key: '',
          label: '',
          help_text: '',
          var_type: 'text',
          options: [],
          required: false,
          group: '',
          sort_order: 0,
          is_active: true,
        }
      }
    }
  },
)

function cancel() {
  visible.value = false
}

function submit() {
  const payload: CreateTemplateVariablePayload = {
    key: form.value.key,
    label: form.value.label,
    help_text: form.value.help_text || null,
    var_type: form.value.var_type,
    options: form.value.var_type === 'select' ? form.value.options : [],
    required: form.value.required,
    group: form.value.group || null,
    sort_order: form.value.sort_order,
    is_active: form.value.is_active,
  }
  emit('save', payload)
}
</script>

<style lang="scss" scoped>
.var-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
