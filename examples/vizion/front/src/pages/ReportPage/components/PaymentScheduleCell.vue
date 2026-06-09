<template>
  <div class="payment-schedule-cell">
    <!-- Column headers — visible whenever there is a summary row to show -->
    <div
      v-if="hasItems || formattedPaidTotal || formattedDueTotal"
      class="ps-header"
      aria-hidden="true"
    >
      <span class="ps-header__spacer" />
      <span class="ps-header__col">{{ t('paymentSchedule.headers.date') }}</span>
      <span class="ps-header__col">{{ t('paymentSchedule.headers.paid') }}</span>
      <span class="ps-header__col">{{ t('paymentSchedule.headers.due') }}</span>
    </div>

    <!-- Summary row — always visible, clickable to expand/collapse -->
    <button
      class="ps-summary"
      :class="{ 'ps-summary--expanded': expanded }"
      :aria-expanded="expanded"
      :aria-controls="hasItems ? controlId : undefined"
      :disabled="!hasItems"
      @click="hasItems ? (expanded = !expanded) : undefined"
    >
      <span
        class="pi ps-toggle-icon"
        :class="expanded ? 'pi-chevron-down' : 'pi-chevron-right'"
        aria-hidden="true"
      />
      <span class="ps-col ps-col--label">{{ t('paymentSchedule.total') }}</span>
      <span class="ps-col ps-col--paid">{{ formattedPaidTotal }}</span>
      <span class="ps-col ps-col--due">{{ formattedDueTotal }}</span>
    </button>

    <!-- Detail rows — visible when expanded -->
    <div
      v-if="expanded && hasItems"
      :id="controlId"
      class="ps-items"
    >
      <div
        v-for="(item, index) in value.items"
        :key="index"
        class="ps-item"
      >
        <span class="ps-col ps-col--label ps-col--date">{{ formatDate(item.date) }}</span>
        <span class="ps-col ps-col--paid ps-col--muted">
          {{ item.paid != null ? formatMoney(item.paid) : '' }}
        </span>
        <span class="ps-col ps-col--due ps-col--muted">
          {{ item.due != null ? formatMoney(item.due) : '' }}
        </span>
      </div>

    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useFormatter } from '@/composables/useFormatter'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export interface PaymentScheduleItem {
  date: string | null
  paid: number | null
  due: number | null
}

export interface PaymentScheduleValue {
  paid_total: number | null
  due_total: number | null
  items: PaymentScheduleItem[]
}

const props = defineProps<{
  value: PaymentScheduleValue
  /** Unique id for aria-controls — caller supplies the cell coordinate */
  cellId: string
}>()

const { t } = useLocalI18n({ en, ru })
const { format } = useFormatter()

const expanded = ref(false)
const controlId = computed(() => `ps-items-${props.cellId}`)
const hasItems = computed(() => props.value.items.length > 0)

const formatMoney = (amount: number | null): string => {
  if (amount == null) return ''
  return String(format(amount, { type: 'money' }))
}

const formattedPaidTotal = computed(() => formatMoney(props.value.paid_total))
const formattedDueTotal = computed(() => formatMoney(props.value.due_total))

/**
 * Format a YYYY-MM-DD string as dd.MM.yyyy without Date parsing
 * to avoid UTC-shift in any timezone.
 */
const formatDate = (date: string | null): string => {
  if (!date) return ''
  const parts = date.split('-')
  if (parts.length !== 3) return date
  const [year, month, day] = parts
  return `${day}.${month}.${year}`
}
</script>

<style lang="scss" scoped>
.payment-schedule-cell {
  display: flex;
  flex-direction: column;
  // Floor width so mini-table contents (toggle + 3 columns: date, paid, due)
  // never collapse on narrow viewports. Breakdown:
  //   1rem (toggle/spacer) + 3 × 0.5rem (gaps) + 3 columns ~7em each
  //   ≈ 2.5rem reserved + 21rem text → ~24rem total.
  // The parent DataTable wraps in `.p-datatable-table-container` with
  // `overflow: auto`, so this min-width forces a horizontal scroll instead
  // of squeezing mini-table columns.
  min-width: 24rem;
}

// Column header row
.ps-header {
  display: grid;
  grid-template-columns: 1rem 1fr 1fr 1fr;
  gap: 0.5rem;
  padding: 0 0 0.2rem 0;
  border-bottom: 1px solid $surface-200;
  margin-bottom: 0.1rem;

  &__spacer {
    display: block;
    width: 1rem;
  }

  &__col {
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
  }
}

// Summary row button — reset browser button defaults, make it feel like a row
.ps-summary {
  display: grid;
  grid-template-columns: 1rem 1fr 1fr 1fr;
  align-items: center;
  gap: 0.5rem;
  width: 100%;
  padding: 0.15rem 0;
  background: none;
  border: none;
  cursor: default;
  text-align: left;
  font-size: inherit;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  border-radius: 2px;

  &:not(:disabled) {
    cursor: pointer;

    &:hover {
      background: $surface-100;
    }

    &:focus-visible {
      outline: 2px solid var(--app-action-primary-bg);
      outline-offset: 1px;
    }
  }

  &:disabled {
    .ps-toggle-icon {
      color: $surface-300;
    }
  }

  .ps-toggle-icon {
    font-size: 0.65rem;
    color: $surface-500;
    flex-shrink: 0;
    transition: color 0.15s;
  }

  &--expanded .ps-toggle-icon {
    color: $surface-700;
  }
}

// Detail container
.ps-items {
  display: flex;
  flex-direction: column;
  margin-top: 0.2rem;
  padding-left: 0.25rem;
  border-left: 2px solid $surface-200;
  margin-left: 0.4rem;
}

.ps-item {
  display: grid;
  grid-template-columns: 1rem 1fr 1fr 1fr;
  align-items: center;
  gap: 0.5rem;
  padding: 0.1rem 0;

  // Reserve the toggle-icon column so columns align with summary row
  &::before {
    content: '';
    display: block;
    width: 1rem;
  }
}

// Shared column modifiers
.ps-col {
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  &--date {
    font-size: $font-size-sm;
    color: $surface-600;
  }

  &--muted {
    font-size: $font-size-sm;
    color: $surface-500;
  }
}
</style>
