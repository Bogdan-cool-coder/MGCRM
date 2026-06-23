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

      <!-- Продукт: async SearchPicker -->
      <div class="add-product-dialog__field">
        <label class="add-product-dialog__label">
          {{ t('sales.deal.page.products.addDialog.fields.product') }} <span class="req">*</span>
        </label>
        <div
          class="add-product-dialog__product-picker"
          :class="{ 'add-product-dialog__product-picker--open': productPickerOpen, 'p-invalid': !!errors.product_id }"
        >
          <!-- Trigger -->
          <button
            type="button"
            class="add-product-dialog__picker-trigger"
            @click="openProductPicker"
          >
            <span class="add-product-dialog__picker-value">
              {{ selectedProduct ? selectedProduct.name : t('sales.deal.page.products.addDialog.fields.productPlaceholder') }}
            </span>
            <i class="pi pi-chevron-down add-product-dialog__picker-chevron" />
          </button>

          <!-- Search popover -->
          <div
            v-if="productPickerOpen"
            ref="productPopoverRef"
            class="add-product-dialog__picker-popover"
          >
            <div class="add-product-dialog__picker-search">
              <i class="pi pi-search add-product-dialog__picker-search-icon" />
              <input
                ref="productSearchRef"
                v-model="productQuery"
                class="add-product-dialog__picker-search-input"
                :placeholder="t('common.search_placeholder')"
                @input="onProductSearch"
              />
            </div>
            <div class="add-product-dialog__picker-options">
              <div
                v-for="opt in productSuggestions"
                :key="opt.id"
                class="add-product-dialog__picker-option"
                :class="{ 'add-product-dialog__picker-option--selected': selectedProduct?.id === opt.id }"
                @click="onProductSelect(opt)"
              >
                <i
                  v-if="selectedProduct?.id === opt.id"
                  class="pi pi-check add-product-dialog__picker-check"
                />
                <div class="add-product-dialog__picker-option-content">
                  <span class="add-product-dialog__picker-option-name">{{ opt.name }}</span>
                  <span v-if="opt.code" class="add-product-dialog__picker-option-code">{{ opt.code }}</span>
                </div>
              </div>
              <div v-if="productSuggestions.length === 0" class="add-product-dialog__picker-empty">
                {{ t('common.no_results') }}
              </div>
            </div>
          </div>
        </div>
        <small v-if="errors.product_id" class="p-error">{{ errors.product_id }}</small>
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
        :disabled="!selectedProduct"
        @click="onSubmit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Select from 'primevue/select'
import Button from 'primevue/button'
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

// ── Product async SearchPicker ─────────────────────────────────────────────────

const productPickerOpen = ref(false)
const productQuery = ref('')
const productSuggestions = ref<ProductDto[]>([])
const productSearchRef = ref<HTMLInputElement | null>(null)
const productPopoverRef = ref<HTMLElement | null>(null)

let searchTimer: ReturnType<typeof setTimeout> | null = null

async function loadProducts(query: string) {
  try {
    const params: ProductListParams = { active_only: true, per_page: 30 }
    if (query.trim().length >= 2) params.q = query.trim()
    const res = await catalogApi.getProducts(params)
    productSuggestions.value = res.data
  } catch {
    productSuggestions.value = []
  }
}

function openProductPicker() {
  productPickerOpen.value = !productPickerOpen.value
  if (productPickerOpen.value) {
    productQuery.value = ''
    // Load full list immediately on open (spec §8.2: clicking shows the list)
    void loadProducts('')
    nextTick(() => productSearchRef.value?.focus())
  }
}

function onProductSearch() {
  if (searchTimer) clearTimeout(searchTimer)
  // Load instantly with no filter when query is short; debounce typed searches
  if (productQuery.value.trim().length < 2) {
    searchTimer = setTimeout(() => void loadProducts(''), 150)
    return
  }
  searchTimer = setTimeout(() => void loadProducts(productQuery.value), 300)
}

function onProductSelect(opt: ProductDto) {
  selectedProduct.value = opt
  productPickerOpen.value = false
  errors.value = {}
}

// Click outside to close product picker
function onDocClick(e: MouseEvent) {
  const pop = productPopoverRef.value
  if (pop && !pop.contains(e.target as Node)) {
    const btn = (e.target as HTMLElement).closest('.add-product-dialog__picker-trigger')
    if (!btn) {
      productPickerOpen.value = false
    }
  }
}

onMounted(() => {
  document.addEventListener('click', onDocClick, true)
})

onUnmounted(() => {
  document.removeEventListener('click', onDocClick, true)
  if (searchTimer) clearTimeout(searchTimer)
})

// ── Unit price (from product + currency) ─────────────────────────────────────

const unitPriceKopecks = computed((): number => {
  if (!selectedProduct.value) return 0
  // Find matching price for selected currency
  const price = selectedProduct.value.prices?.find(
    (p) => p.currency_code === form.value.currency && p.plan_id === null,
  )
  return price?.amount ?? 0
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
  if (!selectedProduct.value) return
  errors.value = {}

  try {
    const product = await mutation.run(() =>
      props.onAdd(props.dealId, {
        product_id: selectedProduct.value!.id,
        plan_id: null,
        quantity: quantity.value,
        unit_price: unitPriceKopecks.value || null,
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
    productQuery.value = ''
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

  .app-dark & {
    color: var(--p-surface-200);
  }
}

// ── Product SearchPicker ──────────────────────────────────────────────────────

.add-product-dialog__product-picker {
  position: relative;
}

.add-product-dialog__picker-trigger {
  display: flex;
  align-items: center;
  gap: $space-1;
  width: 100%;
  padding: 6px $space-3;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  background: var(--p-card-background);
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  text-align: left;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-200);
  }

  &:hover {
    border-color: var(--p-primary-400);
  }

  .add-product-dialog__product-picker--open & {
    border-color: var(--p-primary-color);
  }

  .p-invalid & {
    border-color: var(--p-red-400);
  }
}

.add-product-dialog__picker-value {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.add-product-dialog__picker-chevron {
  font-size: $font-size-3xs;
  color: $surface-400;
  flex-shrink: 0;
  transition: transform var(--app-transition-fast);

  .add-product-dialog__product-picker--open & {
    transform: rotate(180deg);
  }
}

.add-product-dialog__picker-popover {
  position: absolute;
  top: calc(100% + 4px);
  left: 0;
  right: 0;
  z-index: 300;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.add-product-dialog__picker-search {
  display: flex;
  align-items: center;
  gap: $space-1;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.add-product-dialog__picker-search-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.add-product-dialog__picker-search-input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-800;
  min-width: 0;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &::placeholder {
    color: $surface-400;
  }
}

.add-product-dialog__picker-options {
  max-height: 200px;
  overflow-y: auto;
  padding: $space-1;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

.add-product-dialog__picker-option {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-radius: $radius-sm;
  cursor: pointer;
  font-size: $font-size-sm;
  color: $surface-700;
  transition: background var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-200);
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-100);
    }
  }

  &--selected {
    background: var(--p-primary-50);
    color: var(--p-primary-color);

    .app-dark & {
      background: var(--p-primary-950);
      color: var(--p-primary-300);
    }
  }
}

.add-product-dialog__picker-check {
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  flex-shrink: 0;
}

.add-product-dialog__picker-option-content {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: 1px;
}

.add-product-dialog__picker-option-name {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.add-product-dialog__picker-option-code {
  font-size: $font-size-xs;
  color: $surface-400;
}

.add-product-dialog__picker-hint,
.add-product-dialog__picker-empty {
  padding: $space-3;
  text-align: center;
  font-size: $font-size-sm;
  color: $surface-400;
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

  .app-dark & {
    color: var(--p-surface-400);
  }
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
