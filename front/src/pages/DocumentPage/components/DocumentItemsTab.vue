<template>
  <div class="items-tab">
    <!-- Loading -->
    <div v-if="loading">
      <Skeleton height="40px" class="mb-2" v-for="i in 3" :key="i" />
    </div>

    <template v-else>
      <DataTable
        :value="items"
        class="items-tab__table"
        size="small"
        row-hover
      >
        <Column style="width: 40px">
          <template #body="{ index }">{{ index + 1 }}</template>
        </Column>
        <Column :header="t('documents.items.product')">
          <template #body="{ data }">{{ data.product_name }}</template>
        </Column>
        <Column :header="t('documents.items.qty')" style="width: 120px">
          <template #body="{ data }">
            <InputNumber
              v-if="canEdit"
              v-model="data.qty"
              :min="1"
              :max="120"
              :use-grouping="false"
              class="items-tab__qty-input"
              @blur="updateItem(data.id, data.qty)"
            />
            <span v-else>{{ data.qty }}</span>
          </template>
        </Column>
        <Column :header="t('documents.items.price')" style="width: 140px">
          <template #body="{ data }">
            {{ formatMoney(data.unit_price) }}
          </template>
        </Column>
        <Column :header="t('documents.items.total')" style="width: 140px">
          <template #body="{ data }">
            <strong>{{ formatMoney(data.line_total) }}</strong>
          </template>
        </Column>
        <Column v-if="canEdit" style="width: 60px">
          <template #body="{ data }">
            <Button
              icon="pi pi-trash"
              text
              severity="danger"
              size="small"
              @click="removeItem(data.id)"
            />
          </template>
        </Column>

        <template #empty>
          <div class="items-tab__empty">
            <i class="pi pi-list" />
            <span>{{ t('documents.items.empty') }}</span>
          </div>
        </template>
      </DataTable>

      <!-- Add row -->
      <div v-if="canEdit" class="mt-3">
        <Button
          :label="t('documents.items.add')"
          icon="pi pi-plus"
          severity="secondary"
          text
          @click="addDialogVisible = true"
        />
      </div>

      <!-- Summary -->
      <div class="items-tab__summary mt-3">
        <div class="row justify-content-end">
          <div class="col-md-5">
            <table class="items-tab__summary-table w-100">
              <tr>
                <td class="text-secondary">{{ t('documents.items.currency') }}:</td>
                <td class="text-end">
                  <Select
                    v-if="canEdit"
                    v-model="currency"
                    :options="currencyOptions"
                    class="items-tab__currency-select"
                    @update:model-value="saveCurrency"
                  />
                  <span v-else>{{ currency ?? '—' }}</span>
                </td>
              </tr>
              <tr>
                <td class="text-secondary">{{ t('documents.items.subtotal') }}:</td>
                <td class="text-end">{{ formatMoney(subtotal) }}</td>
              </tr>
              <tr v-if="discountPct && discountPct > 0">
                <td class="text-secondary">{{ t('documents.items.discount') }} {{ discountPct }}%:</td>
                <td class="text-end text-danger">-{{ formatMoney(discountAmount) }}</td>
              </tr>
              <tr class="items-tab__total-row">
                <td class="fw-bold">{{ t('documents.items.toPay') }}:</td>
                <td class="text-end fw-bold">{{ formatMoney(totalAmount) }}</td>
              </tr>
            </table>
          </div>
        </div>
      </div>
    </template>

    <!-- Add item dialog -->
    <Dialog
      v-model:visible="addDialogVisible"
      :header="t('documents.items.add')"
      modal
      :style="{ width: '28rem' }"
    >
      <div class="mb-3">
        <label class="items-tab__label">{{ t('documents.items.product') }} *</label>
        <Select
          v-model="newItem.product_id"
          :options="productOptions"
          option-label="name"
          option-value="id"
          class="w-100 mt-1"
          :placeholder="t('documents.items.product')"
        />
      </div>
      <div class="mb-3">
        <label class="items-tab__label">{{ t('documents.items.qty') }} *</label>
        <InputNumber v-model="newItem.qty" :min="1" class="w-100 mt-1" />
      </div>
      <template #footer>
        <Button
          :label="t('common.cancel')"
          severity="secondary"
          text
          @click="addDialogVisible = false"
        />
        <Button
          :label="t('common.create')"
          :loading="addingItem"
          :disabled="!newItem.product_id"
          @click="submitAddItem"
        />
      </template>
    </Dialog>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Button from 'primevue/button'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import Dialog from 'primevue/dialog'
import Skeleton from 'primevue/skeleton'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { documentsApi } from '@/api/documents'
import { apiClient } from '@/api/client'
import { formatMoney } from '@/utils/chartFormatters'
import type { DocumentItemDto } from '@/entities/document'

const props = defineProps<{
  docId: number
  canEdit: boolean
  initialSubtotal: number | null
  initialDiscountPct: number | string | null
  initialDiscountAmount: number | null
  initialTotal: number | null
  initialCurrency: string | null
}>()

defineEmits<{
  totalsChange: [payload: { subtotal: number; discount_pct: number; total: number; currency: string }]
}>()

const { t } = useI18n()
const toast = useToast()

const itemsResource = useAsyncResource<DocumentItemDto[]>(() => [])
const items = computed(() => itemsResource.data.value)
const loading = computed(() => itemsResource.loading.value)

async function fetchItems() {
  await itemsResource.run(() => documentsApi.getDocumentItems(props.docId))
}

watch(() => props.docId, () => void fetchItems(), { immediate: true })

// ─── Computed totals ───────────────────────────────────────────────────────
const subtotal = computed(() =>
  items.value.reduce((acc, item) => acc + item.line_total, 0),
)
const discountPct = ref(props.initialDiscountPct != null ? parseFloat(String(props.initialDiscountPct)) || 0 : 0)
const discountAmount = computed(() => Math.round(subtotal.value * discountPct.value / 100))
const totalAmount = computed(() => subtotal.value - discountAmount.value)
const currency = ref(props.initialCurrency ?? 'KZT')

const currencyOptions = ['KZT', 'USD', 'EUR', 'RUB', 'UZS']

function saveCurrency(val: string) {
  currency.value = val
  // emit for autosave
}

// ─── Update item qty ───────────────────────────────────────────────────────
async function updateItem(itemId: number, qty: number) {
  try {
    const updated = await documentsApi.updateDocumentItem(props.docId, itemId, { qty })
    const idx = itemsResource.data.value.findIndex((i) => i.id === itemId)
    if (idx >= 0) {
      itemsResource.data.value[idx] = updated
    }
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

// ─── Remove item ──────────────────────────────────────────────────────────
async function removeItem(itemId: number) {
  try {
    await documentsApi.deleteDocumentItem(props.docId, itemId)
    itemsResource.data.value = itemsResource.data.value.filter((i) => i.id !== itemId)
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  }
}

// ─── Add item dialog ───────────────────────────────────────────────────────
const addDialogVisible = ref(false)
const addingItem = ref(false)
interface ProductOption { id: number; name: string }
const productOptions = ref<ProductOption[]>([])

watch(addDialogVisible, async (open) => {
  if (open && productOptions.value.length === 0) {
    try {
      const resp = await apiClient.get<{ data: ProductOption[] }>('/api/admin/products', {
        params: { per_page: 100 },
      })
      productOptions.value = resp.data.data
    } catch {
      productOptions.value = []
    }
  }
})

const newItem = ref<{ product_id: number | null; qty: number }>({ product_id: null, qty: 1 })

async function submitAddItem() {
  if (!newItem.value.product_id) return
  addingItem.value = true
  try {
    const item = await documentsApi.createDocumentItem(props.docId, {
      product_id: newItem.value.product_id,
      qty: newItem.value.qty,
    })
    itemsResource.data.value = [...itemsResource.data.value, item]
    addDialogVisible.value = false
    newItem.value = { product_id: null, qty: 1 }
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    addingItem.value = false
  }
}
</script>

<style lang="scss" scoped>
.items-tab {
  &__qty-input {
    width: 80px;
  }

  &__currency-select {
    width: 90px;
  }

  &__empty {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 1.5rem;
    color: var(--p-text-muted-color);
    justify-content: center;
  }

  &__summary-table {
    border-collapse: collapse;

    td {
      padding: 0.25rem 0.5rem;
      font-size: $font-size-sm;
    }
  }

  &__total-row td {
    border-top: 1px solid var(--p-surface-200);
    padding-top: 0.5rem;
    font-size: $font-size-md;
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
