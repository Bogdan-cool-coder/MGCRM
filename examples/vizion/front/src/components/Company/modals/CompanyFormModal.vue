<template>
  <Dialog
    :visible="visible"
    @update:visible="(value) => emit('update:visible', value)"
    modal
    :breakpoints="{ '1199px': '75vw', '575px': '90vw' }"
    :header="isEditMode ? t('editTitle') : t('createTitle')"
    :closable="true"
  >
    <div class="company-form">
      <div class="form-group">
        <label for="name" class="form-label">{{ t('nameLabel') }}</label>
        <InputText
          id="name"
          v-model="formData.name"
          :placeholder="t('namePlaceholder')"
          :disabled="!canEditAllFields"
          :class="{ 'p-invalid': errors.name }"
        />
        <small v-if="!canEditAllFields" class="form-help">{{ t('readOnlyForAdmin') }}</small>
        <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
      </div>

      <div class="form-group">
        <label for="crm_url" class="form-label">{{ t('crmUrlLabel') }}</label>
        <InputText
          id="crm_url"
          v-model="formData.crm_url"
          type="url"
          placeholder="https://macroserver.kz"
        />
      </div>

      <div class="form-row">
        <div class="form-group">
          <label for="currency_code" class="form-label">{{ t('currencyLabel') }}</label>
          <Select
            id="currency_code"
            :model-value="currencySelectValue"
            :options="currencyOptions"
            option-value="value"
            option-label="label"
            :placeholder="t('currencyPlaceholder')"
            :show-clear="true"
            class="w-full"
            :class="{ 'p-invalid': errors.currency_code }"
            @update:model-value="onCurrencySelectChange"
          />
          <InputText
            v-if="isCustomCurrency"
            v-model="customCurrencyInput"
            class="form-custom-input"
            :placeholder="t('currencyCustomPlaceholder')"
            maxlength="3"
            :class="{ 'p-invalid': errors.currency_code }"
            @input="onCustomCurrencyInput"
          />
          <small class="form-help">{{ t('currencyHelp') }}</small>
          <small v-if="errors.currency_code" class="p-error">{{ errors.currency_code }}</small>
        </div>

        <div class="form-group">
          <label for="timezone" class="form-label">{{ t('timezoneLabel') }}</label>
          <Select
            id="timezone"
            v-model="formData.timezone"
            :options="timezoneOptions"
            option-value="value"
            option-label="label"
            :placeholder="t('timezonePlaceholder')"
            :show-clear="true"
            :filter="true"
            :filter-placeholder="t('timezoneFilterPlaceholder')"
            :auto-filter-focus="true"
            class="w-full"
            :class="{ 'p-invalid': errors.timezone }"
          />
          <small class="form-help">{{ t('timezoneHelp') }}</small>
          <small v-if="errors.timezone" class="p-error">{{ errors.timezone }}</small>
        </div>
      </div>

      <!-- MacroData credentials are superadmin-only: backend ignores them
           in the admin payload whitelist, so we hide the whole section to
           avoid showing inputs an admin can read but not save. -->
      <div v-if="canEditAllFields" class="form-section">
        <h4>{{ t('macroDataOptional') }}</h4>

        <div class="form-row">
          <div class="form-group">
            <label for="host" class="form-label">{{ t('hostLabel') }}</label>
            <InputText
              id="host"
              v-model="formData.macrodata_host"
              :placeholder="t('hostPlaceholder')"
            />
          </div>
          <div class="form-group">
            <label for="port" class="form-label">{{ t('portLabel') }}</label>
            <InputText
              id="port"
              v-model="formData.macrodata_port"
              type="number"
              placeholder="3306"
            />
          </div>
        </div>

        <div class="form-row">
          <div class="form-group">
            <label for="database" class="form-label">{{ t('databaseLabel') }}</label>
            <InputText
              id="database"
              v-model="formData.macrodata_database"
              :placeholder="t('databasePlaceholder')"
            />
          </div>
          <div class="form-group">
            <label for="username" class="form-label">{{ t('usernameLabel') }}</label>
            <InputText
              id="username"
              v-model="formData.macrodata_username"
              :placeholder="t('usernamePlaceholder')"
            />
          </div>
        </div>

        <div class="form-group">
          <label for="password" class="form-label">{{ t('passwordLabel') }}</label>
          <Password
            id="password"
            v-model="formData.macrodata_password"
            toggleMask
            :feedback="false"
            :placeholder="t('passwordPlaceholder')"
          />
        </div>
      </div>

      <div v-if="formError" class="form-error">{{ formError }}</div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="danger" @click="handleCancel" />
      <Button
        :label="isEditMode ? t('common.save') : t('common.create')"
        :loading="saving"
        @click="handleSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Password from 'primevue/password'
import Select from 'primevue/select'
import Button from 'primevue/button'
import type { CompanyFormData, CompanyFormErrors } from '@/components/Company'
import {
  COMMON_CURRENCIES,
  CURRENCY_CODE_PATTERN,
  listTimezoneOptions,
} from '@/components/Company/constants'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

const { t } = useLocalI18n({ en, ru })

interface Props {
  visible: boolean
  isEditMode: boolean
  formData: CompanyFormData
  errors: CompanyFormErrors
  formError: string
  saving: boolean
  /**
   * Whether the current user can edit every field (superadmin). When false
   * (admin), only `crm_url`, `currency_code` and `timezone` stay editable —
   * the rest are rendered disabled with a hint. Matches the backend RBAC
   * whitelist on PUT /api/companies/{id}.
   */
  canEditAllFields?: boolean
}

interface Emits {
  (e: 'update:visible', value: boolean): void
  (e: 'cancel'): void
  (e: 'submit'): void
}

const props = withDefaults(defineProps<Props>(), {
  canEditAllFields: true,
})
const emit = defineEmits<Emits>()

const CUSTOM_CURRENCY_SENTINEL = '__custom__'

// Timezone list is stable per page-load — compute once and reuse for the
// Select dropdown. ~430 entries when Intl.supportedValuesOf is available.
const timezoneOptions = computed(() => listTimezoneOptions())

const currencyOptions = computed(() => [
  ...COMMON_CURRENCIES.map((c) => ({ value: c.value, label: c.label })),
  { value: CUSTOM_CURRENCY_SENTINEL, label: t('currencyCustomOption') },
])

const knownCurrencyCodes = new Set(COMMON_CURRENCIES.map((c) => c.value))

// Whether the user is in "Other" mode (typing a custom 3-letter code).
const isCustomCurrency = ref(false)
const customCurrencyInput = ref('')

/**
 * Bridge the (single string) `formData.currency_code` against the
 * (string-or-sentinel) Select model. If the current code is in the known
 * list — show that option. If it's set but not in the list — switch to
 * "Other" mode and pre-fill the custom input.
 */
const currencySelectValue = computed<string | null>(() => {
  const code = props.formData.currency_code
  if (!code) return null
  if (knownCurrencyCodes.has(code)) return code
  return CUSTOM_CURRENCY_SENTINEL
})

const onCurrencySelectChange = (value: string | null) => {
  if (value === null) {
    props.formData.currency_code = ''
    isCustomCurrency.value = false
    customCurrencyInput.value = ''
    // Clearing the select === "no preference" (= null on backend) — drop any
    // stale custom-mode error so the user isn't stuck staring at a red border
    // on an empty/disabled input.
    props.errors.currency_code = undefined
    return
  }

  if (value === CUSTOM_CURRENCY_SENTINEL) {
    isCustomCurrency.value = true
    // Seed custom input with current value if it's already custom.
    customCurrencyInput.value = knownCurrencyCodes.has(props.formData.currency_code)
      ? ''
      : props.formData.currency_code
    props.formData.currency_code = customCurrencyInput.value
    return
  }

  isCustomCurrency.value = false
  customCurrencyInput.value = ''
  props.formData.currency_code = value
  // Picking a known currency cancels any prior custom-mode validation error.
  props.errors.currency_code = undefined
}

const onCustomCurrencyInput = () => {
  // Force uppercase + strip non-letters to match backend regex /^[A-Z]{3}$/.
  // Live mutation keeps the underlying form value valid as the user types.
  const normalized = customCurrencyInput.value
    .toUpperCase()
    .replace(/[^A-Z]/g, '')
    .slice(0, 3)

  if (normalized !== customCurrencyInput.value) {
    customCurrencyInput.value = normalized
  }

  props.formData.currency_code = normalized

  // Clear the inline error as soon as the user types a valid 3-letter code.
  // Keeps the red border in sync with the actual validity state instead of
  // forcing the user to submit again to see the error disappear.
  if (props.errors.currency_code && CURRENCY_CODE_PATTERN.test(normalized)) {
    props.errors.currency_code = undefined
  }
}

// Sync custom-mode flag when the parent re-hydrates the form on modal-open.
// Originally this watched `[visible, formData.currency_code]` together, but
// that introduced a race with `onCurrencySelectChange`: selecting the
// "Other…" option seeds `currency_code = ''` (empty pending the custom
// input), which re-triggered the watcher and flipped `isCustomCurrency`
// back to false — so the custom InputText never appeared. We split the
// reseed into two narrowly-scoped watches:
//
//   1) On modal-open transition only — initialise `isCustomCurrency` from
//      whatever value the parent hydrated into `formData.currency_code`.
//   2) On external currency_code changes (parent push, not a select edit)
//      — re-infer custom-mode when the code arrives from outside *and*
//      doesn't match a known currency. Guarded by `isCustomCurrency`'s
//      current state so a user-initiated empty-string transition (just
//      after clicking "Other…") doesn't clobber the flag.
watch(
  () => props.visible,
  (visible) => {
    if (!visible) return
    const code = props.formData.currency_code
    if (code && !knownCurrencyCodes.has(code)) {
      isCustomCurrency.value = true
      customCurrencyInput.value = code
    } else {
      isCustomCurrency.value = false
      customCurrencyInput.value = ''
    }
  },
  { immediate: true },
)

watch(
  () => props.formData.currency_code,
  (code) => {
    if (!props.visible) return
    // If the user is actively in custom mode and the code is empty (because
    // they just clicked "Other…" and haven't typed yet), do NOT exit custom
    // mode — that's the race the dual-watch caused before.
    if (isCustomCurrency.value && (!code || code === '')) return
    if (code && !knownCurrencyCodes.has(code) && !isCustomCurrency.value) {
      // External hydration with a non-standard code — flip into custom mode.
      isCustomCurrency.value = true
      customCurrencyInput.value = code
    }
    // For known codes, `onCurrencySelectChange` already maintains the flag —
    // no else-branch needed here, and adding one is what caused the bug.
  },
)

const handleCancel = () => {
  emit('cancel')
}

const handleSubmit = () => {
  // Last-chance client-side guard for the "Other currency…" flow. Picking
  // the "Other" option signals intent to enter a custom code, so an empty
  // or malformed value must NOT silently clear `currency_code` (that would
  // be the clear-button's job). Surface an inline error and block submit.
  // The backend regex remains the canonical validator for everything else.
  if (
    isCustomCurrency.value &&
    !CURRENCY_CODE_PATTERN.test(props.formData.currency_code)
  ) {
    props.errors.currency_code = t('currencyInvalid')
    return
  }
  emit('submit')
}
</script>

<style lang="scss" scoped>
.company-form {
  .form-group {
    margin-bottom: 1rem;

    .form-label {
      display: block;
      margin-bottom: 0.5rem;
      font-size: $font-size-sm;
      font-weight: $font-weight-medium;
      color: $surface-700;
    }

    .form-help {
      display: block;
      margin-top: 0.25rem;
      font-size: $font-size-xs;
      color: $surface-500;
    }

    .form-custom-input {
      margin-top: 0.5rem;
      width: 100%;
    }

    .p-error {
      color: $danger;
      font-size: $font-size-xs;
      margin-top: 0.25rem;
    }
  }

  .form-row {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 1rem;

    @media (max-width: '575px') {
      grid-template-columns: 1fr;
    }
  }

  .form-section {
    margin-top: 1.5rem;
    padding-top: 1.5rem;
    border-top: 1px solid $surface-200;

    h4 {
      margin: 0 0 1rem;
      font-size: $font-size-md;
      font-weight: $font-weight-semibold;
      color: $surface-800;
    }
  }

  .form-error {
    margin-top: 1rem;
    padding: 0.75rem;
    background-color: $errorBg;
    border: 1px solid $errorBorder;
    border-radius: $border-radius;
    color: $danger;
    font-size: $font-size-sm;
  }
}
</style>
