<template>
  <div class="company-create-form">
    <!-- ── ОБЯЗАТЕЛЬНЫЕ ПОЛЯ ─────────────────────────────────────────────────── -->
    <div class="company-create-form__section">
      <h3 class="company-create-form__section-title">{{ t('company.create.sections.required') }}</h3>
      <div class="company-create-form__field">
        <label class="company-create-form__label">
          {{ t('company.page.fields.name') }} <span class="company-create-form__req">*</span>
        </label>
        <InputText
          v-model="form.name"
          :class="{ 'p-invalid': errors.name }"
          :placeholder="t('company.page.fields.name')"
          :disabled="saving"
          class="w-full"
          @blur="onNameBlur"
        />
        <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
      </div>
    </div>

    <!-- ── РЕКВИЗИТЫ ─────────────────────────────────────────────────────────── -->
    <div class="company-create-form__section">
      <h3 class="company-create-form__section-title">{{ t('company.create.sections.requisites') }}</h3>
      <div class="company-create-form__field">
        <label class="company-create-form__label">{{ t('company.page.fields.legalForm') }}</label>
        <InputText
          v-model="form.legal_form"
          placeholder="ТОО / ООО / ИП"
          :disabled="saving"
          class="w-full"
        />
      </div>
      <div class="company-create-form__field">
        <label class="company-create-form__label">{{ t('company.page.fields.taxId') }}</label>
        <InputText
          v-model="form.tax_id"
          placeholder="БИН / ИНН"
          :disabled="saving"
          class="w-full"
        />
      </div>
    </div>

    <!-- ── КЛАССИФИКАЦИЯ ─────────────────────────────────────────────────────── -->
    <div class="company-create-form__section">
      <h3 class="company-create-form__section-title">{{ t('company.create.sections.classification') }}</h3>
      <div class="company-create-form__field">
        <label class="company-create-form__label">{{ t('company.page.fields.companyType') }}</label>
        <Select
          v-model="form.company_type_id"
          :options="directoriesStore.activeCompanyTypes"
          option-label="name"
          option-value="id"
          :placeholder="t('contacts.page.filters.companyType')"
          show-clear
          :disabled="saving"
          class="w-full"
        />
      </div>
      <div class="company-create-form__field">
        <label class="company-create-form__label">{{ t('company.page.fields.country') }}</label>
        <Select
          v-model="form.country_code"
          :options="directoriesStore.activeCountries"
          option-label="name"
          option-value="code"
          :placeholder="t('contacts.page.filters.country')"
          show-clear
          :disabled="saving"
          class="w-full"
        />
      </div>
      <div class="company-create-form__field">
        <label class="company-create-form__label">{{ t('company.page.fields.source') }}</label>
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

    <!-- ── ОТВЕТСТВЕННОСТЬ ───────────────────────────────────────────────────── -->
    <div class="company-create-form__section">
      <h3 class="company-create-form__section-title">{{ t('company.create.sections.responsibility') }}</h3>
      <div class="company-create-form__field">
        <label class="company-create-form__label">{{ t('company.page.fields.responsibleUser') }}</label>
        <Select
          v-model="form.responsible_user_id"
          :options="usersCache"
          option-label="full_name"
          option-value="id"
          :placeholder="t('common.select')"
          show-clear
          :disabled="saving"
          class="w-full"
        />
      </div>
    </div>

    <!-- ── ACTION BAR ────────────────────────────────────────────────────────── -->
    <div class="company-create-form__actions">
      <Button
        :label="t('company.create.cancelBtn')"
        severity="secondary"
        text
        :disabled="saving"
        @click="emit('cancel')"
      />
      <Button
        icon="pi pi-check"
        :label="t('company.create.saveBtn')"
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
import { companiesApi } from '@/api/crm/companies'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorStatus, getValidationErrors, getApiErrorMessage } from '@/utils/errors'
import { useDirectoriesStore } from '@/stores/directories'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import type { Company } from '@/entities/crm'

const emit = defineEmits<{
  saved: [company: Company]
  cancel: []
}>()

const { t } = useI18n()
const toast = useToast()
const directoriesStore = useDirectoriesStore()
const { users: usersCache, load: loadUsers } = useUsersCache()

interface CompanyCreateForm {
  name: string
  legal_form: string
  tax_id: string
  company_type_id: number | null
  country_code: string | null
  source: string | null
  responsible_user_id: number | null
}

const form = ref<CompanyCreateForm>({
  name: '',
  legal_form: '',
  tax_id: '',
  company_type_id: null,
  country_code: null,
  source: null,
  responsible_user_id: null,
})

const errors = ref<Record<string, string>>({})

const mutation = useMutation<Company>()
const saving = computed(() => mutation.isPending.value)

function onNameBlur() {
  if (!form.value.name.trim()) {
    errors.value = { ...errors.value, name: t('company.create.errors.nameRequired') }
  } else {
    const { name: _n, ...rest } = errors.value
    void _n
    errors.value = rest
  }
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.name.trim()) {
    errs.name = t('company.create.errors.nameRequired')
  }
  errors.value = errs
  return Object.keys(errs).length === 0
}

async function onSubmit() {
  if (!validate()) return

  try {
    const created = await mutation.run(() =>
      companiesApi.create({
        name: form.value.name.trim(),
        legal_form: form.value.legal_form || undefined,
        tax_id: form.value.tax_id || undefined,
        company_type_id: form.value.company_type_id ?? undefined,
        country_code: form.value.country_code ?? undefined,
        source: form.value.source ?? undefined,
        responsible_user_id: form.value.responsible_user_id ?? undefined,
      }),
    )
    toast.add({
      severity: 'success',
      summary: t('company.create.success'),
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
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  }
}

onMounted(() => {
  if (!directoriesStore.loaded) {
    void directoriesStore.fetchAll()
  }
  void loadUsers()
})
</script>

<style lang="scss" scoped>
.company-create-form {
  display: flex;
  flex-direction: column;
  gap: $space-6;
  padding: $space-6;
  max-width: 640px;
}

.company-create-form__section {
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

.company-create-form__section-title {
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

.company-create-form__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.company-create-form__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.company-create-form__req {
  color: $red-500;
}

.company-create-form__actions {
  display: flex;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-2;
}

.w-full {
  width: 100%;
}
</style>
