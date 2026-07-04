<template>
  <Dialog
    v-model:visible="visible"
    :header="t('sales.deal.page.products.addDialog.title')"
    modal
    style="width: 480px"
    :closable="!saving"
  >
    <div class="add-product-dialog">
      <!-- Row 1: Период + Валюта side-by-side -->
      <div class="add-product-dialog__row-2col">
        <!-- Период -->
        <div class="add-product-dialog__field">
          <label class="add-product-dialog__label">
            {{ t('sales.deal.page.products.addDialog.fields.period') }}
          </label>
          <Select
            v-model="form.period"
            :options="periodOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>

        <!-- Валюта -->
        <div class="add-product-dialog__field">
          <label class="add-product-dialog__label">
            {{ t('sales.deal.page.products.addDialog.fields.currency') }}
          </label>
          <Select
            v-model="form.currency"
            :options="currencyOptions"
            option-label="label"
            option-value="value"
            class="w-full"
          />
        </div>
      </div>

      <!-- Продукт: async AutoComplete -->
      <div class="add-product-dialog__field">
        <label class="add-product-dialog__label">
          {{ t('sales.deal.page.products.addDialog.fields.product') }} <span class="req">*</span>
        </label>
        <AutoComplete
          v-model="selectedProduct"
          :suggestions="productSuggestions"
          option-label="name"
          class="add-product-dialog__product-ac"
          dropdown
          complete-on-focus
          force-selection
          :invalid="!!errors.product_id"
          :placeholder="t('sales.deal.page.products.addDialog.fields.productPlaceholder')"
          :min-length="0"
          :delay="300"
          append-to="body"
          @complete="onProductComplete($event.query)"
          @option-select="onProductSelect($event.value)"
        >
          <template #option="{ option }">
            <div class="add-product-dialog__ac-option">
              <span class="add-product-dialog__ac-option-name">{{ option.name }}</span>
              <span v-if="option.code" class="add-product-dialog__ac-option-code">{{ option.code }}</span>
            </div>
          </template>
        </AutoComplete>
        <small v-if="errors.product_id" class="p-error">{{ errors.product_id }}</small>
        <!-- No-price hint shown inline below the picker -->
        <small v-if="selectedProduct && !hasPriceForCurrency" class="p-error">
          {{ t('sales.deal.page.products.addDialog.noPriceHint') }}
        </small>
      </div>

      <!-- Сумма (авто, read-only) -->
      <div class="add-product-dialog__sum-block">
        <span class="add-product-dialog__sum-label">{{ t('sales.deal.page.products.addDialog.fields.sum') }}</span>
        <span class="add-product-dialog__sum-value">{{ previewTotal }}</span>
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
        :disabled="!selectedProduct || !hasPriceForCurrency"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Button from 'primevue/button'
import AutoComplete from 'primevue/autocomplete'
import { catalogApi, type ProductListParams } from '@/api/catalog'
import { useMutation } from '@/composables/async/useMutation'
import { formatCurrency } from '@/utils/currency'
import { getValidationErrors, getApiErrorStatus, getApiErrorMessage } from '@/utils/errors'
import { useToast } from 'primevue/usetoast'
import type { ProductDto } from '@/entities/catalog'
import type { DealProductDto } from '@/entities/sales'

// Period options (месяцы + разово)
const PERIOD_OPTIONS = [
  { label: '1 мес', value: 1 },
  { label: '3 мес', value: 3 },
  { label: '6 мес', value: 6 },
  { label: '12 мес', value: 12 },
  { label: '24 мес', value: 24 },
  { label: 'Разово', value: 0 },
]

const CURRENCY_OPTIONS = [
  { label: '₸ KZT', value: 'KZT' },
  { label: '₽ RUB', value: 'RUB' },
  { label: '$ USD', value: 'USD' },
  { label: '€ EUR', value: 'EUR' },
]

const props = defineProps<{
  modelValue: boolean
  dealId: number
  currency: string
  onAdd: (dealId: number, payload: {
    product_id: number
    plan_id?: number | null
    quantity: number
    unit_price?: number | null
    currency?: string | null
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

// ── Period / currency options ─────────────────────────────────────────────────

const periodOptions = PERIOD_OPTIONS
const currencyOptions = CURRENCY_OPTIONS

// ── Form state ────────────────────────────────────────────────────────────────

interface AddForm {
  period: number   // 0 = разово, 1/3/6/12/24 = months
  currency: string
}

const form = ref<AddForm>({ period: 12, currency: props.currency || 'KZT' })
const selectedProduct = ref<ProductDto | null>(null)
const errors = ref<Record<string, string>>({})

// Sync currency from deal
watch(() => props.currency, (c) => {
  form.value.currency = c || 'KZT'
}, { immediate: true })

// ── Product async AutoComplete ─────────────────────────────────────────────────
// Empty/short query → full active list (spec §8.2: dropdown/focus shows the list);
// query ≥ 2 chars → server-side filter. AutoComplete debounces via :delay.

const productSuggestions = ref<ProductDto[]>([])
const lastProductQuery = ref('')

async function onProductComplete(query: string) {
  lastProductQuery.value = query
  try {
    const params: ProductListParams = { active_only: true, per_page: 30 }
    if (query.trim().length >= 2) params.q = query.trim()
    const res = await catalogApi.getProducts(params)
    // Out-of-order guard: ignore a stale response for an earlier keystroke.
    if (lastProductQuery.value !== query) return
    productSuggestions.value = res.data
  } catch {
    if (lastProductQuery.value === query) productSuggestions.value = []
  }
}

function onProductSelect(opt: ProductDto) {
  selectedProduct.value = opt
  errors.value = {}
}

// ── Unit price preview (product + currency, display only) ────────────────────
// Priority: base price (plan_id=null) → first plan-attached price → plan.prices.
// The actual price is snapshotted server-side; this is for the sum display only.

const unitPriceKopecks = computed((): number => {
  if (!selectedProduct.value) return 0
  const currency = form.value.currency
  const prices = selectedProduct.value.prices ?? []
  // 1. Base price (no plan)
  const base = prices.find((p) => p.currency_code === currency && p.plan_id === null)
  if (base) return base.amount
  // 2. Fallback: first plan-attached price for this currency (display estimate)
  const planPrice = prices.find((p) => p.currency_code === currency && p.plan_id !== null)
  if (planPrice) return planPrice.amount
  // 3. Check nested plan prices (product.plans[*].prices)
  const plans = selectedProduct.value.plans ?? []
  for (const plan of plans) {
    const pp = (plan.prices ?? []).find((p) => p.currency_code === currency)
    if (pp) return pp.amount
  }
  return 0
})

// Guard: can the server resolve a price for this product+currency?
// If unitPriceKopecks is 0 for a selected product the server will 422.
// Disable the Add button proactively with a hint instead.
const hasPriceForCurrency = computed((): boolean => {
  if (!selectedProduct.value) return true  // no selection yet — don't show error
  return unitPriceKopecks.value > 0
})

// ── Auto-calculated sum ────────────────────────────────────────────────────────
// period=0 → разово (quantity=1). period>0 → quantity=period (months subscription)

const quantity = computed((): number => {
  return form.value.period === 0 ? 1 : form.value.period
})

const previewTotal = computed(() => {
  const total = Math.round(unitPriceKopecks.value * quantity.value)
  return formatCurrency(total, form.value.currency)
})

// ── Submit ────────────────────────────────────────────────────────────────────

const mutation = useMutation<DealProductDto>()
const saving = computed(() => mutation.isPending.value)

async function onSubmit() {
  if (!selectedProduct.value || !hasPriceForCurrency.value) return
  errors.value = {}

  try {
    // Do NOT send unit_price in the default flow — the server snapshots the
    // catalog price authoritatively (price-tampering guard, §3). An authorized
    // override would add override_price:true + unit_price, but that path is
    // not exposed in this dialog.
    const product = await mutation.run(() =>
      props.onAdd(props.dealId, {
        product_id: selectedProduct.value!.id,
        plan_id: null,
        quantity: quantity.value,
        currency: form.value.currency || null,
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
    form.value = { period: 12, currency: props.currency || 'KZT' }
    productSuggestions.value = []
  } catch (err) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const ve = getValidationErrors(err)
      if (ve) {
        errors.value = { product_id: ve.product_id ?? '' }
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

.add-product-dialog__row-2col {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: $space-3;
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
  // theme-reactive secondary text — no dark override
}

// ── Product AutoComplete ──────────────────────────────────────────────────────

.add-product-dialog__product-ac {
  width: 100%;

  :deep(.p-autocomplete-input) {
    width: 100%;
  }
}

// Custom option template (name + code) rendered inside the teleported overlay.
.add-product-dialog__ac-option {
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.add-product-dialog__ac-option-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.add-product-dialog__ac-option-code {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

// Sum block (read-only)
.add-product-dialog__sum-block {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  background: var(--p-surface-50);
  border-radius: $radius-md;
  border: 1px solid var(--p-surface-200);

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

.add-product-dialog__sum-label {
  font-size: $font-size-sm;
  color: $surface-500;
  // $surface-500 theme-reactive muted ink (dark #647294) — no dark override
}

.add-product-dialog__sum-value {
  font-size: $font-size-lg;
  font-weight: $font-weight-bold;
  color: var(--p-primary-color);
}

.req {
  color: var(--p-red-500, #ff5a44);
}

.w-full {
  width: 100%;
}
</style>
