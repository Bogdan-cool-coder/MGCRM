<template>
  <div class="deal-tab-finances">
    <!-- ── Section 1: Payment fixation ──────────────────────────────────────── -->
    <div class="deal-tab-finances__section">
      <p class="deal-tab-finances__section-label">
        <i class="pi pi-credit-card me-1" />
        {{ t('sales.deal.finances.paymentFixation') }}
      </p>

      <!-- Оплата — факт (дата) -->
      <div class="deal-tab-finances__field-row">
        <label class="deal-tab-finances__label">
          {{ t('sales.deal.finances.paidAt') }}
        </label>
        <DateField
          v-model="paidAtIso"
          placeholder="ДД.ММ.ГГГГ"
        />
      </div>

      <!-- Сумма оплаты + валюта -->
      <div class="deal-tab-finances__field-row mt-2">
        <label class="deal-tab-finances__label">
          {{ t('sales.deal.finances.paymentAmount') }}
        </label>
        <!-- TODO MISSING: paid_amount / payment_currency — no backend column yet;
             left inert until finance sprint adds PATCH /api/deals/{id}{paid_amount,payment_currency} -->
        <div class="deal-tab-finances__amount-row">
          <input
            v-model="paymentAmount"
            class="deal-tab-finances__amount-input"
            type="text"
            inputmode="numeric"
            :placeholder="t('sales.deal.finances.amountPlaceholder')"
            :disabled="true"
          />
          <!-- Currency selector — inert until paid_amount/payment_currency backend columns exist -->
          <select
            v-model="paymentCurrency"
            class="deal-tab-finances__currency-select"
            disabled
          >
            <option v-for="opt in currencyOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>
        <small class="deal-tab-finances__missing-note">
          {{ t('sales.deal.finances.missingNote') }}
        </small>
      </div>

      <!-- Зафиксировать оплату -->
      <div class="mt-3">
        <Button
          :label="t('sales.deal.finances.fixPayment')"
          size="small"
          :loading="savingPaidAt"
          :disabled="!paidAtIso"
          @click="handleFixPayment"
        />
      </div>
    </div>

    <!-- ── Section 2: Payment schedule stub ─────────────────────────────────── -->
    <div class="deal-tab-finances__section deal-tab-finances__section--stub">
      <div class="deal-tab-finances__stub-header">
        <i class="pi pi-lock deal-tab-finances__stub-icon" />
        <span class="deal-tab-finances__stub-title">{{ t('sales.deal.finances.scheduleTitle') }}</span>
        <Tag
          severity="warn"
          :value="t('sales.deal.finances.waitingForDoc')"
          class="ms-auto"
        />
      </div>
      <div class="deal-tab-finances__stub-skeletons">
        <Skeleton height="14px" class="mb-2" />
        <Skeleton height="14px" class="mb-2" />
        <Skeleton height="14px" width="60%" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import { useToast } from 'primevue/usetoast'
import DateField from '@/components/crm/DateField.vue'
import { salesApi } from '@/api/sales'
import type { DealDto } from '@/entities/sales'

const props = defineProps<{
  deal: DealDto
}>()

const emit = defineEmits<{
  dealUpdated: [deal: DealDto]
}>()

const { t } = useI18n()
const toast = useToast()

// ── Payment date (WIRED: PATCH /api/deals/{deal}{paid_at}) ───────────────────

const paidAtIso = ref<string | null>(props.deal.paid_at ?? null)
const savingPaidAt = ref(false)

async function handleFixPayment() {
  if (!paidAtIso.value) return
  savingPaidAt.value = true
  try {
    const updated = await salesApi.updateDeal(props.deal.id, { paid_at: paidAtIso.value })
    emit('dealUpdated', updated)
    toast.add({
      severity: 'success',
      summary: t('sales.deal.finances.fixPayment'),
      life: 2000,
    })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    savingPaidAt.value = false
  }
}

// ── Payment amount + currency (MISSING — inert stub) ─────────────────────────
// TODO: no paid_amount / payment_currency column on backend yet.
// Wire once PATCH /api/deals/{id} supports {paid_amount, payment_currency}.

const paymentAmount = ref<string>('')
const paymentCurrency = ref<string | null>(null)

const currencyOptions = [
  { label: '₸ KZT', value: 'KZT' },
  { label: '₽ RUB', value: 'RUB' },
  { label: '$ USD', value: 'USD' },
  { label: '€ EUR', value: 'EUR' },
]

</script>

<style lang="scss" scoped>
.deal-tab-finances {
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-3;

  &__section {
    border: 1px solid var(--p-surface-200);
    border-radius: $radius-md;
    padding: $space-3;

    .app-dark & {
      border-color: var(--p-surface-200);
    }
  }

  &__section-label {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
    margin: 0 0 $space-3;
  }

  &__field-row {
    display: flex;
    flex-direction: column;
  }

  &__label {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    margin-bottom: $space-1;
  }

  // Amount + currency row
  &__amount-row {
    display: flex;
    gap: $space-2;
    align-items: center;
  }

  &__amount-input {
    flex: 1;
    padding: 4px $space-2;
    border: 1px solid var(--p-surface-300);
    border-radius: $radius-sm;
    background: var(--p-card-background);
    font-size: $font-size-sm;
    color: var(--p-text-color);
    outline: none;
    min-width: 0;

    &:disabled {
      opacity: 0.5;
      cursor: not-allowed;
    }

    .app-dark & {
      border-color: var(--p-surface-600);
    }
  }

  &__currency-select {
    flex-shrink: 0;
    min-width: 90px;
    padding: 4px $space-2;
    border: 1px solid var(--p-surface-300);
    border-radius: $radius-sm;
    background: var(--p-card-background);
    font-size: $font-size-sm;
    color: var(--p-text-color);
    cursor: not-allowed;
    opacity: 0.5;

    .app-dark & {
      border-color: var(--p-surface-600);
    }
  }

  &__missing-note {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    margin-top: $space-1;
    font-style: italic;
  }

  // Schedule stub section
  &__section--stub {
    opacity: 0.8;
  }

  &__stub-header {
    display: flex;
    align-items: center;
    gap: $space-2;
    margin-bottom: $space-3;
  }

  &__stub-icon {
    font-size: $font-size-base;
    color: var(--p-text-muted-color);
  }

  &__stub-title {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: var(--p-text-color);
  }

  &__stub-skeletons {
    width: 100%;
  }
}
</style>
