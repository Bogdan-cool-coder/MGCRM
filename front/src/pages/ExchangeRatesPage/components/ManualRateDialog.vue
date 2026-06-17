<template>
  <Dialog
    v-model:visible="visible"
    modal
    style="width: 480px"
    :closable="!saving"
    :header="t('catalog.exchangeRates.manual.title')"
    @hide="onHide"
  >
    <div class="manual-rate">
      <!-- From currency -->
      <div class="manual-rate__field">
        <label class="manual-rate__label">
          {{ t('catalog.exchangeRates.manual.fields.from') }} <span class="req">*</span>
        </label>
        <Select
          v-model="form.from_code"
          :options="currencyOptions"
          :placeholder="t('catalog.exchangeRates.manual.fields.from')"
          class="w-full"
          :class="{ 'p-invalid': errors.from_code }"
        />
        <small v-if="errors.from_code" class="p-error">{{ errors.from_code }}</small>
      </div>

      <!-- To currency -->
      <div class="manual-rate__field">
        <label class="manual-rate__label">
          {{ t('catalog.exchangeRates.manual.fields.to') }} <span class="req">*</span>
        </label>
        <Select
          v-model="form.to_code"
          :options="currencyOptions"
          :placeholder="t('catalog.exchangeRates.manual.fields.to')"
          class="w-full"
          :class="{ 'p-invalid': errors.to_code }"
        />
        <small v-if="errors.to_code" class="p-error">{{ errors.to_code }}</small>
      </div>

      <!-- Rate -->
      <div class="manual-rate__field">
        <label class="manual-rate__label">
          {{ t('catalog.exchangeRates.manual.fields.rate') }} <span class="req">*</span>
        </label>
        <InputNumber
          v-model="form.rate"
          :min="0.000001"
          :min-fraction-digits="6"
          :max-fraction-digits="6"
          locale="en-US"
          class="w-full"
          :class="{ 'p-invalid': errors.rate }"
        />
        <small v-if="errors.rate" class="p-error">{{ errors.rate }}</small>
      </div>

      <!-- Date -->
      <div class="manual-rate__field">
        <label class="manual-rate__label">
          {{ t('catalog.exchangeRates.manual.fields.date') }} <span class="req">*</span>
        </label>
        <DatePicker
          v-model="form.date"
          date-format="dd.mm.yy"
          class="w-full"
          :class="{ 'p-invalid': errors.date }"
          :max-date="today"
        />
        <small v-if="errors.date" class="p-error">{{ errors.date }}</small>
      </div>
    </div>

    <template #footer>
      <div class="manual-rate__footer">
        <Button
          :label="t('catalog.exchangeRates.manual.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="onCancel"
        />
        <Button
          icon="pi pi-check"
          :label="t('catalog.exchangeRates.manual.save')"
          :loading="saving"
          @click="onSubmit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import DatePicker from 'primevue/datepicker'
import Button from 'primevue/button'
import { CURRENCY_WHITELIST } from '@/utils/currency'
import type { ExchangeRateDto } from '@/entities/catalog'

const props = defineProps<{
  modelValue: boolean
  editRate?: ExchangeRateDto | null
  saving?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submit: [payload: {
    from_code: string
    to_code: string
    rate: number
    date: string
    id?: number
  }]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const today = new Date()

const currencyOptions = [...CURRENCY_WHITELIST]

interface RateForm {
  from_code: string
  to_code: string
  rate: number | null
  date: Date | null
}

const defaultForm = (): RateForm => ({
  from_code: '',
  to_code: '',
  rate: null,
  date: new Date(),
})

const form = ref<RateForm>(defaultForm())
const errors = ref<Record<string, string>>({})

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      errors.value = {}
      if (props.editRate) {
        form.value = {
          from_code: props.editRate.from_code,
          to_code: props.editRate.to_code,
          rate: props.editRate.rate,
          date: new Date(props.editRate.date),
        }
      } else {
        form.value = defaultForm()
      }
    }
  },
)

function dateToApiString(d: Date): string {
  const y = d.getFullYear()
  const m = String(d.getMonth() + 1).padStart(2, '0')
  const day = String(d.getDate()).padStart(2, '0')
  return `${y}-${m}-${day}`
}

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.from_code) errs.from_code = t('catalog.product.form.errors.nameRequired')
  if (!form.value.to_code) errs.to_code = t('catalog.product.form.errors.nameRequired')
  if (!form.value.rate || form.value.rate <= 0) errs.rate = t('catalog.product.form.errors.nameRequired')
  if (!form.value.date) errs.date = t('catalog.product.form.errors.nameRequired')
  errors.value = errs
  return Object.keys(errs).length === 0
}

function onSubmit() {
  if (!validate()) return
  emit('submit', {
    from_code: form.value.from_code,
    to_code: form.value.to_code,
    rate: form.value.rate!,
    date: dateToApiString(form.value.date!),
    id: props.editRate?.id,
  })
}

function onCancel() {
  visible.value = false
}

function onHide() {
  errors.value = {}
}
</script>

<style lang="scss" scoped>
.manual-rate {
  display: flex;
  flex-direction: column;
  gap: $space-4;

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
    width: 100%;
  }
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
