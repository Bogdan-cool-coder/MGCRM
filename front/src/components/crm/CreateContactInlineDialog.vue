<template>
  <Dialog
    v-model:visible="visible"
    :header="t('contacts.inline_create.title')"
    modal
    style="width: 520px"
    :closable="!saving"
    @hide="onHide"
  >
    <div class="create-contact-inline">
      <!-- Full name -->
      <div class="create-contact-inline__field">
        <label class="create-contact-inline__label">
          {{ t('contacts.inline_create.fields.full_name') }}
          <span class="create-contact-inline__req">*</span>
        </label>
        <InputText
          v-model.trim="form.full_name"
          class="w-full"
          :class="{ 'p-invalid': errors.full_name }"
          :placeholder="t('contacts.inline_create.placeholders.full_name')"
          :disabled="saving"
        />
        <small v-if="errors.full_name" class="p-error">{{ errors.full_name }}</small>
      </div>

      <!-- Phone -->
      <div class="create-contact-inline__field">
        <label class="create-contact-inline__label">
          {{ t('contacts.inline_create.fields.phone') }}
          <span class="create-contact-inline__req">*</span>
        </label>
        <InputText
          v-model.trim="form.phone"
          class="w-full"
          :class="{ 'p-invalid': errors.phone }"
          :placeholder="t('contacts.inline_create.placeholders.phone')"
          :disabled="saving"
        />
        <small v-if="errors.phone" class="p-error">{{ errors.phone }}</small>
      </div>

      <!-- Email -->
      <div class="create-contact-inline__field">
        <label class="create-contact-inline__label">
          {{ t('contacts.inline_create.fields.email') }}
        </label>
        <InputText
          v-model.trim="form.email"
          class="w-full"
          :class="{ 'p-invalid': errors.email }"
          :placeholder="t('contacts.inline_create.placeholders.email')"
          type="email"
          :disabled="saving"
        />
        <small v-if="errors.email" class="p-error">{{ errors.email }}</small>
      </div>

      <!-- Position -->
      <div class="create-contact-inline__field">
        <label class="create-contact-inline__label">
          {{ t('contacts.inline_create.fields.position') }}
          <span class="create-contact-inline__req">*</span>
        </label>
        <InputText
          v-model.trim="form.position"
          class="w-full"
          :class="{ 'p-invalid': errors.position }"
          :placeholder="t('contacts.inline_create.placeholders.position')"
          :disabled="saving"
        />
        <small v-if="errors.position" class="p-error">{{ errors.position }}</small>
      </div>

      <!-- Notes -->
      <div class="create-contact-inline__field">
        <label class="create-contact-inline__label">
          {{ t('contacts.inline_create.fields.notes') }}
        </label>
        <Textarea
          v-model="form.notes"
          class="w-full"
          :placeholder="t('contacts.inline_create.placeholders.notes')"
          :disabled="saving"
          rows="3"
          auto-resize
        />
      </div>

      <!-- Is primary (optional — only shown when showIsPrimary prop is true) -->
      <div v-if="showIsPrimary" class="create-contact-inline__field create-contact-inline__field--row">
        <label class="create-contact-inline__label">
          {{ t('contacts.inline_create.fields.is_primary') }}
        </label>
        <ToggleSwitch v-model="form.is_primary" :disabled="saving" />
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('contacts.inline_create.cancel')"
        severity="secondary"
        text
        :disabled="saving"
        @click="visible = false"
      />
      <Button
        icon="pi pi-user-plus"
        :label="t('contacts.inline_create.submit')"
        :loading="saving"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import ToggleSwitch from 'primevue/toggleswitch'
import Button from 'primevue/button'
import { useMutation } from '@/composables/async/useMutation'
import { contactsApi } from '@/api/crm/contacts'
import { getApiErrorMessage, getApiErrorStatus, getValidationErrors } from '@/utils/errors'
import type { Contact } from '@/entities/crm'

interface Form {
  full_name: string
  phone: string
  email: string
  position: string
  notes: string
  is_primary: boolean
}

const props = withDefaults(
  defineProps<{
    modelValue: boolean
    /** Pre-fill full_name from the autocomplete search query */
    initialName?: string
    /** Whether to show the "is primary contact" toggle */
    showIsPrimary?: boolean
  }>(),
  {
    initialName: '',
    showIsPrimary: true,
  },
)

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  /** Emitted after successful contact creation; parent must handle the attachment */
  created: [contact: Contact, position: string, isPrimary: boolean]
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

function emptyForm(): Form {
  return {
    full_name: props.initialName,
    phone: '',
    email: '',
    position: '',
    notes: '',
    is_primary: false,
  }
}

const form = ref<Form>(emptyForm())
const errors = ref<Partial<Record<keyof Form, string>>>({})

const mutation = useMutation<Contact>()
const saving = computed(() => mutation.isPending.value)

// Pre-fill full_name when the dialog opens
watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      form.value = emptyForm()
      errors.value = {}
    }
  },
)

function validate(): boolean {
  const e: Partial<Record<keyof Form, string>> = {}

  if (!form.value.full_name) {
    e.full_name = t('contacts.inline_create.validation.full_name_required')
  }
  if (!form.value.phone) {
    e.phone = t('contacts.inline_create.validation.phone_required')
  }
  if (!form.value.position) {
    e.position = t('contacts.inline_create.validation.position_required')
  }

  errors.value = e
  return Object.keys(e).length === 0
}

async function onSubmit() {
  if (!validate()) return

  errors.value = {}

  try {
    const contact = await mutation.run(() =>
      contactsApi.create({
        full_name: form.value.full_name,
        phone: form.value.phone || undefined,
        email: form.value.email || undefined,
        position: form.value.position || undefined,
        notes: form.value.notes || undefined,
      }),
    )

    toast.add({
      severity: 'success',
      summary: t('contacts.inline_create.success'),
      life: 3000,
    })

    emit('created', contact, form.value.position, form.value.is_primary)
    visible.value = false
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve) {
        const mapped: Partial<Record<keyof Form, string>> = {}
        if (ve.full_name) mapped.full_name = ve.full_name
        if (ve.phone) mapped.phone = ve.phone
        if (ve.email) mapped.email = ve.email
        if (ve.position) mapped.position = ve.position
        errors.value = mapped
        return
      }
    }
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

function onHide() {
  errors.value = {}
}
</script>

<style lang="scss" scoped>
.create-contact-inline {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;
}

.create-contact-inline__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;

  &--row {
    flex-direction: row;
    align-items: center;
    justify-content: space-between;
  }
}

.create-contact-inline__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.create-contact-inline__req {
  color: var(--p-red-500, #ff5a44);
  margin-left: 2px;
}

.w-full {
  width: 100%;
}
</style>
