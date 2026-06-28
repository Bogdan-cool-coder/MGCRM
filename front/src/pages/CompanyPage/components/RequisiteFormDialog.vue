<template>
  <Dialog
    v-model:visible="visible"
    :header="isEdit ? t('crm.company.requisites.edit') : t('crm.company.requisites.add')"
    modal
    style="width: 660px; max-width: 95vw"
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
          <label class="requisite-form__label">
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

        <!-- Legal form short -->
        <div class="col-md-4">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.legalForm') }}</label>
          <InputText
            v-model="form.legal_form"
            class="w-full"
            placeholder="ООО / АО / ТОО"
          />
        </div>

        <!-- Full legal form -->
        <div class="col-md-8">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.fullLegalForm') }}</label>
          <InputText
            v-model="form.full_legal_form"
            class="w-full"
            placeholder="Общество с ограниченной ответственностью"
          />
        </div>

        <!-- Gender ending OE (родовое окончание — «-ого»/«-ой» for contract generation) -->
        <div class="col-md-4">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.genderEndingOe') }}</label>
          <InputText
            v-model="form.gender_ending_oe"
            class="w-full"
            placeholder="-ого / -ой"
          />
        </div>

        <!-- Director section divider -->
        <div class="col-12">
          <div class="requisite-form__section-divider">
            <span>{{ t('crm.company.requisites.section.director') }}</span>
          </div>
        </div>

        <!-- Director position -->
        <div class="col-md-6">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.directorPosition') }}</label>
          <InputText
            v-model="form.director_position"
            class="w-full"
            :placeholder="t('crm.company.requisites.fields.directorPositionPlaceholder')"
          />
        </div>

        <!-- Director short -->
        <div class="col-md-6">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.directorShort') }}</label>
          <InputText
            v-model="form.director_short"
            class="w-full"
            :placeholder="t('crm.company.requisites.fields.directorShortPlaceholder')"
          />
        </div>

        <!-- Director genitive -->
        <div class="col-md-6">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.directorGenitive') }}</label>
          <InputText
            v-model="form.director_genitive"
            class="w-full"
            :placeholder="t('crm.company.requisites.fields.directorGenitivePlaceholder')"
          />
        </div>

        <!-- Acts basis -->
        <div class="col-md-6">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.actsBasis') }}</label>
          <InputText
            v-model="form.acts_basis"
            class="w-full"
            :placeholder="t('crm.company.requisites.fields.actsBasisPlaceholder')"
          />
        </div>

        <!-- Tax section divider -->
        <div class="col-12">
          <div class="requisite-form__section-divider">
            <span>{{ t('crm.company.requisites.section.tax') }}</span>
          </div>
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

        <!-- Address -->
        <div class="col-md-8">
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
            <span>{{ t('crm.company.requisites.section.bank') }}</span>
          </div>
        </div>

        <!-- Bank name -->
        <div class="col-12">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.bank') }}</label>
          <InputText v-model="form.bank" class="w-full" />
        </div>

        <!-- Bank code label + Bank code -->
        <div class="col-md-4">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.bankCodeLabel') }}</label>
          <InputText v-model="form.bank_code_label" class="w-full" placeholder="БИК / SWIFT" />
        </div>
        <div class="col-md-8">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.bankCode') }}</label>
          <InputText v-model="form.bank_code" class="w-full" />
        </div>

        <!-- Account -->
        <div class="col-12">
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.account') }}</label>
          <InputText v-model="form.account" class="w-full" />
        </div>

        <!-- Valid from / to -->
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
          <label class="requisite-form__label">{{ t('crm.company.requisites.fields.validTo') }}</label>
          <DatePicker
            v-model="form.valid_to_date"
            class="w-full"
            date-format="dd.mm.yy"
            show-button-bar
          />
        </div>

        <!-- Set as current (only on create) -->
        <div v-if="!isEdit" class="col-12">
          <div class="requisite-form__toggle-row">
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
import { localDateString, parseDateLocal } from '@/utils/activity'
import type { CompanyRequisite, CreateRequisitePayload } from '@/entities/crm'

const props = defineProps<{
  modelValue: boolean
  requisite?: CompanyRequisite | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  saved: [payload: CreateRequisitePayload, id?: number, setAsCurrent?: boolean]
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
  legal_form: string
  full_legal_form: string
  gender_ending_oe: string
  director_position: string
  director_short: string
  director_genitive: string
  acts_basis: string
  tax_id_label: string
  tax_id: string
  country_code: string | null
  address: string
  // bank fields (flat in form, nested in payload)
  bank: string
  bank_code_label: string
  bank_code: string
  account: string
  valid_from_date: Date | null
  valid_to_date: Date | null
  note: string
  set_as_current: boolean
}

function emptyForm(): FormState {
  return {
    label: '',
    legal_name: '',
    legal_form: '',
    full_legal_form: '',
    gender_ending_oe: '',
    director_position: '',
    director_short: '',
    director_genitive: '',
    acts_basis: '',
    tax_id_label: '',
    tax_id: '',
    country_code: null,
    address: '',
    bank: '',
    bank_code_label: '',
    bank_code: '',
    account: '',
    valid_from_date: null,
    valid_to_date: null,
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
        legal_form: req.legal_form ?? '',
        full_legal_form: req.full_legal_form ?? '',
        gender_ending_oe: req.gender_ending_oe ?? '',
        director_position: req.director_position ?? '',
        director_short: req.director_short ?? '',
        director_genitive: req.director_genitive ?? '',
        acts_basis: req.acts_basis ?? '',
        tax_id_label: req.tax_id_label ?? '',
        tax_id: req.tax_id ?? '',
        country_code: req.country_code ?? null,
        address: req.address ?? '',
        bank: req.bank_details?.bank ?? '',
        bank_code_label: req.bank_details?.bank_code_label ?? '',
        bank_code: req.bank_details?.bank_code ?? '',
        account: req.bank_details?.account ?? '',
        // parseDateLocal avoids the UTC midnight day-shift that new Date('YYYY-MM-DD') causes
        valid_from_date: req.valid_from ? parseDateLocal(req.valid_from) : null,
        valid_to_date: req.valid_to ? parseDateLocal(req.valid_to) : null,
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
  return Object.keys(errors.value).length === 0
}

// ── Submit ─────────────────────────────────────────────────────────────────────

function onSubmit() {
  if (!validate()) return

  // Build bank_details only if any bank field is set
  const bank = form.value.bank.trim() || null
  const bankCodeLabel = form.value.bank_code_label.trim() || null
  const bankCode = form.value.bank_code.trim() || null
  const account = form.value.account.trim() || null

  const bankDetails =
    bank || bankCodeLabel || bankCode || account
      ? { bank, bank_code_label: bankCodeLabel, bank_code: bankCode, account }
      : null

  const payload: CreateRequisitePayload = {
    label: form.value.label.trim() || null,
    legal_name: form.value.legal_name.trim() || null,
    legal_form: form.value.legal_form.trim() || null,
    full_legal_form: form.value.full_legal_form.trim() || null,
    gender_ending_oe: form.value.gender_ending_oe.trim() || null,
    director_position: form.value.director_position.trim() || null,
    director_short: form.value.director_short.trim() || null,
    director_genitive: form.value.director_genitive.trim() || null,
    acts_basis: form.value.acts_basis.trim() || null,
    tax_id_label: form.value.tax_id_label.trim() || null,
    tax_id: form.value.tax_id.trim() || null,
    country_code: form.value.country_code || null,
    address: form.value.address.trim() || null,
    bank_details: bankDetails,
    // localDateString() uses local calendar date — avoids UTC midnight day-shift
    valid_from: form.value.valid_from_date
      ? localDateString(form.value.valid_from_date)
      : null,
    valid_to: form.value.valid_to_date
      ? localDateString(form.value.valid_to_date)
      : null,
    note: form.value.note.trim() || null,
  }

  emit('saved', payload, props.requisite?.id, form.value.set_as_current)
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
    font-size: $font-size-3xs;
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
  align-items: center;
  gap: $space-3;
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
