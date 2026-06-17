<template>
  <Dialog
    v-model:visible="visible"
    modal
    style="width: 480px"
    :closable="!saving"
    :header="isEdit ? t('catalog.product.page.plan.editTitle') : t('catalog.product.page.plan.createTitle')"
    @hide="onHide"
  >
    <div class="plan-dialog">
      <!-- Name -->
      <div class="plan-dialog__field">
        <label class="plan-dialog__label">
          {{ t('catalog.product.page.plan.fields.name') }} <span class="req">*</span>
        </label>
        <InputText
          v-model="form.name"
          class="w-full"
          :class="{ 'p-invalid': errors.name }"
        />
        <small v-if="errors.name" class="p-error">{{ errors.name }}</small>
      </div>

      <!-- Code -->
      <div class="plan-dialog__field">
        <label class="plan-dialog__label">{{ t('catalog.product.page.plan.fields.code') }}</label>
        <InputText
          v-model="form.code"
          class="w-full"
          :placeholder="t('catalog.product.page.plan.fields.code')"
        />
      </div>

      <!-- Unit -->
      <div class="plan-dialog__field">
        <label class="plan-dialog__label">
          {{ t('catalog.product.page.plan.fields.unit') }} <span class="req">*</span>
        </label>
        <Select
          v-model="form.unit"
          :options="unitOptions"
          option-label="label"
          option-value="value"
          class="w-full"
          :class="{ 'p-invalid': errors.unit }"
        />
        <small v-if="errors.unit" class="p-error">{{ errors.unit }}</small>
      </div>

      <!-- Sort Order -->
      <div class="plan-dialog__field">
        <label class="plan-dialog__label">{{ t('catalog.product.page.plan.fields.sortOrder') }}</label>
        <InputNumber v-model="form.sort_order" :min="0" class="w-full" />
      </div>

      <!-- Is Active -->
      <div class="plan-dialog__field plan-dialog__field--row">
        <label class="plan-dialog__label">{{ t('catalog.product.page.plan.fields.isActive') }}</label>
        <ToggleSwitch v-model="form.is_active" />
      </div>

      <!-- Hint -->
      <Message severity="info" size="small">
        {{ t('catalog.product.page.plan.hint') }}
      </Message>
    </div>

    <template #footer>
      <div class="plan-dialog__footer">
        <Button
          :label="t('catalog.product.page.plan.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="onCancel"
        />
        <Button
          icon="pi pi-check"
          :label="t('catalog.product.page.plan.save')"
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
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import Message from 'primevue/message'
import Button from 'primevue/button'
import type { ProductPlanDto } from '@/entities/catalog'

const props = defineProps<{
  modelValue: boolean
  editPlan?: ProductPlanDto | null
  saving?: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  submit: [payload: {
    name: string
    code: string | null
    unit: string
    sort_order: number
    is_active: boolean
  }]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const isEdit = computed(() => !!props.editPlan)

interface PlanForm {
  name: string
  code: string
  unit: string
  sort_order: number
  is_active: boolean
}

const defaultForm = (): PlanForm => ({
  name: '',
  code: '',
  unit: 'year',
  sort_order: 0,
  is_active: true,
})

const form = ref<PlanForm>(defaultForm())
const errors = ref<Record<string, string>>({})

const unitOptions = computed(() => [
  { value: 'year', label: t('catalog.products.unit.year') },
  { value: 'one_time', label: t('catalog.products.unit.one_time') },
  { value: 'minute', label: t('catalog.products.unit.minute') },
  { value: 'package', label: t('catalog.products.unit.package') },
])

watch(
  () => props.modelValue,
  (open) => {
    if (open) {
      errors.value = {}
      if (props.editPlan) {
        form.value = {
          name: props.editPlan.name,
          code: props.editPlan.code ?? '',
          unit: props.editPlan.unit,
          sort_order: props.editPlan.sort_order,
          is_active: props.editPlan.is_active,
        }
      } else {
        form.value = defaultForm()
      }
    }
  },
)

function validate(): boolean {
  const errs: Record<string, string> = {}
  if (!form.value.name) errs.name = t('catalog.product.form.errors.nameRequired')
  if (!form.value.unit) errs.unit = t('catalog.product.form.errors.pricingTypeRequired')
  errors.value = errs
  return Object.keys(errs).length === 0
}

function onSubmit() {
  if (!validate()) return
  emit('submit', {
    name: form.value.name,
    code: form.value.code || null,
    unit: form.value.unit,
    sort_order: form.value.sort_order,
    is_active: form.value.is_active,
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
.plan-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;

    &--row {
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
    }
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
