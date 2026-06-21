<template>
  <Dialog
    v-model:visible="visible"
    :header="t('admin.users.addUser')"
    modal
    :style="{ width: '34rem' }"
    :draggable="false"
  >
    <div class="row g-3">
      <!-- Full name (required) -->
      <div class="col-12">
        <label class="user-dialog__label">{{ t('admin.users.fields.full_name') }} *</label>
        <InputText
          v-model="form.full_name"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.full_name }"
          autofocus
          :placeholder="t('admin.users.fields.full_name_ph')"
        />
        <small v-if="errors.full_name" class="p-error">{{ errors.full_name }}</small>
      </div>

      <!-- Email (required) -->
      <div class="col-12">
        <label class="user-dialog__label">{{ t('admin.users.fields.email') }} *</label>
        <InputText
          v-model="form.email"
          type="email"
          class="w-100 mt-1"
          :class="{ 'p-invalid': errors.email }"
          :placeholder="t('admin.users.fields.email_ph')"
        />
        <small v-if="errors.email" class="p-error">{{ errors.email }}</small>
      </div>

      <!-- Phone -->
      <div class="col-md-6">
        <label class="user-dialog__label">{{ t('admin.users.fields.phone') }}</label>
        <InputText
          v-model="form.phone"
          class="w-100 mt-1"
          :placeholder="t('admin.users.fields.phone_ph')"
        />
      </div>

      <!-- Job title -->
      <div class="col-md-6">
        <label class="user-dialog__label">{{ t('admin.users.fields.job_title') }}</label>
        <InputText
          v-model="form.job_title"
          class="w-100 mt-1"
          :placeholder="t('admin.users.fields.job_title_ph')"
        />
      </div>

      <!-- Department -->
      <div class="col-md-6">
        <label class="user-dialog__label">{{ t('admin.users.fields.department') }}</label>
        <Select
          v-model="form.department_id"
          :options="departments"
          option-label="name"
          option-value="id"
          show-clear
          :placeholder="t('admin.users.fields.department_ph')"
          :loading="departmentsLoading"
          class="w-100 mt-1"
        />
      </div>

      <!-- Role -->
      <div class="col-md-6">
        <label class="user-dialog__label">{{ t('admin.users.fields.role') }}</label>
        <Select
          v-model="form.role"
          :options="roleOptions"
          option-label="label"
          option-value="value"
          show-clear
          :placeholder="t('admin.users.fields.role_ph')"
          class="w-100 mt-1"
        />
      </div>

      <!-- Password hint -->
      <div class="col-12">
        <Message severity="secondary" :closable="false" class="user-dialog__hint">
          <i class="pi pi-info-circle me-1" />
          {{ t('admin.users.passwordHint') }}
        </Message>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="cancel" />
      <Button
        :label="t('common.create')"
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
import Select from 'primevue/select'
import Message from 'primevue/message'
import type { DepartmentOption } from '@/entities/adminUser'
import type { UserRole } from '@/entities/user'
import type { CreateAdminUserPayload } from '@/entities/adminUser'

const props = defineProps<{
  modelValue: boolean
  loading: boolean
  departments: DepartmentOption[]
  departmentsLoading: boolean
  roleOptions: Array<{ label: string; value: UserRole }>
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  create: [payload: CreateAdminUserPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

interface FormState {
  full_name: string
  email: string
  phone: string
  job_title: string
  department_id: number | null
  role: UserRole | null
}

const defaultForm = (): FormState => ({
  full_name: '',
  email: '',
  phone: '',
  job_title: '',
  department_id: null,
  role: null,
})

const form = ref<FormState>(defaultForm())
const errors = ref<Partial<Record<keyof FormState, string>>>({})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      form.value = defaultForm()
      errors.value = {}
    }
  },
)

function validate(): boolean {
  errors.value = {}
  if (!form.value.full_name.trim()) {
    errors.value.full_name = t('common.required')
  }
  if (!form.value.email.trim()) {
    errors.value.email = t('common.required')
  } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.value.email.trim())) {
    errors.value.email = t('admin.users.errors.invalidEmail')
  }
  return Object.keys(errors.value).length === 0
}

function cancel() {
  visible.value = false
}

function submit() {
  if (!validate()) return

  const payload: CreateAdminUserPayload = {
    full_name: form.value.full_name.trim(),
    email: form.value.email.trim(),
    phone: form.value.phone.trim() || null,
    job_title: form.value.job_title.trim() || null,
    department_id: form.value.department_id,
    role: form.value.role,
  }
  emit('create', payload)
  // Dialog is closed by parent composable's onSuccess. Do NOT close here.
}
</script>

<style lang="scss" scoped>
.user-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__hint {
    font-size: $font-size-sm;
  }
}
</style>
