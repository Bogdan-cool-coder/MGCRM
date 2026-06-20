<template>
  <Dialog
    v-model:visible="visible"
    :header="isEdit ? t('crm.company.requisites.edit') : t('crm.company.requisites.add')"
    modal
    style="width: 600px; max-width: 95vw"
    @hide="onHide"
  >
    <form class="requisite-form" @submit.prevent="onSubmit">
      <div class="row g-3">
        <!-- Label -->
        <div class="col-12">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.label') }}</label>
          <InputText
            v-model="form.label"
            class="w-full"
            :placeholder="t('crm.company.requisites.fields.labelPlaceholder')"
          />
        </div>

        <!-- Legal name (required) -->
        <div class="col-md-8">
          <label class="requisite-form__label requisite-form__label--required">
            {{ t('crm.company.requisites.fields.legalName') }}
          </label>
          <InputText
            v-model="form.legal_name"
            class="w-full"
            :class="{ 'p-invalid': errors.legal_name }"
            :placeholder="t('crm.company.requisites.fields.legalNamePlaceholder')"
          />
          <small v-if="errors.legal_name" class="p-error">{{ errors.legal_name }}</small>
        </div>

        <!-- Full legal form -->
        <div class="col-md-4">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.fullLegalForm') }}</label>
          <InputText
            v-model="form.full_legal_form"
            class="w-full"
            placeholder="ТОО / ООО / АО"
          />
        </div>

        <!-- Tax ID label + Tax ID -->
        <div class="col-md-4">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.taxIdLabel') }}</label>
          <InputText
            v-model="form.tax_id_label"
            class="w-full"
            placeholder="БИН / ИНН / TIN"
          />
        </div>
        <div class="col-md-8">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.taxId') }}</label>
          <InputText
            v-model="form.tax_id"
            class="w-full"
            :class="{ 'p-invalid': errors.tax_id }"
            :placeholder="t('crm.company.requisites.fields.taxIdPlaceholder')"
          />
          <small v-if="errors.tax_id" class="p-error">{{ errors.tax_id }}</small>
        </div>

        <!-- Country -->
        <div class="col-md-4">
          <label class="requisite-form__label">{{ t('company.page.fields.country') }}</label>
          <Select
            v-model="form.country_code"
            :options="countryOptions"
            option-label="name"
            option-value="code"
            :placeholder="t('common.select')"
            class="w-full"
            filter
            show-clear
          />
        </div>

        <!-- Director -->
        <div class="col-md-8">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.director') }}</label>
          <InputText
            v-model="form.director"
            class="w-full"
            :placeholder="t('crm.company.requisites.fields.directorPlaceholder')"
          />
        </div>

        <!-- Director genitive -->
        <div class="col-12">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.directorGenitive') }}</label>
          <InputText
            v-model="form.director_genitive"
            class="w-full"
            :placeholder="t('crm.company.requisites.fields.directorGenitivePlaceholder')"
          />
        </div>

        <!-- Address -->
        <div class="col-12">
          <label class="requisite-form__label">{{ t('company.page.fields.address') }}</label>
          <Textarea
            v-model="form.address"
            class="w-full"
            rows="2"
            auto-resize
          />
        </div>

        <!-- Bank section divider -->
        <div class="col-12">
          <div class="requisite-form__section-divider">
            <span>{{ t('company.requisites.section.bank') }}</span>
          </div>
        </div>

        <!-- Bank name -->
        <div class="col-12">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.bank') }}</label>
          <InputText v-model="form.bank" class="w-full" />
        </div>

        <!-- Account + BIK -->
        <div class="col-md-8">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.account') }}</label>
          <InputText v-model="form.account" class="w-full" />
        </div>
        <div class="col-md-4">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.bik') }}</label>
          <InputText v-model="form.bik" class="w-full" />
        </div>

        <!-- Valid from + note -->
        <div class="col-md-6">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.validFrom') }}</label>
          <DatePicker
            v-model="form.valid_from_date"
            class="w-full"
            date-format="dd.mm.yy"
            show-button-bar
          />
        </div>
        <div class="col-md-6">
          <!-- Set as current (only on create) -->
          <div v-if="!isEdit" class="requisite-form__toggle-row">
            <label class="requisite-form__label">{{ t('crm.company.requisites.setAsCurrentOnCreate') }}</label>
            <ToggleSwitch v-model="form.set_as_current" />
          </div>
        </div>

        <!-- Note -->
        <div class="col-12">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.note') }}</label>
          <Textarea
            v-model="form.note"
            class="w-full"
            rows="2"
            auto-resize
            :placeholder="t('crm.company.requisites.fields.notePlaceholder')"
          />
        </div>
      </div>
    </form>

    <template #footer>
      <Button
        :label="t('common.cancel')"
        severity="secondary"
        text
        @click="visible = false"
      />
      <Button
        :label="t('common.save')"
        :loading="saving"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Button from 'primevue/button'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import ToggleSwitch from 'primevue/toggleswitch'
import { useDirectoriesStore } from '@/stores/directories'
import type { CompanyRequisite, CreateRequisitePayload } from '@/entities/crm'

const props = defineProps<{
  modelValue: boolean
  requisite?: CompanyRequisite | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [payload: CreateRequisitePayload, id?: number]
}>()

const { t } = useI18n()
const directoriesStore = useDirectoriesStore()

const saving = ref(false)

const isEdit = computed(() => !!props.requisite)

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

// ── Form state ─────────────────────────────────────────────────────────────────

interface FormState {
  label: string
  legal_name: string
  full_legal_form: string
  tax_id_label: string
  tax_id: string
  country_code: string | null
  director: string
  director_genitive: string
  address: string
  bank: string
  account: string
  bik: string
  valid_from_date: Date | null
  note: string
  set_as_current: boolean
}

function emptyForm(): FormState {
  return {
    label: '',
    legal_name: '',
    full_legal_form: '',
    tax_id_label: '',
    tax_id: '',
    country_code: null,
    director: '',
    director_genitive: '',
    address: '',
    bank: '',
    account: '',
    bik: '',
    valid_from_date: null,
    note: '',
    set_as_current: false,
  }
}

const form = ref<FormState>(emptyForm())
const errors = ref<{ legal_name?: string; tax_id?: string }>({})

// ── Country options ────────────────────────────────────────────────────────────

const countryOptions = computed(() =>
  directoriesStore.countries.map((c) => ({ code: c.code, name: c.name })),
)

// ── Sync form from requisite prop ──────────────────────────────────────────────

watch(
  () => [props.modelValue, props.requisite] as const,
  ([open, req]) => {
    if (!open) return
    if (req) {
      form.value = {
        label: req.label ?? '',
        legal_name: req.legal_name ?? '',
        full_legal_form: req.full_legal_form ?? '',
        tax_id_label: req.tax_id_label ?? '',
        tax_id: req.tax_id ?? '',
        country_code: req.country_code ?? null,
        director: req.director ?? '',
        director_genitive: req.director_genitive ?? '',
        address: req.address ?? '',
        bank: req.bank_details?.bank ?? '',
        account: req.bank_details?.account ?? '',
        bik: req.bank_details?.bik ?? '',
        valid_from_date: req.valid_from ? new Date(req.valid_from) : null,
        note: req.note ?? '',
        set_as_current: false,
      }
    } else {
      form.value = emptyForm()
    }
    errors.value = {}
  },
  { immediate: true },
)

// ── Validation ─────────────────────────────────────────────────────────────────

function validate(): boolean {
  errors.value = {}
  if (!form.value.legal_name.trim()) {
    errors.value.legal_name = t('crm.company.requisites.errors.legalNameRequired')
  }
  return Object.keys(errors.value).length === 0
}

// ── Submit ─────────────────────────────────────────────────────────────────────

function onSubmit() {
  if (!validate()) return

  const payload: CreateRequisitePayload = {
    label: form.value.label.trim() || null,
    legal_name: form.value.legal_name.trim(),
    full_legal_form: form.value.full_legal_form.trim() || null,
    tax_id_label: form.value.tax_id_label.trim() || null,
    tax_id: form.value.tax_id.trim() || null,
    country_code: form.value.country_code || null,
    director: form.value.director.trim() || null,
    director_genitive: form.value.director_genitive.trim() || null,
    address: form.value.address.trim() || null,
    bank: form.value.bank.trim() || null,
    account: form.value.account.trim() || null,
    bik: form.value.bik.trim() || null,
    valid_from: form.value.valid_from_date
      ? form.value.valid_from_date.toISOString().slice(0, 10)
      : null,
    note: form.value.note.trim() || null,
    set_as_current: form.value.set_as_current,
  }

  emit('saved', payload, props.requisite?.id)
}

function onHide() {
  errors.value = {}
}

// Expose saving control to parent
defineExpose({ setSaving: (v: boolean) => { saving.value = v } })
</script>

<style lang="scss" scoped>
.requisite-form {
  padding-top: $space-2;
}

.requisite-form__label {
  display: block;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  margin-bottom: $space-1;

  .app-dark & {
    color: var(--p-surface-200);
  }

  &--required::after {
    content: ' *';
    color: var(--p-red-500);
  }
}

.requisite-form__section-divider {
  display: flex;
  align-items: center;
  gap: $space-2;

  span {
    font-size: 10px;
    font-weight: $font-weight-bold;
    text-transform: uppercase;
    letter-spacing: 0.06em;
    color: $surface-400;
    white-space: nowrap;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }

  &::after {
    content: '';
    flex: 1;
    height: 1px;
    background: var(--p-surface-200);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }
}

.requisite-form__toggle-row {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding-top: $space-4;
}

.w-full {
  width: 100%;
}

.p-error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  margin-top: 2px;
  display: block;
}
</style>
