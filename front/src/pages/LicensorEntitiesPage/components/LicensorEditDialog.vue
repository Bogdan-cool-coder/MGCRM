<template>
  <Dialog
    v-model:visible="visible"
    :header="t('licensors.editDialog.title', 'Редактировать лицензиара')"
    :style="{ width: '560px' }"
    modal
    :draggable="false"
  >
    <div v-if="licensor" class="d-flex flex-column gap-3">
      <div class="row g-2">
        <div class="col-12">
          <label class="licensor-dialog__label">{{ t('licensors.fields.name', 'Название') }} *</label>
          <InputText v-model="form.name" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.legalForm', 'Правовая форма') }} *</label>
          <InputText v-model="form.legal_form" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.fullLegalForm', 'Полная форма') }} *</label>
          <InputText v-model="form.full_legal_form" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.directorShort', 'Директор (кратко)') }}</label>
          <InputText v-model="form.director_short" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.directorGenitive', 'Директор (род. падеж)') }}</label>
          <InputText v-model="form.director_genitive" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.directorPosition', 'Должность директора') }}</label>
          <InputText v-model="form.director_position" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.actsBasis', 'Основание') }}</label>
          <InputText v-model="form.acts_basis" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.taxIdLabel', 'Метка ИНН') }}</label>
          <InputText v-model="form.tax_id_label" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.taxId', 'ИНН / БИН / ИНН') }}</label>
          <InputText v-model="form.tax_id" class="w-100 mt-1" />
        </div>
        <div class="col-12">
          <label class="licensor-dialog__label">{{ t('licensors.fields.address', 'Адрес') }}</label>
          <InputText v-model="form.address" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.phone', 'Телефон') }}</label>
          <InputText v-model="form.phone" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.email', 'Email') }}</label>
          <InputText v-model="form.email" class="w-100 mt-1" />
        </div>
        <!-- Default bank (fallback for currencies without a bank account) -->
        <div class="col-12">
          <p class="fw-semibold mb-1 mt-2 licensor-dialog__section-title">
            {{ t('licensors.fields.defaultBank', 'Основной счёт (запасной)') }}
          </p>
        </div>
        <div class="col-12">
          <label class="licensor-dialog__label">{{ t('licensors.fields.bank', 'Банк') }}</label>
          <InputText v-model="form.bank" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.bankCode', 'БИК/Код банка') }}</label>
          <InputText v-model="form.bank_code" class="w-100 mt-1" />
        </div>
        <div class="col-6">
          <label class="licensor-dialog__label">{{ t('licensors.fields.account', 'Счёт') }}</label>
          <InputText v-model="form.account" class="w-100 mt-1" />
        </div>
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
import type { LicensorEntityDto, PatchLicensorEntityPayload } from '@/entities/licensor'

const props = defineProps<{
  modelValue: boolean
  licensor: LicensorEntityDto | null
  saving: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: PatchLicensorEntityPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

interface FormShape {
  name: string
  legal_form: string
  full_legal_form: string
  director_short: string
  director_genitive: string
  director_position: string
  acts_basis: string
  tax_id_label: string
  tax_id: string
  address: string
  phone: string
  email: string
  bank: string
  bank_code: string
  account: string
}

const form = ref<FormShape>({
  name: '',
  legal_form: '',
  full_legal_form: '',
  director_short: '',
  director_genitive: '',
  director_position: '',
  acts_basis: '',
  tax_id_label: '',
  tax_id: '',
  address: '',
  phone: '',
  email: '',
  bank: '',
  bank_code: '',
  account: '',
})

watch(
  () => props.modelValue,
  (open) => {
    if (open && props.licensor) {
      const l = props.licensor
      form.value = {
        name: l.name,
        legal_form: l.legal_form,
        full_legal_form: l.full_legal_form,
        director_short: l.director_short,
        director_genitive: l.director_genitive,
        director_position: l.director_position,
        acts_basis: l.acts_basis ?? '',
        tax_id_label: l.tax_id_label,
        tax_id: l.tax_id,
        address: l.address,
        phone: l.phone ?? '',
        email: l.email ?? '',
        bank: l.bank,
        bank_code: l.bank_code,
        account: l.account,
      }
    }
  },
)

function save() {
  const payload: PatchLicensorEntityPayload = {
    name: form.value.name,
    legal_form: form.value.legal_form,
    full_legal_form: form.value.full_legal_form,
    director_short: form.value.director_short,
    director_genitive: form.value.director_genitive,
    director_position: form.value.director_position,
    acts_basis: form.value.acts_basis || null,
    tax_id_label: form.value.tax_id_label,
    tax_id: form.value.tax_id,
    address: form.value.address,
    phone: form.value.phone || null,
    email: form.value.email || null,
    bank: form.value.bank,
    bank_code: form.value.bank_code,
    account: form.value.account,
  }
  emit('save', payload)
}
</script>

<style lang="scss" scoped>
.licensor-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }

  &__section-title {
    font-size: $font-size-sm;
    color: var(--p-text-muted-color);
    border-bottom: 1px solid var(--p-surface-200);
    padding-bottom: 0.25rem;
  }
}
</style>
