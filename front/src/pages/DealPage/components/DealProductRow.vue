<template>
  <div class="deal-product-row" :class="{ 'deal-product-row--editing': isEditing }">
    <!-- View mode -->
    <template v-if="!isEditing">
      <div class="deal-product-row__desc">
        {{ item.product.name }}
        <span v-if="item.plan" class="deal-product-row__plan"> · {{ item.plan.name }}</span>
        <span class="deal-product-row__qty-inline"> × {{ item.quantity }}</span>
      </div>
      <div class="deal-product-row__right">
        <span v-if="item.discount > 0" class="deal-product-row__discount">
          −{{ formatCurrency(item.discount, currency) }}
        </span>
        <span class="deal-product-row__amount">
          {{ formatCurrency(item.amount, currency) }}
        </span>
      </div>
      <div class="deal-product-row__actions">
        <Button
          icon="pi pi-pencil"
          text
          severity="secondary"
          size="small"
          class="deal-product-row__action-btn"
          @click="startEdit"
        />
        <Button
          icon="pi pi-trash"
          text
          severity="danger"
          size="small"
          :loading="deleting"
          class="deal-product-row__action-btn"
          @click="emit('remove', item.id)"
        />
      </div>
    </template>

    <!-- Edit mode -->
    <template v-else>
      <div class="deal-product-row__edit-fields">
        <InputNumber
          v-model="editQty"
          :min="0.01"
          :max-fraction-digits="2"
          :placeholder="'×'"
          class="deal-product-row__edit-qty"
        />
        <InputNumber
          v-model="editPriceDisplay"
          :min="0"
          :max-fraction-digits="2"
          class="deal-product-row__edit-price"
        />
        <InputNumber
          v-model="editDiscountDisplay"
          :min="0"
          :max-fraction-digits="2"
          :placeholder="t('sales.deal.info.products.discount')"
          class="deal-product-row__edit-discount"
        />
        <span class="deal-product-row__edit-total">
          {{ formatCurrency(Math.max(0, Math.round(toKopecks(editPriceDisplay ?? 0) * (editQty ?? 0)) - toKopecks(editDiscountDisplay ?? 0)), currency) }}
        </span>
      </div>
      <div class="deal-product-row__edit-actions">
        <Button
          icon="pi pi-check"
          text
          severity="success"
          size="small"
          :loading="saving"
          @click="submitEdit"
        />
        <Button
          icon="pi pi-times"
          text
          severity="secondary"
          size="small"
          @click="cancelEdit"
        />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import { formatCurrency, fromKopecks, toKopecks } from '@/utils/currency'
import type { DealProductDto } from '@/entities/sales'

const props = defineProps<{
  item: DealProductDto
  currency: string
  saving?: boolean
  deleting?: boolean
}>()

const emit = defineEmits<{
  update: [id: number, payload: { quantity?: number; unit_price?: number; discount?: number }]
  remove: [id: number]
}>()

const { t } = useI18n()

const isEditing = ref(false)
const editQty = ref(props.item.quantity)
const editPriceDisplay = ref(fromKopecks(props.item.unit_price))
const editDiscountDisplay = ref(fromKopecks(props.item.discount ?? 0))

function startEdit() {
  editQty.value = props.item.quantity
  editPriceDisplay.value = fromKopecks(props.item.unit_price)
  editDiscountDisplay.value = fromKopecks(props.item.discount ?? 0)
  isEditing.value = true
}

function submitEdit() {
  emit('update', props.item.id, {
    quantity: editQty.value,
    unit_price: toKopecks(editPriceDisplay.value ?? 0),
    discount: toKopecks(editDiscountDisplay.value ?? 0),
  })
  isEditing.value = false
}

function cancelEdit() {
  isEditing.value = false
}
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

  // Show action buttons on hover
  &:hover .deal-product-row__actions {
    opacity: 1;
  }
}

.deal-product-row__desc {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-800;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.deal-product-row__plan {
  color: $surface-500;
}

.deal-product-row__qty-inline {
  color: $surface-500;
}

.deal-product-row__right {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
  flex-shrink: 0;
  min-width: 80px;
}

.deal-product-row__discount {
  font-size: $font-size-xs;
  color: var(--p-green-600);
  line-height: 1.2;

  .app-dark & {
    color: var(--p-green-400);
  }
}

.deal-product-row__amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  text-align: right;
}

.deal-product-row__actions {
  display: flex;
  gap: $space-1;
  flex-shrink: 0;
  opacity: 0;
  transition: opacity 0.15s;
}

.deal-product-row__action-btn {
  // inherits from PrimeVue Button
}

// Edit mode
.deal-product-row--editing {
  flex-wrap: wrap;
  gap: $space-2;
}

.deal-product-row__edit-fields {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex: 1;
  min-width: 0;
}

.deal-product-row__edit-qty {
  width: 80px;
  flex-shrink: 0;
}

.deal-product-row__edit-price {
  width: 100px;
  flex-shrink: 0;
}

.deal-product-row__edit-discount {
  width: 90px;
  flex-shrink: 0;
}

.deal-product-row__edit-total {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-primary-color);
  white-space: nowrap;
}

.deal-product-row__edit-actions {
  display: flex;
  gap: $space-1;
  flex-shrink: 0;
}
</style>
