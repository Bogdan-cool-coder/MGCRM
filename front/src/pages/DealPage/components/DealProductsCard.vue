<template>
  <div class="deal-products-card">
    <div class="deal-products-card__header">
      <h3 class="deal-products-card__title">{{ t('sales.deal.page.products.sectionTitle') }}</h3>
      <Button
        icon="pi pi-plus"
        :label="t('sales.deal.page.products.addProduct')"
        severity="secondary"
        outlined
        size="small"
        @click="emit('addProduct')"
      />
    </div>

    <!-- Empty state -->
    <div v-if="!loading && items.length === 0" class="deal-products-card__empty">
      <i class="pi pi-shopping-cart deal-products-card__empty-icon" />
      <p class="deal-products-card__empty-title">{{ t('sales.deal.page.products.empty.title') }}</p>
      <p class="deal-products-card__empty-subtitle">{{ t('sales.deal.page.products.empty.subtitle') }}</p>
    </div>

    <!-- Products table -->
    <table v-else class="deal-products-card__table">
      <thead>
        <tr>
          <th>{{ t('sales.deal.page.products.columns.product') }}</th>
          <th>{{ t('sales.deal.page.products.columns.plan') }}</th>
          <th class="text-right">{{ t('sales.deal.page.products.columns.quantity') }}</th>
          <th class="text-right">{{ t('sales.deal.page.products.columns.unitPrice') }}</th>
          <th class="text-right">{{ t('sales.deal.page.products.columns.amount') }}</th>
          <th />
        </tr>
      </thead>
      <tbody>
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
      </tbody>
      <tfoot>
        <tr class="deal-products-card__total-row">
          <td colspan="4" class="deal-products-card__total-label">
            {{ t('sales.deal.page.products.total') }}
          </td>
          <td class="deal-products-card__total-amount text-right">
            {{ formatCurrency(totalAmount, currency) }}
          </td>
          <td />
        </tr>
      </tfoot>
    </table>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
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
  updateItem: [id: number, payload: { quantity?: number; unit_price?: number }]
  removeItem: [id: number]
}>()

const { t } = useI18n()

const totalAmount = computed(() =>
  props.items.reduce((sum, item) => sum + item.amount, 0),
)
</script>

<style lang="scss" scoped>
.deal-products-card {
  background: $surface-card;
  border-radius: $radius-md;
  border: 1px solid $surface-200;
  overflow: hidden;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.deal-products-card__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-100;

  :global(.app-dark) & {
    border-bottom-color: var(--p-surface-700);
  }
}

.deal-products-card__title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  margin: 0;
}

.deal-products-card__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6;
  text-align: center;
}

.deal-products-card__empty-icon {
  font-size: 2rem;
  color: $surface-400;
}

.deal-products-card__empty-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
}

.deal-products-card__empty-subtitle {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0;
}

.deal-products-card__table {
  width: 100%;
  border-collapse: collapse;

  th {
    padding: $space-2 $space-3;
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    color: $surface-500;
    text-transform: uppercase;
    letter-spacing: 0.05em;
    text-align: left;
    background: $surface-50;
    border-bottom: 1px solid $surface-200;

    :global(.app-dark) & {
      background: var(--p-surface-800);
      border-bottom-color: var(--p-surface-700);
      color: var(--p-surface-400);
    }
  }

  .text-right {
    text-align: right;
  }
}

.deal-products-card__total-row {
  background: $surface-50;

  :global(.app-dark) & {
    background: var(--p-surface-800);
  }

  td {
    padding: $space-2 $space-3;
    font-size: $font-size-sm;
    border-top: 2px solid $surface-200;

    :global(.app-dark) & {
      border-top-color: var(--p-surface-700);
    }
  }
}

.deal-products-card__total-label {
  font-weight: $font-weight-semibold;
  color: $surface-600;
  text-align: right;
}

.deal-products-card__total-amount {
  font-weight: $font-weight-bold;
  // var(--p-primary-color) is reactive: light → {primary.900}=#172747, dark → {primary.400}=#6f87bc
  color: var(--p-primary-color);
  font-size: $font-size-base;
}
</style>
