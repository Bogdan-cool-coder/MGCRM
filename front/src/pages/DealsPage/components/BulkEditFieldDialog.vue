<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deals.page.bulk.editFieldDialog.title', { n: dealIds.length })"
    modal
    style="width: 460px"
    :closable="!saving"
    class="bulk-edit-field-dialog"
  >
    <div class="bulk-edit-field-dialog__body">
      <!-- Field selector -->
      <div class="bulk-edit-field-dialog__field">
        <label class="bulk-edit-field-dialog__label">
          {{ t('sales.deals.page.bulk.editFieldDialog.field') }}
          <span class="req">*</span>
        </label>
        <Select
          v-model="selectedField"
          :options="fieldOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          :placeholder="t('sales.deals.page.bulk.editFieldDialog.fieldPlaceholder')"
        />
      </div>

      <!-- Value for currency -->
      <div v-if="selectedField === 'currency'" class="bulk-edit-field-dialog__field">
        <label class="bulk-edit-field-dialog__label">
          {{ t('sales.deals.page.bulk.editFieldDialog.value') }}
          <span class="req">*</span>
        </label>
        <Select
          v-model="currencyValue"
          :options="currencyOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          :class="{ 'p-invalid': hasValueError }"
          :placeholder="t('sales.deals.page.bulk.editFieldDialog.valuePlaceholder')"
        />
        <small v-if="hasValueError" class="p-error">
          {{ t('sales.deals.page.bulk.editFieldDialog.valueRequired') }}
        </small>
      </div>

      <!-- No field selected hint -->
      <p v-if="!selectedField" class="bulk-edit-field-dialog__hint">
        {{ t('sales.deals.page.bulk.editFieldDialog.selectFieldHint') }}
      </p>
    </div>

    <template #footer>
      <div class="bulk-edit-field-dialog__footer">
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          icon="pi pi-check"
          :label="t('sales.deals.page.bulk.editFieldDialog.apply')"
          :loading="saving"
          :disabled="!selectedField"
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
import Button from 'primevue/button'
import Select from 'primevue/select'
import { useMutation } from '@/composables/async/useMutation'
import { salesApi } from '@/api/sales'
import { CURRENCY_WHITELIST } from '@/utils/currency'
import type { BulkDealField } from '@/entities/sales'

const props = defineProps<{
  modelValue: boolean
  dealIds: number[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  done: []
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const selectedField = ref<BulkDealField | null>(null)
const currencyValue = ref<string | null>(null)
const hasValueError = ref(false)

const mutation = useMutation()
const saving = computed(() => mutation.isPending.value)

const fieldOptions = computed(() => [
  { value: 'currency' as BulkDealField, label: t('sales.deals.form.fields.currency') },
])

const currencyOptions = computed(() =>
  CURRENCY_WHITELIST.map((c) => ({ value: c, label: c })),
)

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      selectedField.value = null
      currencyValue.value = null
      hasValueError.value = false
    }
  },
)

function getFieldValue(): unknown {
  if (selectedField.value === 'currency') return currencyValue.value
  return null
}

function validateValue(): boolean {
  if (selectedField.value === 'currency' && !currencyValue.value) {
    hasValueError.value = true
    return false
  }
  hasValueError.value = false
  return true
}

async function onSubmit() {
  if (!selectedField.value) return
  if (!validateValue()) return

  await mutation.run(() =>
    salesApi.bulkPatchDeals({
      deal_ids: props.dealIds,
      operation: 'set_field',
      field: selectedField.value!,
      value: getFieldValue(),
    }),
  )

  visible.value = false
  emit('done')
}
</script>

<style lang="scss" scoped>
.bulk-edit-field-dialog {
  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-4;
    padding: $space-2 0;
  }

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

  &__hint {
    font-size: $font-size-sm;
    color: $surface-400;
    margin: 0;
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
  }
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
