<template>
  <div class="contact-create-form">
    <!-- ── ОБЯЗАТЕЛЬНЫЕ ПОЛЯ ─────────────────────────────────────────────────── -->
    <div class="contact-create-form__section">
      <h3 class="contact-create-form__section-title">{{ t('contact.create.sections.required') }}</h3>
      <div class="contact-create-form__field">
        <label class="contact-create-form__label">
          {{ t('contact.page.fields.fullName') }} <span class="contact-create-form__req">*</span>
        </label>
        <InputText
          ref="fullNameRef"
          v-model="form.full_name"
          :class="{ 'p-invalid': errors.full_name }"
          :placeholder="t('contact.page.fields.fullName')"
          :disabled="saving"
          class="w-full"
          @blur="onFullNameBlur"
        />
        <small v-if="errors.full_name" class="p-error">{{ errors.full_name }}</small>
      </div>
    </div>

    <!-- ── КОНТАКТЫ ─────────────────────────────────────────────────────────── -->
    <div class="contact-create-form__section">
      <h3 class="contact-create-form__section-title">{{ t('contact.create.sections.contacts') }}</h3>
      <div class="contact-create-form__field">
        <label class="contact-create-form__label">{{ t('contact.page.fields.phone') }}</label>
        <InputText
          v-model="form.phone"
          placeholder="+7 777 000 00 00"
          :disabled="saving"
          class="w-full"
        />
      </div>
      <div class="contact-create-form__field">
        <label class="contact-create-form__label">{{ t('contact.page.fields.email') }}</label>
        <InputText
          v-model="form.email"
          placeholder="email@example.com"
          :disabled="saving"
          class="w-full"
        />
      </div>
    </div>

    <!-- ── ДОПОЛНИТЕЛЬНО ────────────────────────────────────────────────────── -->
    <div class="contact-create-form__section">
      <h3 class="contact-create-form__section-title">{{ t('contact.create.sections.additional') }}</h3>
      <div class="contact-create-form__field">
        <label class="contact-create-form__label">{{ t('contact.page.fields.position') }}</label>
        <InputText
          v-model="form.position"
          :placeholder="t('contact.page.fields.position')"
          :disabled="saving"
          class="w-full"
        />
      </div>
      <div class="contact-create-form__field">
        <label class="contact-create-form__label">{{ t('contact.page.fields.source') }}</label>
        <Select
          v-model="form.source"
          :options="directoriesStore.activeSources"
          option-label="name"
          option-value="code"
          :placeholder="t('contacts.page.filters.source')"
          show-clear
          :disabled="saving"
          class="w-full"
        />
      </div>
    </div>

    <!-- ── ACTION BAR ────────────────────────────────────────────────────────── -->
    <div class="contact-create-form__actions">
      <Button
        :label="t('contact.create.cancelBtn')"
        severity="secondary"
        text
        :disabled="saving"
        @click="emit('cancel')"
      />
      <Button
        icon="pi pi-check"
        :label="saving ? t('contact.create.saving') : t('contact.create.saveBtn')"
        :loading="saving"
        :disabled="saving"
        @click="onSubmit"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { contactsApi } from '@/api/crm/contacts'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorStatus, getValidationErrors, getApiErrorMessage } from '@/utils/errors'
import { useDirectoriesStore } from '@/stores/directories'
import type { Contact } from '@/entities/crm'

const emit = defineEmits<{
  saved: [contact: Contact]
  cancel: []
}>()

const { t } = useI18n()
const toast = useToast()
const directoriesStore = useDirectoriesStore()

const fullNameRef = ref<InstanceType<typeof InputText> | null>(null)

interface ContactCreateForm {
  full_name: string
  phone: string
  email: string
  position: string
  source: string | null
}

const form = ref<ContactCreateForm>({
  full_name: '',
  phone: '',
  email: '',
  position: '',
  source: null,
})

const errors = ref<Record<string, string>>({})

const mutation = useMutation<Contact>()
const saving = computed(() => mutation.isPending.value)

function onFullNameBlur() {
  if (!form.value.full_name.trim()) {
    errors.value = { ...errors.value, full_name: t('contact.create.errors.fullNameRequired') }
  } else {
    const { full_name: _fn, ...rest } = errors.value
    void _fn
    errors.value = rest
  }
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.full_name.trim()) {
    errs.full_name = t('contact.create.errors.fullNameRequired')
  }
  errors.value = errs
  return Object.keys(errs).length === 0
}

async function onSubmit() {
  if (!validate()) return

  try {
    const created = await mutation.run(() =>
      contactsApi.create({
        full_name: form.value.full_name.trim(),
        phone: form.value.phone || undefined,
        email: form.value.email || undefined,
        position: form.value.position || undefined,
        source: form.value.source ?? undefined,
      }),
    )
    toast.add({
      severity: 'success',
      summary: t('contact.create.success'),
      life: 3000,
    })
    emit('saved', created)
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve) {
        errors.value = Object.fromEntries(
          Object.entries(ve).map(([k, v]) => [k, Array.isArray(v) ? (v[0] ?? '') : v]),
        ) as Record<string, string>
        return
      }
    }
    toast.add({
      severity: 'error',
      summary: t('contact.create.errors.serverError'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

onMounted(() => {
  if (!directoriesStore.loaded) {
    void directoriesStore.fetchAll()
  }
})
</script>

<style lang="scss" scoped>
.contact-create-form {
  display: flex;
  flex-direction: column;
  gap: $space-6;
  padding: $space-6;
  max-width: 640px;
}

.contact-create-form__section {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-card;
  padding: $space-5;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.contact-create-form__section-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0 0 $space-1;
  text-transform: uppercase;
  letter-spacing: 0.04em;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.contact-create-form__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.contact-create-form__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.contact-create-form__req {
  color: $red-500;
}

.contact-create-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-2;
}

.w-full {
  width: 100%;
}
</style>
