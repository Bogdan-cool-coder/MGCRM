<template>
  <div class="deal-product-row">
    <!-- Name + sub-line -->
    <div class="deal-product-row__desc">
      <span class="deal-product-row__name">{{ item.product.name }}</span>
      <span class="deal-product-row__sub">{{ subLine }}</span>
    </div>
    <!-- Amount right: show discounted net when deal-level discount active.
         Use item.currency (per-line) so KZT lines show ₸ even on a RUB deal. -->
    <span class="deal-product-row__amount">
      <template v-if="netAmount !== undefined && netAmount !== item.amount">
        <span class="deal-product-row__amount-original">{{ formatCurrency(item.amount, lineCurrency) }}</span>
        {{ formatCurrency(netAmount, lineCurrency) }}
      </template>
      <template v-else>
        {{ formatCurrency(item.amount, lineCurrency) }}
      </template>
    </span>
    <!-- Remove on hover -->
    <button
      class="deal-product-row__remove"
      type="button"
      :disabled="deleting"
      :title="t('common.delete')"
      @click.stop="emit('remove', item.id)"
    >
      <i :class="['pi', deleting ? 'pi-spin pi-spinner' : 'pi-times']" />
    </button>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { formatCurrency } from '@/utils/currency'
import type { DealProductDto } from '@/entities/sales'

const props = defineProps<{
  item: DealProductDto
  /** Deal-level currency fallback (used only when item.currency is absent). */
  currency: string
  saving?: boolean
  deleting?: boolean
  /** Net amount after deal-level discount (kopecks). When provided, shown instead of item.amount. */
  netAmount?: number
}>()

const emit = defineEmits<{
  remove: [id: number]
}>()

const { t } = useI18n()

// ── Per-line currency (falls back to deal-level if not set) ───────────────────
const lineCurrency = computed(() => props.item.currency || props.currency)

// ── Sub-line: "{период} × {цена}" ────────────────────────────────────────────

function getPeriodLabel(item: DealProductDto): string {
  if (item.plan?.name) {
    const m = item.plan.name.match(/(\d+)\s*мес/i)
    if (m) return `${m[1]} мес`
    if (/разово|one.time/i.test(item.plan.name)) return 'разово'
    return item.plan.name
  }
  if (item.quantity && Number.isInteger(item.quantity) && [1, 3, 6, 12, 24].includes(item.quantity)) {
    return `${item.quantity} мес`
  }
  return 'разово'
}

const subLine = computed(() => {
  const period = getPeriodLabel(props.item)
  const price = formatCurrency(props.item.unit_price, lineCurrency.value)
  return `${period} × ${price}`
})
</script>

<style lang="scss" scoped>
.deal-product-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-bottom: 1px solid var(--p-surface-100);
  min-height: 36px;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }

  &:last-child {
    border-bottom: none;
  }

  &:hover .deal-product-row__remove {
    opacity: 1;
  }
}

.deal-product-row__desc {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 1px;
  min-width: 0;
}

.deal-product-row__name {
  font-size: $font-size-sm;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.deal-product-row__sub {
  font-size: $font-size-2xs;
  color: $surface-500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.deal-product-row__amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  flex-shrink: 0;
  text-align: right;
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  gap: 1px;
}

.deal-product-row__amount-original {
  font-size: $font-size-2xs;
  font-weight: $font-weight-normal;
  color: $surface-400;
  text-decoration: line-through;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.deal-product-row__remove {
  flex-shrink: 0;
  background: none;
  border: none;
  cursor: pointer;
  color: $surface-400;
  padding: 2px;
  opacity: 0;
  transition: opacity var(--app-transition-fast), color var(--app-transition-fast);
  display: flex;
  align-items: center;

  &:hover:not(:disabled) {
    color: var(--p-red-500);
  }

  &:disabled {
    opacity: 0.4;
    cursor: not-allowed;
  }

  .pi {
    font-size: $font-size-xs;
  }
}
</style>
