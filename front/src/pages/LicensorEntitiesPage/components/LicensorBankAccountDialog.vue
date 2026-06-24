<template>
  <Dialog
    v-model:visible="visible"
    :header="editingAccount
      ? t('licensors.bankDialog.editTitle', 'Редактировать счёт')
      : t('licensors.bankDialog.addTitle', 'Добавить счёт')"
    :style="{ width: '440px' }"
    modal
    :draggable="false"
  >
    <div class="d-flex flex-column gap-3">
      <div>
        <label class="bank-dialog__label">{{ t('licensors.bankDialog.currency', 'Валюта') }} *</label>
        <Select
          v-model="form.currency"
          :options="currencyOptions"
          :disabled="!!editingAccount"
          class="w-100 mt-1"
        />
      </div>
      <div>
        <label class="bank-dialog__label">{{ t('licensors.bankDialog.bank', 'Банк') }} *</label>
        <InputText v-model="form.bank" class="w-100 mt-1" />
      </div>
      <div class="row g-2">
        <div class="col-6">
          <label class="bank-dialog__label">{{ t('licensors.bankDialog.bankCodeLabel', 'Метка кода') }}</label>
          <InputText v-model="form.bank_code_label" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="bank-dialog__label">{{ t('licensors.bankDialog.bankCode', 'Код банка / БИК') }} *</label>
          <InputText v-model="form.bank_code" class="w-100 mt-1" />
        </div>
      </div>
      <div>
        <label class="bank-dialog__label">{{ t('licensors.bankDialog.account', 'Номер счёта') }} *</label>
        <InputText v-model="form.account" class="w-100 mt-1" />
      </div>
      <div>
        <label class="bank-dialog__label">{{ t('licensors.bankDialog.swift', 'SWIFT') }}</label>
        <InputText v-model="form.swift" class="w-100 mt-1" />
      </div>
      <div class="d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_primary" input-id="is-primary" />
        <label for="is-primary" class="bank-dialog__label mb-0">
          {{ t('licensors.bankDialog.isPrimary', 'Основной для валюты') }}
        </label>
      </div>
      <div>
        <label class="bank-dialog__label">{{ t('licensors.bankDialog.note', 'Примечание') }}</label>
        <InputText v-model="form.note" class="w-100 mt-1" />
      </div>
    </div>

    <template #footer>
      <div class="d-flex gap-2 justify-content-end">
        <Button
          :label="t('common.cancel', 'Отмена')"
          severity="secondary"
          text
          @click="visible = false"
        />
        <Button
          :label="t('common.save', 'Сохранить')"
          :loading="saving"
          @click="save"
        />
      </div>
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
import ToggleSwitch from 'primevue/toggleswitch'
import type { LicensorBankAccountDto, StoreLicensorBankAccountPayload } from '@/entities/licensor'

const props = defineProps<{
  modelValue: boolean
  editingAccount: LicensorBankAccountDto | null
  saving: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: StoreLicensorBankAccountPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const currencyOptions = ['KZT', 'UZS', 'RUB', 'USD', 'EUR']

interface FormShape {
  currency: string
  bank: string
  bank_code_label: string
  bank_code: string
  account: string
  swift: string
  is_primary: boolean
  note: string
}

const emptyForm = (): FormShape => ({
  currency: 'USD',
  bank: '',
  bank_code_label: '',
  bank_code: '',
  account: '',
  swift: '',
  is_primary: false,
  note: '',
})

const form = ref<FormShape>(emptyForm())

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      if (props.editingAccount) {
        const a = props.editingAccount
        form.value = {
          currency: a.currency,
          bank: a.bank,
          bank_code_label: a.bank_code_label,
          bank_code: a.bank_code,
          account: a.account,
          swift: a.swift ?? '',
          is_primary: a.is_primary,
          note: a.note ?? '',
        }
      } else {
        form.value = emptyForm()
      }
    }
  },
)

function save() {
  const payload: StoreLicensorBankAccountPayload = {
    currency: form.value.currency,
    bank: form.value.bank,
    bank_code_label: form.value.bank_code_label,
    bank_code: form.value.bank_code,
    account: form.value.account,
    swift: form.value.swift || null,
    is_primary: form.value.is_primary,
    note: form.value.note || null,
  }
  emit('save', payload)
}
</script>

<style lang="scss" scoped>
.bank-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
