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
        <div class="deal-tab-finances__amount-row">
          <input
            v-model="paymentAmountDisplay"
            class="deal-tab-finances__amount-input"
            type="text"
            inputmode="numeric"
            :placeholder="t('sales.deal.finances.amountPlaceholder')"
            @input="onAmountInput"
          />
          <select
            v-model="paymentCurrency"
            class="deal-tab-finances__currency-select"
          >
            <option v-for="opt in currencyOptions" :key="opt.value" :value="opt.value">
              {{ opt.label }}
            </option>
          </select>
        </div>
        <!-- Show saved value as formatted money -->
        <small v-if="props.deal.paid_amount != null" class="deal-tab-finances__saved-note">
          {{ t('sales.deal.finances.savedAmount') }}: {{ formatMoney(props.deal.paid_amount, props.deal.payment_currency) }}
        </small>
      </div>

      <!-- Зафиксировать оплату -->
      <div class="mt-3">
        <Button
          :label="t('sales.deal.finances.fixPayment')"
          size="small"
          :loading="savingPayment"
          :disabled="!canSave"
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
import { ref, computed } from 'vue'
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

// ── Currency helpers ──────────────────────────────────────────────────────────

const currencySymbols: Record<string, string> = {
  KZT: '₸',
  RUB: '₽',
  USD: '$',
  EUR: '€',
  UZS: 'сум',
  AED: 'AED',
}

function formatMoney(kopecks: number, currency: string | null | undefined): string {
  const sym = currencySymbols[currency ?? ''] ?? (currency ?? '')
  const units = Math.round(kopecks / 100)
  const formatted = units.toLocaleString('ru-RU')
  return `${formatted} ${sym}`.trim()
}

// ── Payment amount display (user-facing roubles/units, stored as kopecks) ─────

function kopecksToDisplay(kopecks: number | null | undefined): string {
  if (kopecks == null) return ''
  return Math.round(kopecks / 100).toLocaleString('ru-RU')
}

// Initialise from saved value
const paymentAmountDisplay = ref<string>(kopecksToDisplay(props.deal.paid_amount))
const paymentAmountKopecks = ref<number | null>(props.deal.paid_amount ?? null)
const paymentCurrency = ref<string>(props.deal.payment_currency ?? 'RUB')

function onAmountInput(event: Event) {
  const raw = (event.target as HTMLInputElement).value
  // Strip non-digits and spaces
  const digits = raw.replace(/[^\d]/g, '')
  if (digits === '') {
    paymentAmountKopecks.value = null
    paymentAmountDisplay.value = ''
    return
  }
  const units = parseInt(digits, 10)
  paymentAmountKopecks.value = units * 100 // store as kopecks
  paymentAmountDisplay.value = units.toLocaleString('ru-RU')
}

// ── Payment date ─────────────────────────────────────────────────────────────

const paidAtIso = ref<string | null>(props.deal.paid_at ?? null)

// ── Save state ────────────────────────────────────────────────────────────────

const savingPayment = ref(false)

// Allow save if either date or amount is set
const canSave = computed(() => !!(paidAtIso.value || paymentAmountKopecks.value != null))

const currencyOptions = [
  { label: '₸ KZT', value: 'KZT' },
  { label: '₽ RUB', value: 'RUB' },
  { label: '$ USD', value: 'USD' },
  { label: '€ EUR', value: 'EUR' },
  { label: 'сум UZS', value: 'UZS' },
  { label: 'AED', value: 'AED' },
]

// ── Handle save — POST /api/deals/{id}/fix-payment ───────────────────────────

async function handleFixPayment() {
  if (!canSave.value) return
  savingPayment.value = true
  try {
    const payload: {
      paid_at?: string | null
      paid_amount?: number | null
      payment_currency?: string | null
    } = {}
    if (paidAtIso.value !== undefined) payload.paid_at = paidAtIso.value
    if (paymentAmountKopecks.value !== null) {
      payload.paid_amount = paymentAmountKopecks.value
      payload.payment_currency = paymentCurrency.value
    }
    const updated = await salesApi.fixPayment(props.deal.id, payload)
    emit('dealUpdated', updated)
    toast.add({
      severity: 'success',
      summary: t('crm.log.events.payment_fixed'),
      life: 2000,
    })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
  } finally {
    savingPayment.value = false
  }
}
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
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    padding: 4px $space-2;
    border: 1px solid var(--p-surface-300);
    border-radius: $radius-sm;
    background: var(--p-card-background);
    font-size: $font-size-sm;
    color: var(--p-text-color);
    outline: none;
    min-width: 0;

    &:focus {
      border-color: var(--p-primary-color);
    }

    .app-dark & {
      border-color: var(--p-surface-600);
    }
  }

  &__currency-select {
    flex-shrink: 0;
    min-width: 90px;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    padding: 4px $space-2;
    border: 1px solid var(--p-surface-300);
    border-radius: $radius-sm;
    background: var(--p-card-background);
    font-size: $font-size-sm;
    color: var(--p-text-color);
    cursor: pointer;
    outline: none;

    &:focus {
      border-color: var(--p-primary-color);
    }

    .app-dark & {
      border-color: var(--p-surface-600);
    }
  }

  &__saved-note {
    font-size: $font-size-xs;
    color: var(--p-text-muted-color);
    margin-top: $space-1;
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
