<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deal.page.products.addDialog.title')"
    modal
    style="width: 560px"
    :closable="!saving"
  >
    <div class="add-product-dialog">
      <!-- Product search -->
      <div class="add-product-dialog__field">
        <label class="add-product-dialog__label">
          {{ t('sales.deal.page.products.addDialog.fields.product') }} <span class="req">*</span>
        </label>
        <AutoComplete
          v-model="selectedProduct"
          :suggestions="productSuggestions"
          option-label="name"
          force-selection
          dropdown
          class="w-full"
          :class="{ 'p-invalid': errors.product_id }"
          :delay="300"
          @complete="searchProducts($event.query)"
          @option-select="onProductSelect"
        >
          <template #option="{ option }">
            <div class="add-product-dialog__product-option">
              <span>{{ option.name }}</span>
              <span class="add-product-dialog__product-code">{{ option.code }}</span>
            </div>
          </template>
        </AutoComplete>
        <small v-if="errors.product_id" class="p-error">{{ errors.product_id }}</small>
      </div>

      <!-- Plan select (if product has plans) -->
      <div v-if="availablePlans.length > 0" class="add-product-dialog__field">
        <label class="add-product-dialog__label">
          {{ t('sales.deal.page.products.addDialog.fields.plan') }}
        </label>
        <Select
          v-model="form.plan_id"
          :options="availablePlans"
          option-label="name"
          option-value="id"
          show-clear
          class="w-full"
          @change="onPlanChange"
        />
      </div>

      <!-- Quantity -->
      <div class="add-product-dialog__field">
        <label class="add-product-dialog__label">
          {{ t('sales.deal.page.products.addDialog.fields.quantity') }} <span class="req">*</span>
        </label>
        <InputNumber
          v-model="form.quantity"
          :min="0.01"
          :max-fraction-digits="2"
          class="w-full"
          :class="{ 'p-invalid': errors.quantity }"
          @input="recalcPreview"
        />
        <small v-if="errors.quantity" class="p-error">{{ errors.quantity }}</small>
      </div>

      <!-- Unit price -->
      <div class="add-product-dialog__field">
        <label class="add-product-dialog__label">
          {{ t('sales.deal.page.products.addDialog.fields.unitPrice') }}
          <span v-if="priceOverridden" class="add-product-dialog__override-hint">
            <i class="pi pi-info-circle" />
            {{ t('sales.deal.page.products.addDialog.fields.unitPriceOverrideHint') }}
          </span>
        </label>
        <InputNumber
          v-model="unitPriceDisplay"
          :min="0"
          :max-fraction-digits="2"
          class="w-full"
          @input="onPriceInput"
        />
        <small class="add-product-dialog__hint">
          {{ t('sales.deal.page.products.addDialog.snapshotHint') }}
        </small>
      </div>

      <!-- Preview total -->
      <div class="add-product-dialog__preview">
        {{ t('sales.deal.page.products.addDialog.preview', { amount: previewTotal }) }}
      </div>
    </div>

    <template #footer>
      <Button
        :label="t('sales.deal.page.products.addDialog.cancel')"
        severity="secondary"
        text
        :disabled="saving"
        @click="visible = false"
      />
      <Button
        icon="pi pi-plus"
        :label="t('sales.deal.page.products.addDialog.save')"
        :loading="saving"
        :disabled="!selectedProduct || !form.quantity"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import AutoComplete from 'primevue/autocomplete'
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import { catalogApi } from '@/api/catalog'
import { useMutation } from '@/composables/async/useMutation'
import { formatCurrency, fromKopecks, toKopecks } from '@/utils/currency'
import { getValidationErrors, getApiErrorStatus, getApiErrorMessage } from '@/utils/errors'
import { useToast } from 'primevue/usetoast'
import type { ProductDto } from '@/entities/catalog'
import type { DealProductDto } from '@/entities/sales'

const props = defineProps<{
  modelValue: boolean
  dealId: number
  currency: string
  onAdd: (dealId: number, payload: {
    product_id: number
    plan_id?: number | null
    quantity: number
    unit_price?: number | null
  }) => Promise<DealProductDto>
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  added: [product: DealProductDto]
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

interface AddForm {
  product_id: number | null
  plan_id: number | null
  quantity: number
  unit_price_kopecks: number
}

const selectedProduct = ref<ProductDto | null>(null)
const productSuggestions = ref<ProductDto[]>([])
const availablePlans = ref<{ id: number; name: string }[]>([])
const priceOverridden = ref(false)

const form = ref<AddForm>({ product_id: null, plan_id: null, quantity: 1, unit_price_kopecks: 0 })
const errors = ref<Record<string, string>>({})

// Display unit_price in human-readable units (not kopecks)
const unitPriceDisplay = ref(0)

const previewTotal = computed(() =>
  formatCurrency(Math.round(toKopecks(unitPriceDisplay.value) * form.value.quantity), props.currency),
)

const mutation = useMutation<DealProductDto>()
const saving = computed(() => mutation.isPending.value)

async function searchProducts(query: string) {
  if (!query) {
    productSuggestions.value = []
    return
  }
  try {
    const res = await catalogApi.getProducts({ q: query, active_only: true, per_page: 20 })
    productSuggestions.value = res.data
  } catch {
    productSuggestions.value = []
  }
}

async function onProductSelect(event: { value: ProductDto }) {
  const product = event.value
  selectedProduct.value = product
  form.value.product_id = product.id
  form.value.plan_id = null
  priceOverridden.value = false

  // Load plans
  if (product.plans && product.plans.length > 0) {
    availablePlans.value = product.plans.map((p) => ({ id: p.id, name: p.name }))
  } else {
    try {
      const plans = await catalogApi.getProductPlans(product.id)
      availablePlans.value = plans.map((p) => ({ id: p.id, name: p.name }))
    } catch {
      availablePlans.value = []
    }
  }

  // Find price for current currency from product prices
  const price = product.prices?.find((p) => p.currency_code === props.currency && p.plan_id === null)
  if (price) {
    unitPriceDisplay.value = fromKopecks(price.amount)
    form.value.unit_price_kopecks = price.amount
  } else {
    unitPriceDisplay.value = 0
    form.value.unit_price_kopecks = 0
  }
}

function onPlanChange() {
  if (!selectedProduct.value) return
  // Find price for the selected plan
  const price = selectedProduct.value.prices?.find(
    (p) => p.currency_code === props.currency && p.plan_id === form.value.plan_id,
  )
  if (price && !priceOverridden.value) {
    unitPriceDisplay.value = fromKopecks(price.amount)
    form.value.unit_price_kopecks = price.amount
  }
}

function onPriceInput() {
  priceOverridden.value = true
  form.value.unit_price_kopecks = toKopecks(unitPriceDisplay.value)
}

function recalcPreview() {
  // Just triggers computed re-evaluation
}

async function onSubmit() {
  if (!selectedProduct.value || !form.value.quantity) return
  errors.value = {}

  try {
    const product = await mutation.run(() =>
      props.onAdd(props.dealId, {
        product_id: selectedProduct.value!.id,
        plan_id: form.value.plan_id || null,
        quantity: form.value.quantity,
        unit_price: form.value.unit_price_kopecks || null,
      }),
    )

    toast.add({
      severity: 'success',
      summary: t('sales.deal.page.products.addDialog.addSuccess'),
      life: 3000,
    })
    visible.value = false
    emit('added', product)

    // Reset
    selectedProduct.value = null
    availablePlans.value = []
    form.value = { product_id: null, plan_id: null, quantity: 1, unit_price_kopecks: 0 }
    unitPriceDisplay.value = 0
    priceOverridden.value = false
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve) {
        errors.value = {
          product_id: ve.product_id ?? '',
          quantity: ve.quantity ?? '',
        }
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
</script>

<style lang="scss" scoped>
.add-product-dialog {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-2 0;
}

.add-product-dialog__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.add-product-dialog__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
}

.add-product-dialog__product-option {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.add-product-dialog__product-code {
  font-size: $font-size-xs;
  color: $surface-400;
}

.add-product-dialog__hint {
  font-size: $font-size-xs;
  color: $surface-400;
}

.add-product-dialog__override-hint {
  font-size: $font-size-xs;
  color: var(--p-orange-500);
  margin-left: $space-2;

  i {
    margin-right: 4px;
  }
}

.add-product-dialog__preview {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-color;
  text-align: right;
  padding-top: $space-2;
  border-top: 1px solid $surface-200;
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
