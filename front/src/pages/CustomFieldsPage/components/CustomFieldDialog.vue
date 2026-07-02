<template>
  <Dialog
    v-model:visible="visible"
    :header="isEditing ? t('customFields.edit') : t('customFields.add')"
    modal
    :draggable="false"
    :style="{ width: '32rem' }"
  >
    <div class="row g-3">
      <!-- Label -->
      <div class="col-12">
        <label class="cf-dialog__label">{{ t('customFields.fields.label') }} <span class="cf-dialog__req">*</span></label>
        <InputText
          v-model="form.label"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.label }"
          maxlength="255"
          autofocus
        />
        <small v-if="errors.label" class="p-error">{{ t('customFields.errors.required') }}</small>
      </div>

      <!-- Code -->
      <div class="col-12">
        <label class="cf-dialog__label">{{ t('customFields.fields.code') }} <span v-if="!isEditing" class="cf-dialog__req">*</span></label>
        <InputText
          v-model="form.code"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.code }"
          :disabled="isEditing"
          placeholder="snake_case"
          maxlength="64"
        />
        <small class="cf-dialog__hint">{{ t('customFields.fields.codeHint') }}</small>
        <small v-if="errors.code" class="p-error d-block">
          {{ t('customFields.errors.codeFormat') }}
        </small>
      </div>

      <!-- Entity scope -->
      <div class="col-12">
        <label class="cf-dialog__label">{{ t('customFields.fields.scope') }} <span class="cf-dialog__req">*</span></label>
        <Select
          v-model="form.entity_scope"
          :options="scopeOptions"
          option-label="label"
          option-value="value"
          :disabled="isEditing"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.entity_scope }"
        />
        <small v-if="errors.entity_scope" class="p-error">{{ t('customFields.errors.required') }}</small>
      </div>

      <!-- Field type -->
      <div class="col-12">
        <label class="cf-dialog__label">{{ t('customFields.fields.kind') }} <span class="cf-dialog__req">*</span></label>
        <Select
          v-model="form.field_type"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.field_type }"
        />
        <small v-if="errors.field_type" class="p-error">{{ t('customFields.errors.required') }}</small>
      </div>

      <!-- Options (conditional: select / multiselect) -->
      <div v-if="needsOptions" class="col-12">
        <label class="cf-dialog__label">{{ t('customFields.fields.options') }} <span class="cf-dialog__req">*</span></label>
        <InputChips
          v-model="form.options"
          :add-on-blur="true"
          separator=","
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.options }"
          :placeholder="t('customFields.fields.optionsHint')"
        />
        <small v-if="errors.options" class="p-error">{{ t('customFields.errors.required') }}</small>
      </div>

      <!-- Sort order -->
      <div class="col-12">
        <label class="cf-dialog__label">{{ t('customFields.fields.sortOrder') }}</label>
        <InputNumber
          v-model="form.sort_order"
          :min="0"
          class="w-100 mt-1"
        />
      </div>

      <!-- Toggles -->
      <div class="col-12 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_required" />
        <label class="mb-0 cf-dialog__label">{{ t('customFields.fields.isRequired') }}</label>
      </div>
      <div class="col-12 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_active" />
        <label class="mb-0 cf-dialog__label">{{ t('customFields.fields.isActive') }}</label>
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        text
        @click="cancel"
      />
      <Button
        :label="t('common.save')"
        :loading="loading"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import InputChips from 'primevue/inputchips'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import type { CustomFieldDef, CustomFieldScope } from '@/entities/crm'
import type { CustomFieldFormPayload } from '../composables/useCustomFieldsPage'

const CODE_RE = /^[a-z][a-z0-9_]*$/

const props = defineProps<{
  modelValue: boolean
  editing: CustomFieldDef | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: CustomFieldFormPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const isEditing = computed(() => props.editing !== null)

const needsOptions = computed(() =>
  form.value.field_type === 'select' || form.value.field_type === 'multiselect',
)

// ─── Options ──────────────────────────────────────────────────────────────────

const scopeOptions = computed(() => [
  { label: t('customFields.scopes.deal'),     value: 'deal' as CustomFieldScope },
  { label: t('customFields.scopes.contact'),  value: 'contact' as CustomFieldScope },
  { label: t('customFields.scopes.company'),  value: 'company' as CustomFieldScope },
  { label: t('customFields.scopes.contract'), value: 'contract' as CustomFieldScope },
])

const kindOptions = computed(() => [
  { label: t('customFields.kinds.text'),        value: 'text' },
  { label: t('customFields.kinds.textarea'),    value: 'textarea' },
  { label: t('customFields.kinds.number'),      value: 'number' },
  { label: t('customFields.kinds.date'),        value: 'date' },
  { label: t('customFields.kinds.select'),      value: 'select' },
  { label: t('customFields.kinds.multiselect'), value: 'multiselect' },
  { label: t('customFields.kinds.url'),         value: 'url' },
  { label: t('customFields.kinds.checkbox'),    value: 'boolean' },
  { label: t('customFields.kinds.user_ref'),    value: 'user_ref' },
])

// ─── Form ─────────────────────────────────────────────────────────────────────

function emptyForm(): CustomFieldFormPayload {
  return {
    label: '',
    code: '',
    entity_scope: 'deal' as CustomFieldScope,
    field_type: 'text',
    options: [],
    help_text: null,
    sort_order: 0,
    is_required: false,
    is_active: true,
  }
}

const form = ref<CustomFieldFormPayload>(emptyForm())

const errors = ref({
  label: false,
  code: false,
  entity_scope: false,
  field_type: false,
  options: false,
})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      errors.value = { label: false, code: false, entity_scope: false, field_type: false, options: false }
      if (props.editing) {
        form.value = {
          label: props.editing.label,
          code: props.editing.code,
          entity_scope: props.editing.entity_scope,
          field_type: props.editing.field_type,
          options: props.editing.options ? [...props.editing.options] : [],
          help_text: props.editing.help_text,
          sort_order: props.editing.sort_order,
          is_required: props.editing.required,
          is_active: props.editing.is_active,
        }
      } else {
        form.value = emptyForm()
      }
    }
  },
)

function cancel() {
  emit('update:modelValue', false)
}

function submit() {
  const f = form.value
  const needsOpts = f.field_type === 'select' || f.field_type === 'multiselect'
  errors.value.label = !f.label.trim()
  errors.value.code = !isEditing.value && (!f.code.trim() || !CODE_RE.test(f.code))
  errors.value.entity_scope = !f.entity_scope
  errors.value.field_type = !f.field_type
  errors.value.options = needsOpts && f.options.length === 0

  if (Object.values(errors.value).some(Boolean)) return

  emit('save', { ...f, label: f.label.trim() })
}
</script>

<style lang="scss" scoped>
.cf-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__req {
    color: var(--p-red-500);
    margin-left: $space-1;
  }

  &__hint {
    display: block;
    margin-top: $space-1;
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
  }
}
</style>
