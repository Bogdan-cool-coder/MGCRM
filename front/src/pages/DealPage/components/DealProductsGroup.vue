<template>
  <DealFieldGroup
    :title="t('sales.deal.info.groups.products')"
    icon="pi-shopping-cart"
    group-key="products"
    :accent="true"
    :count="items.length"
    :total-label="items.length > 0 ? formatCurrency(totalAmount, currency) : undefined"
  >
    <template #header-action>
      <Button
        :label="t('sales.deal.info.products.add')"
        icon="pi pi-plus"
        size="small"
        text
        severity="secondary"
        @click="emit('addProduct')"
      />
    </template>

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

    <!-- Compact list (no thead) -->
    <template v-else>
      <div class="deal-products-group__list">
        <DealProductRow
          v-for="item in items"
          :key="item.id"
          :item="item"
          :currency="currency"
          :saving="updatingId === item.id"
          :deleting="deletingId === item.id"
          @update="(id, payload) => emit('updateItem', id, payload)"
          @remove="(id) => emit('removeItem', id)"
        />
      </div>

      <!-- Net total + discount summary shown below the list -->
      <div class="deal-products-group__summary">
        <template v-if="totalDiscount > 0">
          <div class="deal-products-group__summary-row deal-products-group__summary-row--discount">
            <span class="deal-products-group__summary-label">{{ t('sales.deal.info.products.discount') }}</span>
            <span class="deal-products-group__summary-value deal-products-group__summary-value--discount">
              −{{ formatCurrency(totalDiscount, currency) }}
            </span>
          </div>
        </template>
        <div class="deal-products-group__summary-row deal-products-group__summary-row--total">
          <span class="deal-products-group__summary-label">{{ t('sales.deal.info.products.total') }}</span>
          <span class="deal-products-group__summary-value deal-products-group__summary-value--total">
            {{ formatCurrency(totalAmount, currency) }}
          </span>
        </div>
      </div>
    </template>
  </DealFieldGroup>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
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
}>()

const emit = defineEmits<{
  addProduct: []
  updateItem: [id: number, payload: { quantity?: number; unit_price?: number; discount?: number }]
  removeItem: [id: number]
  amountChanged: [newTotal: number]
}>()

const { t } = useI18n()

const totalAmount = computed(() =>
  props.items.reduce((sum, item) => sum + item.amount, 0),
)

const totalDiscount = computed(() =>
  props.items.reduce((sum, item) => sum + (item.discount ?? 0), 0),
)
</script>

<style lang="scss" scoped>
.deal-products-group__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-4 $space-4 $space-3;
  text-align: center;
}

.deal-products-group__empty-icon {
  font-size: 1.5rem;
  color: $surface-300;
}

.deal-products-group__empty-text {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0;
}

.deal-products-group__list {
  // compact — product rows render as compact rows
}

.deal-products-group__summary {
  border-top: 1px solid var(--p-surface-200);
  background: var(--p-surface-50);

  .app-dark & {
    border-top-color: var(--p-surface-700);
    background: var(--p-surface-800);
  }
}

.deal-products-group__summary-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-1 $space-4;

  &--total {
    padding-top: $space-2;
    padding-bottom: $space-2;
  }

  &--discount {
    padding-top: $space-2;
    padding-bottom: 0;
  }
}

.deal-products-group__summary-label {
  font-size: $font-size-xs;
  color: $surface-500;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.deal-products-group__summary-value {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;

  &--total {
    color: var(--p-primary-color);
  }

  &--discount {
    color: var(--p-green-600);
    font-weight: $font-weight-medium;

    .app-dark & {
      color: var(--p-green-400);
    }
  }
}
</style>
