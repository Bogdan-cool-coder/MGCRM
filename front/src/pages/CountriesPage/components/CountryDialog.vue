<template>
  <Dialog
    v-model:visible="visible"
    :header="editing ? t('admin.countries.edit') : t('admin.countries.add')"
    modal
    :style="{ width: '30rem' }"
    :draggable="false"
  >
    <div class="row g-3">
      <!-- Code (editable on create, read-only on edit) -->
      <div class="col-6">
        <label class="dir-dialog__label">{{ t('admin.countries.fields.code') }}</label>
        <InputText
          v-if="!editing"
          v-model="form.code"
          class="w-100 mt-1"
          :class="{ 'p-invalid': codeError }"
          maxlength="2"
          autofocus
          style="text-transform: uppercase"
          @blur="form.code = form.code.toLowerCase()"
        />
        <InputText
          v-else
          :model-value="editing.code"
          class="w-100 mt-1"
          disabled
        />
        <small v-if="codeError" class="p-error">{{ t('admin.countries.errors.codeRequired') }}</small>
      </div>

      <!-- Phone prefix -->
      <div class="col-6">
        <label class="dir-dialog__label">{{ t('admin.countries.fields.phonePrefix') }}</label>
        <InputText v-model="form.phone_prefix" class="w-100 mt-1" placeholder="+7" />
      </div>

      <!-- Name (RU) -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.countries.fields.name') }}</label>
        <InputText
          v-model="form.name"
          class="w-100 mt-1"
          :class="{ 'p-invalid': nameError }"
          :autofocus="!!editing"
        />
        <small v-if="nameError" class="p-error">{{ t('common.required') }}</small>
      </div>

      <!-- Name (EN) -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.countries.fields.nameEn') }}</label>
        <InputText v-model="form.name_en" class="w-100 mt-1" />
      </div>

      <!-- Sort order -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.countries.fields.sortOrder') }}</label>
        <InputNumber v-model="form.sort_order" :min="0" class="w-100 mt-1" />
      </div>

      <!-- Is active -->
      <div class="col-12 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_active" />
        <label class="mb-0 dir-dialog__label">{{ t('admin.countries.fields.isActive') }}</label>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="cancel" />
      <Button :label="t('common.save')" :loading="loading" @click="submit" />
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
import ToggleSwitch from 'primevue/toggleswitch'
import type { Country } from '@/entities/crm'
import type { CountryFormPayload } from '../composables/useCountriesPage'

const props = defineProps<{
  modelValue: boolean
  editing: Country | null
  loading: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: CountryFormPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v: boolean) => emit('update:modelValue', v),
})

const form = ref<CountryFormPayload>({
  code: '',
  name: '',
  name_en: '',
  phone_prefix: '',
  sort_order: 0,
  is_active: true,
})

const nameError = ref(false)
const codeError = ref(false)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      nameError.value = false
      codeError.value = false
      if (props.editing) {
        form.value = {
          code: props.editing.code,
          name: props.editing.name,
          name_en: props.editing.name_en ?? '',
          phone_prefix: props.editing.phone_prefix ?? '',
          sort_order: props.editing.sort_order,
          is_active: props.editing.is_active,
        }
      } else {
        form.value = { code: '', name: '', name_en: '', phone_prefix: '', sort_order: 0, is_active: true }
      }
    }
  },
)

function cancel() {
  emit('update:modelValue', false)
}

function submit() {
  nameError.value = !form.value.name.trim()
  codeError.value = !props.editing && !form.value.code.trim()
  if (nameError.value || codeError.value) return
  emit('save', { ...form.value })
}
</script>

<style lang="scss" scoped>
.dir-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
