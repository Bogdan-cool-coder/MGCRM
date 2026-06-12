<template>
  <tr class="deal-product-row">
    <td>
      <div class="deal-product-row__product">
        <span>{{ item.product.name }}</span>
      </div>
    </td>
    <td>{{ item.plan?.name ?? '—' }}</td>
    <td class="deal-product-row__qty">
      <template v-if="!isEditing">
        {{ item.quantity }}
      </template>
      <InputNumber
        v-else
        v-model="editQty"
        :min="0.01"
        :max-fraction-digits="2"
        style="width: 80px"
      />
    </td>
    <td class="deal-product-row__price">
      <template v-if="!isEditing">
        {{ formatCurrency(item.unit_price, currency) }}
      </template>
      <InputNumber
        v-else
        v-model="editPriceDisplay"
        :min="0"
        :max-fraction-digits="2"
        style="width: 120px"
      />
    </td>
    <td class="deal-product-row__amount">
      {{ formatCurrency(isEditing ? Math.round(toKopecks(editPriceDisplay ?? 0) * (editQty ?? 0)) : item.amount, currency) }}
    </td>
    <td class="deal-product-row__actions">
      <template v-if="!isEditing">
        <Button
          icon="pi pi-pencil"
          text
          severity="secondary"
          size="small"
          @click="startEdit"
        />
        <Button
          icon="pi pi-trash"
          text
          severity="danger"
          size="small"
          :loading="deleting"
          @click="emit('remove', item.id)"
        />
      </template>
      <template v-else>
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
      </template>
    </td>
  </tr>
</template>

<script setup lang="ts">
import { ref } from 'vue'
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
  update: [id: number, payload: { quantity?: number; unit_price?: number }]
  remove: [id: number]
}>()

const isEditing = ref(false)
const editQty = ref(props.item.quantity)
const editPriceDisplay = ref(fromKopecks(props.item.unit_price))

function startEdit() {
  editQty.value = props.item.quantity
  editPriceDisplay.value = fromKopecks(props.item.unit_price)
  isEditing.value = true
}

function submitEdit() {
  emit('update', props.item.id, {
    quantity: editQty.value,
    unit_price: toKopecks(editPriceDisplay.value ?? 0),
  })
  isEditing.value = false
}

function cancelEdit() {
  isEditing.value = false
}
</script>

<style lang="scss" scoped>
.deal-product-row {
  td {
    padding: $space-2 $space-3;
    font-size: $font-size-sm;
    color: $surface-800;
    border-bottom: 1px solid $surface-100;

    :global(.app-dark) & {
      border-bottom-color: var(--p-surface-700);
      color: var(--p-surface-100);
    }
  }
}

.deal-product-row__product {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.deal-product-row__qty,
.deal-product-row__price {
  text-align: right;
}

.deal-product-row__amount {
  text-align: right;
  font-weight: $font-weight-semibold;
  color: $primary-color;
}

.deal-product-row__actions {
  display: flex;
  gap: $space-1;
  justify-content: flex-end;
  white-space: nowrap;
}
</style>
