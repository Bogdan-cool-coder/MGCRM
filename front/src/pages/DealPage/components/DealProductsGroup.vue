<template>
  <DealFieldGroup
    :title="t('sales.deal.info.groups.products')"
    icon="pi-shopping-cart"
    group-key="products"
    :accent="true"
    :count="null"
    :total-label="items.length > 0 ? totalLabel : undefined"
  >
    <template #header-action>
      <button class="deal-products-group__add-btn" type="button" @click="emit('addProduct')">
        {{ t('sales.deal.info.products.add') }}
      </button>
    </template>

    <!-- License segmented + Discount row -->
    <div class="deal-products-group__control-row">
      <!-- License segmented control -->
      <div class="deal-products-group__segmented">
        <button
          type="button"
          class="deal-products-group__seg-btn"
          :class="{ 'deal-products-group__seg-btn--active': !localPerpetual }"
          @click="onSegmentClick(false)"
        >
          {{ t('sales.deal.license.subscription') }}
        </button>
        <button
          type="button"
          class="deal-products-group__seg-btn"
          :class="{ 'deal-products-group__seg-btn--active': localPerpetual }"
          @click="onSegmentClick(true)"
        >
          {{ t('sales.deal.license.perpetual') }}
        </button>
      </div>
      <!-- Discount: "Скидка [input] %" — spec §3 -->
      <span class="deal-products-group__discount-label">{{ t('sales.deal.info.products.discountLabel') }}</span>
      <div class="deal-products-group__discount-field">
        <InputNumber
          v-model="localDiscount"
          :min="0"
          :max="50"
          :max-fraction-digits="0"
          class="deal-products-group__discount-input"
          :placeholder="'0'"
          @update:model-value="onDiscountInput"
          @blur="onDiscountBlur"
        />
        <span class="deal-products-group__discount-pct">%</span>
      </div>
    </div>

    <!-- Empty state -->
    <div v-if="!loading && items.length === 0" class="deal-products-group__empty">
      <i class="pi pi-shopping-cart deal-products-group__empty-icon" />
      <p class="deal-products-group__empty-text">{{ t('sales.deal.info.products.empty') }}</p>
      <Button
        :label="t('sales.deal.info.products.add')"
        icon="pi pi-plus"
        size="small"
        severity="secondary"
        outlined
        @click="emit('addProduct')"
      />
    </div>

    <!-- Product rows -->
    <div v-else class="deal-products-group__list">
      <DealProductRow
        v-for="item in items"
        :key="item.id"
        :item="item"
        :currency="currency"
        :saving="updatingId === item.id"
        :deleting="deletingId === item.id"
        :net-amount="discountedMap[item.id]"
        @remove="(id) => emit('removeItem', id)"
      />
    </div>
  </DealFieldGroup>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import DealFieldGroup from './DealFieldGroup.vue'
import DealProductRow from './DealProductRow.vue'
import { formatCurrency } from '@/utils/currency'
import type { DealProductDto } from '@/entities/sales'

const props = defineProps<{
  items: DealProductDto[]
  currency: string
  loading?: boolean
  updatingId?: number | null
  deletingId?: number | null
  dealAmount: number
  amountLocked: boolean
  perpetualLicense: boolean
  perpetualSaving: boolean
  lockSaving: boolean
  /** Deal-level discount percent (0..50). Bound to deal.discount_percent. */
  discountPercent: number
  /** Products net total after deal-level discount (kopecks), from SHOW endpoint. */
  productsNetTotal?: number
  /** Per-line discounted amounts from SHOW endpoint — keyed by dealProductId. */
  productsDiscounted?: Array<{ id: number; net_amount: number }>
}>()

const emit = defineEmits<{
  addProduct: []
  removeItem: [id: number]
  togglePerpetual: [newValue: boolean]
  updateDiscount: [percent: number]
}>()

const { t } = useI18n()

// ── Local reactive copy of perpetual_license ──────────────────────────────────

const localPerpetual = ref(props.perpetualLicense)

watch(() => props.perpetualLicense, (v) => {
  localPerpetual.value = v
})

// ── Discount% (deal-level, 0..50) ────────────────────────────────────────────

const localDiscount = ref<number | null>(props.discountPercent ?? 0)

watch(
  () => props.discountPercent,
  (v) => {
    // Sync from parent (e.g. after reload). Guard to avoid echo-cycle.
    const clamped = Math.min(50, Math.max(0, v ?? 0))
    if (localDiscount.value !== clamped) {
      localDiscount.value = clamped
    }
  },
)

function clampDiscount(v: number | null): number {
  if (v === null || isNaN(v)) return 0
  return Math.min(50, Math.max(0, Math.round(v)))
}

function onDiscountInput(v: number | null) {
  localDiscount.value = clampDiscount(v)
}

function onDiscountBlur() {
  const clamped = clampDiscount(localDiscount.value)
  localDiscount.value = clamped
  emit('updateDiscount', clamped)
}

// ── Total label for header ────────────────────────────────────────────────────

const totalAmount = computed(() =>
  props.items.reduce((sum, item) => sum + item.amount, 0),
)

/** Net total: prefer backend-computed value when deal-level discount is active. */
const displayTotal = computed(() => {
  if (props.amountLocked) return props.dealAmount
  // Use backend net total when discount is active and backend has returned it
  if ((localDiscount.value ?? 0) > 0 && props.productsNetTotal !== undefined) {
    return props.productsNetTotal
  }
  return totalAmount.value
})

/** Map dealProductId → net_amount for discounted row rendering. */
const discountedMap = computed<Record<number, number>>(() => {
  if (!props.productsDiscounted?.length) return {}
  const m: Record<number, number> = {}
  for (const entry of props.productsDiscounted) {
    m[entry.id] = entry.net_amount
  }
  return m
})

// Period label from plan name (e.g. "12 мес")
function getPeriodLabel(item: DealProductDto): string {
  if (item.plan?.name) {
    // Try to extract numeric months or "разово" from plan name
    const m = item.plan.name.match(/(\d+)\s*мес/i)
    if (m) return `${m[1]} мес`
    if (/разово|one.time/i.test(item.plan.name)) return 'разово'
    return item.plan.name
  }
  // Fallback: use quantity as period if integer
  if (item.quantity && Number.isInteger(item.quantity) && item.quantity > 0) {
    if ([1, 3, 6, 12, 24].includes(item.quantity)) return `${item.quantity} мес`
  }
  return 'разово'
}

// Get dominant period for header label
const headerPeriod = computed(() => {
  if (!props.items.length) return null
  const periods = props.items.map(getPeriodLabel)
  // Most common period
  const counts: Record<string, number> = {}
  for (const p of periods) counts[p] = (counts[p] ?? 0) + 1
  return Object.entries(counts).sort((a, b) => b[1] - a[1])[0]?.[0] ?? null
})

const totalLabel = computed(() => {
  const amt = formatCurrency(displayTotal.value, props.currency)
  const period = headerPeriod.value
  return period && period !== 'разово' ? `${amt} · ${period}` : amt
})

// ── Segment click (perpetual toggle with confirm) ─────────────────────────────

function onSegmentClick(perpetual: boolean) {
  if (localPerpetual.value === perpetual) return
  localPerpetual.value = perpetual
  emit('togglePerpetual', perpetual)
}

// Expose for collapse/expand via parent
const groupRef = ref<InstanceType<typeof DealFieldGroup> | null>(null)
function collapse() { groupRef.value?.collapse?.() }
function expand() { groupRef.value?.expand?.() }
defineExpose({ collapse, expand })
</script>

<style lang="scss" scoped>
// ── Add button in header ──────────────────────────────────────────────────────

.deal-products-group__add-btn {
  background: none;
  border: none;
  cursor: pointer;
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  font-weight: $font-weight-semibold;
  padding: 0 $space-1;

  &:hover {
    text-decoration: underline;
  }
}

// ── Control row (segmented + discount) ──────────────────────────────────────────

.deal-products-group__control-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

// ── Segmented control ─────────────────────────────────────────────────────────

.deal-products-group__segmented {
  display: flex;
  align-items: center;
  padding: 3px;
  border-radius: $radius-sm;
  background: var(--p-surface-100);
  gap: 0;

  .app-dark & {
    background: var(--p-surface-200);
  }
}

.deal-products-group__seg-btn {
  padding: 3px 10px;
  border: none;
  border-radius: $radius-sm;
  cursor: pointer;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  background: transparent;
  color: $surface-600;
  transition: all var(--app-transition-fast);
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-300);
  }

  &--active {
    background: var(--p-card-background);
    box-shadow: $shadow-sm;
    color: var(--p-primary-color);
    font-weight: $font-weight-semibold;
  }
}

// ── Dark active segment override (higher specificity than .deal-products-group__seg-btn .app-dark &) ──
// In dark mode the track bg = surface-200 (#616263). The active chip must be clearly lighter.
// surface-900 = #F9FAFB (near-white in inverted dark scale) mirrors the light-theme "white chip" look.
// primary-900 (navy) on near-white gives 16:1 contrast — WAY above WCAG AA.
// Selector specificity: 2 class + element-descendant context beats the 1-class + .app-dark context above.
.deal-products-group__segmented .deal-products-group__seg-btn--active {
  .app-dark & {
    background: var(--p-surface-900);
    color: $primary-900;
    box-shadow: $shadow-sm;
  }
}

// ── Discount field ────────────────────────────────────────────────────────────

.deal-products-group__discount-label {
  font-size: $font-size-xs;
  color: $surface-500;
  white-space: nowrap;
  margin-left: auto; // pushes "Скидка [input] %" group to right

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.deal-products-group__discount-field {
  display: flex;
  align-items: center;
  gap: 2px;
}

.deal-products-group__discount-input {
  :deep(.p-inputnumber-input) {
    width: 52px;
    font-size: $font-size-xs;
    padding: 3px 6px;
    text-align: right;
  }
}

.deal-products-group__discount-pct {
  font-size: $font-size-xs;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// ── Empty state ───────────────────────────────────────────────────────────────

.deal-products-group__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4 $space-4 $space-3;
  text-align: center;
}

.deal-products-group__empty-icon {
  font-size: $font-size-2xl;
  color: $surface-300;
}

.deal-products-group__empty-text {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0;
}

// ── Product list ──────────────────────────────────────────────────────────────

.deal-products-group__list {
  display: flex;
  flex-direction: column;
}
</style>
