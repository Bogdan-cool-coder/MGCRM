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

    <!-- ── КОНТАКТНЫЕ ДАННЫЕ ─────────────────────────────────────────────────── -->
    <div class="company-create-form__section">
      <h3 class="company-create-form__section-title">{{ t('company.create.sections.contacts') }}</h3>
      <div class="company-create-form__field">
        <label class="company-create-form__label">
          {{ t('company.page.fields.website') }} <span class="company-create-form__req">*</span>
        </label>
        <InputText
          v-model="form.website"
          :class="{ 'p-invalid': errors.website }"
          placeholder="https://..."
          :disabled="saving"
          class="w-full"
          @blur="onWebsiteBlur"
        />
        <small v-if="errors.website" class="p-error">{{ errors.website }}</small>
      </div>
      <div class="company-create-form__field">
        <label class="company-create-form__label">
          {{ t('company.page.fields.address') }} <span class="company-create-form__req">*</span>
        </label>
        <Textarea
          v-model="form.address"
          :class="{ 'p-invalid': errors.address }"
          :placeholder="t('company.page.fields.address')"
          :disabled="saving"
          auto-resize
          rows="2"
          class="w-full"
          @blur="onAddressBlur"
        />
        <small v-if="errors.address" class="p-error">{{ errors.address }}</small>
      </div>
      <div class="company-create-form__field">
        <label class="company-create-form__label">
          {{ t('company.page.fields.phone') }} <span class="company-create-form__req">*</span>
        </label>
        <InputText
          v-model="form.phone"
          :class="{ 'p-invalid': errors.phone }"
          placeholder="+7 ..."
          :disabled="saving"
          class="w-full"
          @blur="onPhoneBlur"
        />
        <small v-if="errors.phone" class="p-error">{{ errors.phone }}</small>
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
        <label class="company-create-form__label">
          {{ t('company.page.fields.companyType') }} <span class="company-create-form__req">*</span>
        </label>
        <Select
          v-model="form.company_type_id"
          :options="directoriesStore.activeCompanyTypes"
          option-label="name"
          option-value="id"
          :class="{ 'p-invalid': errors.company_type_id }"
          :placeholder="t('contacts.page.filters.companyType')"
          :disabled="saving"
          class="w-full"
          @change="onCompanyTypeChange"
        />
        <small v-if="errors.company_type_id" class="p-error">{{ errors.company_type_id }}</small>
      </div>
      <div class="company-create-form__field">
        <label class="company-create-form__label">
          {{ t('company.page.fields.country') }} <span class="company-create-form__req">*</span>
        </label>
        <Select
          v-model="form.country_code"
          :options="directoriesStore.activeCountries"
          option-label="name"
          option-value="code"
          :class="{ 'p-invalid': errors.country_code }"
          :placeholder="t('contacts.page.filters.country')"
          :disabled="saving"
          class="w-full"
          @change="onCountryChange"
        />
        <small v-if="errors.country_code" class="p-error">{{ errors.country_code }}</small>
      </div>
      <div class="company-create-form__field">
        <label class="company-create-form__label">
          {{ t('company.page.fields.source') }} <span class="company-create-form__req">*</span>
        </label>
        <Select
          v-model="form.source"
          :options="directoriesStore.activeSources"
          option-label="name"
          option-value="code"
          :class="{ 'p-invalid': errors.source }"
          :placeholder="t('contacts.page.filters.source')"
          :disabled="saving"
          class="w-full"
          @change="onSourceChange"
        />
        <small v-if="errors.source" class="p-error">{{ errors.source }}</small>
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
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import Button from 'primevue/button'
import { companiesApi } from '@/api/crm/companies'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorStatus, getValidationErrors, getApiErrorMessage } from '@/utils/errors'
import { useDirectoriesStore } from '@/stores/directories'
import type { Company } from '@/entities/crm'

const emit = defineEmits<{
  saved: [company: Company]
  cancel: []
}>()

const { t } = useI18n()
const toast = useToast()
const directoriesStore = useDirectoriesStore()

interface CompanyCreateForm {
  name: string
  website: string
  address: string
  phone: string
  legal_form: string
  tax_id: string
  company_type_id: number | null
  country_code: string | null
  source: string | null
}

const form = ref<CompanyCreateForm>({
  name: '',
  website: '',
  address: '',
  phone: '',
  legal_form: '',
  tax_id: '',
  company_type_id: null,
  country_code: null,
  source: null,
})

const errors = ref<Record<string, string>>({})

/** Simple http(s) URL check — mirrors the backend `url` rule (§E.2). */
function isValidUrl(value: string): boolean {
  try {
    const url = new URL(value.trim())
    return url.protocol === 'http:' || url.protocol === 'https:'
  } catch {
    return false
  }
}

/** Drops a single field from the error map (used by blur guards on success). */
function clearError(field: string) {
  if (!(field in errors.value)) return
  const next = { ...errors.value }
  delete next[field]
  errors.value = next
}

function setError(field: string, message: string) {
  errors.value = { ...errors.value, [field]: message }
}

function onNameBlur() {
  if (!form.value.name.trim()) setError('name', t('company.create.errors.nameRequired'))
  else clearError('name')
}

function onWebsiteBlur() {
  const value = form.value.website.trim()
  if (!value) setError('website', t('company.create.errors.websiteRequired'))
  else if (!isValidUrl(value)) setError('website', t('company.create.errors.websiteInvalid'))
  else clearError('website')
}

function onAddressBlur() {
  if (!form.value.address.trim()) setError('address', t('company.create.errors.addressRequired'))
  else clearError('address')
}

function onPhoneBlur() {
  if (!form.value.phone.trim()) setError('phone', t('company.create.errors.phoneRequired'))
  else clearError('phone')
}

function onCompanyTypeChange() {
  if (form.value.company_type_id == null) {
    setError('company_type_id', t('company.create.errors.companyTypeRequired'))
  } else clearError('company_type_id')
}

function onCountryChange() {
  if (!form.value.country_code) setError('country_code', t('company.create.errors.countryRequired'))
  else clearError('country_code')
}

function onSourceChange() {
  if (!form.value.source) setError('source', t('company.create.errors.sourceRequired'))
  else clearError('source')
}

const mutation = useMutation<Company>()
const saving = computed(() => mutation.isPending.value)

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.name.trim()) errs.name = t('company.create.errors.nameRequired')

  const website = form.value.website.trim()
  if (!website) errs.website = t('company.create.errors.websiteRequired')
  else if (!isValidUrl(website)) errs.website = t('company.create.errors.websiteInvalid')

  if (!form.value.address.trim()) errs.address = t('company.create.errors.addressRequired')
  if (!form.value.phone.trim()) errs.phone = t('company.create.errors.phoneRequired')

  if (form.value.company_type_id == null) {
    errs.company_type_id = t('company.create.errors.companyTypeRequired')
  }
  if (!form.value.country_code) errs.country_code = t('company.create.errors.countryRequired')
  if (!form.value.source) errs.source = t('company.create.errors.sourceRequired')

  errors.value = errs
  return Object.keys(errs).length === 0
}

async function onSubmit() {
  if (!validate()) return

  try {
    const created = await mutation.run(() =>
      companiesApi.create({
        name: form.value.name.trim(),
        website: form.value.website.trim(),
        address: form.value.address.trim(),
        phone: form.value.phone.trim(),
        legal_form: form.value.legal_form || undefined,
        tax_id: form.value.tax_id || undefined,
        company_type_id: form.value.company_type_id ?? undefined,
        country_code: form.value.country_code ?? undefined,
        source: form.value.source ?? undefined,
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
