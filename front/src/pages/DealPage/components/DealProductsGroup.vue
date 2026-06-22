<template>
  <DealFieldGroup
    :title="t('sales.deal.info.groups.products')"
    icon="pi-shopping-cart"
    group-key="products"
    :accent="true"
    :count="items.length"
    :total-label="items.length > 0 ? formatCurrency(displayTotal, currency) : undefined"
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

    <!-- Perpetual license toggle row -->
    <div class="deal-products-group__perpetual-row">
      <span class="deal-products-group__perpetual-label">
        {{ t('sales.deal.fields.perpetualLicense') }}
      </span>
      <div class="deal-products-group__perpetual-value">
        <ToggleSwitch
          v-model="localPerpetual"
          :disabled="perpetualSaving"
          @update:model-value="onPerpetualChange"
        />
        <span class="deal-products-group__perpetual-hint">
          {{ localPerpetual ? t('sales.deal.perpetual.on') : t('sales.deal.perpetual.off') }}
        </span>
        <ProgressSpinner
          v-if="perpetualSaving"
          style="width: 16px; height: 16px"
          stroke-width="4"
        />
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

        <!-- When locked AND amounts differ: show line total + locked budget separately -->
        <template v-if="amountLocked && dealAmount !== totalAmount">
          <div class="deal-products-group__summary-row">
            <span class="deal-products-group__summary-label">{{ t('sales.deal.budget.lineTotal') }}</span>
            <span class="deal-products-group__summary-value deal-products-group__summary-value--muted">
              {{ formatCurrency(totalAmount, currency) }}
            </span>
          </div>
          <div class="deal-products-group__summary-row deal-products-group__summary-row--locked">
            <span class="deal-products-group__summary-label deal-products-group__summary-label--locked">
              <i class="pi pi-lock deal-products-group__lock-icon" />
              {{ t('sales.deal.budget.locked') }}
            </span>
            <div class="deal-products-group__summary-locked-value">
              <span class="deal-products-group__summary-value deal-products-group__summary-value--total">
                {{ formatCurrency(dealAmount, currency) }}
              </span>
              <Button
                :label="t('sales.deal.budget.unlock')"
                size="small"
                text
                severity="secondary"
                :loading="lockSaving"
                class="deal-products-group__unlock-btn"
                @click="onToggleLock"
              />
            </div>
          </div>
        </template>

        <!-- Normal total row (amounts match, or not locked) -->
        <div v-else class="deal-products-group__summary-row deal-products-group__summary-row--total">
          <span class="deal-products-group__summary-label">{{ t('sales.deal.budget.total') }}</span>
          <div class="deal-products-group__summary-total-value">
            <span class="deal-products-group__summary-value deal-products-group__summary-value--total">
              {{ formatCurrency(totalAmount, currency) }}
            </span>
            <!-- Perpetual badge when enabled -->
            <Tag
              v-if="localPerpetual"
              :value="t('sales.deal.perpetual.on')"
              severity="info"
              size="small"
              class="deal-products-group__perpetual-badge"
            />
            <!-- Lock toggle icon -->
            <button
              class="deal-products-group__lock-btn"
              type="button"
              :title="amountLocked ? t('sales.deal.budget.unlock') : t('sales.deal.budget.lock')"
              :disabled="lockSaving"
              @click="onToggleLock"
            >
              <i
                :class="['pi', amountLocked ? 'pi-lock' : 'pi-lock-open']"
                class="deal-products-group__lock-icon"
              />
            </button>
          </div>
        </div>
      </div>
    </template>
  </DealFieldGroup>

  <!-- Perpetual license confirm dialog (no group: routes to DealPage's top-level <ConfirmDialog />) -->
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import ToggleSwitch from 'primevue/toggleswitch'
import ProgressSpinner from 'primevue/progressspinner'
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
  /** deal.amount (kopecks) — used for locked-budget display */
  dealAmount: number
  /** deal.amount_locked */
  amountLocked: boolean
  /** deal.perpetual_license */
  perpetualLicense: boolean
  /** true while perpetual PATCH is in flight */
  perpetualSaving: boolean
  /** true while amount_locked PATCH is in flight */
  lockSaving: boolean
}>()

const emit = defineEmits<{
  addProduct: []
  updateItem: [id: number, payload: { quantity?: number; unit_price?: number; discount?: number }]
  removeItem: [id: number]
  amountChanged: [newTotal: number]
  togglePerpetual: [newValue: boolean]
  toggleLock: []
}>()

const { t } = useI18n()
const confirm = useConfirm()

// ── Local reactive copy of perpetual_license (for optimistic toggle) ──────────

const localPerpetual = ref(props.perpetualLicense)

watch(() => props.perpetualLicense, (v) => {
  localPerpetual.value = v
})

// ── Computed totals ───────────────────────────────────────────────────────────

const totalAmount = computed(() =>
  props.items.reduce((sum, item) => sum + item.amount, 0),
)

const totalDiscount = computed(() =>
  props.items.reduce((sum, item) => sum + (item.discount ?? 0), 0),
)

// The value shown in the group header total-label
const displayTotal = computed(() => {
  if (props.amountLocked) return props.dealAmount
  return totalAmount.value
})

// ── Perpetual toggle (with confirm dialog) ────────────────────────────────────

function onPerpetualChange(newVal: boolean) {
  // Roll back optimistic change — we wait for user confirmation
  localPerpetual.value = !newVal

  confirm.require({
    header: t('sales.deal.perpetual.confirmTitle'),
    message: t('sales.deal.perpetual.confirmMessage'),
    icon: 'pi pi-exclamation-triangle',
    acceptLabel: t('common.confirm'),
    rejectLabel: t('common.cancel'),
    accept: () => {
      localPerpetual.value = newVal
      emit('togglePerpetual', newVal)
    },
    reject: () => {
      // keep localPerpetual as-is (already rolled back above)
    },
  })
}

// ── Lock toggle ───────────────────────────────────────────────────────────────

function onToggleLock() {
  emit('toggleLock')
}
</script>

<style lang="scss" scoped>
// ── Perpetual row ─────────────────────────────────────────────────────────────

.deal-products-group__perpetual-row {
  display: grid;
  grid-template-columns: 120px 1fr;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.deal-products-group__perpetual-label {
  font-size: $font-size-xs;
  color: $surface-500;
}

.deal-products-group__perpetual-value {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.deal-products-group__perpetual-hint {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-300);
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

// ── Summary block ─────────────────────────────────────────────────────────────

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

  &--locked {
    padding-top: $space-2;
    padding-bottom: $space-2;
    background: var(--p-primary-50);
    border-radius: 0 0 $radius-sm $radius-sm;

    .app-dark & {
      background: var(--p-primary-950);
    }
  }
}

.deal-products-group__summary-label {
  font-size: $font-size-xs;
  color: $surface-500;
  font-weight: $font-weight-semibold;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  display: flex;
  align-items: center;
  gap: $space-1;

  &--locked {
    color: var(--p-primary-color);
  }
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

  &--muted {
    color: $surface-400;
    font-weight: $font-weight-medium;
  }
}

.deal-products-group__summary-total-value {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.deal-products-group__summary-locked-value {
  display: flex;
  align-items: center;
  gap: $space-2;
}

// ── Lock button (icon-only, inline) ──────────────────────────────────────────

.deal-products-group__lock-btn {
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  display: flex;
  align-items: center;
  color: $surface-400;
  transition: color var(--app-transition-fast);

  &:hover:not(:disabled) {
    color: var(--p-primary-color);
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }
}

.deal-products-group__lock-icon {
  font-size: $font-size-xs;
}

.deal-products-group__unlock-btn {
  padding: 0 $space-2;
  height: 24px;
  font-size: $font-size-xs;
}

.deal-products-group__perpetual-badge {
  flex-shrink: 0;
}
</style>
