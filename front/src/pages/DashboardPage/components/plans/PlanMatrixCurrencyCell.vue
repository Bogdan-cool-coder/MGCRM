<template>
  <!-- Money metrics: a currency Select + an info-popover with the per-currency
       breakdown (rate on the 1st of the month, base equivalent). Count metrics
       render a dimmed «—» (no currency dimension). -->
  <div v-if="isMoney" class="plan-currency-cell">
    <Select
      :model-value="currency"
      :options="currencyOptions"
      option-label="label"
      option-value="value"
      :disabled="disabled"
      class="plan-currency-cell__select"
      @update:model-value="(v) => emit('update:currency', v as string)"
    />
    <Button
      v-if="hasBreakdown"
      text
      rounded
      severity="secondary"
      size="small"
      icon="pi pi-info-circle"
      :aria-label="t('dashboard.plans.currency_info')"
      class="plan-currency-cell__info"
      @click="toggle"
    />

    <Popover ref="popover">
      <div class="plan-currency-cell__popover">
        <p class="plan-currency-cell__popover-title">{{ t('dashboard.plans.rate_on_first') }}</p>
        <div
          v-for="b in breakdown"
          :key="b.currency"
          class="plan-currency-cell__popover-row"
        >
          <span class="plan-currency-cell__popover-cur">{{ b.currency }}</span>
          <span class="plan-currency-cell__popover-val">
            {{ formatMkMoney(b.plan_kopecks, b.currency) }}
          </span>
        </div>
        <div class="plan-currency-cell__popover-base">
          <span>{{ t('dashboard.plans.in_base') }}</span>
          <span class="plan-currency-cell__popover-val">
            {{ formatMkMoney(baseKopecks, baseCurrency) }}
          </span>
        </div>
      </div>
    </Popover>
  </div>
  <span v-else class="plan-currency-cell__na">—</span>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Select from 'primevue/select'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import { formatMkMoney } from '@/utils/motivation'
import { CURRENCY_WHITELIST } from '@/utils/currency'
import type { PlanCurrencyBreakdown } from '@/entities/planTargets'

const props = defineProps<{
  isMoney: boolean
  currency: string
  disabled: boolean
  breakdown: PlanCurrencyBreakdown[]
  baseKopecks: number
  baseCurrency: string
}>()

const emit = defineEmits<{
  'update:currency': [value: string]
}>()

const { t } = useI18n()

const currencyOptions = CURRENCY_WHITELIST.map((c) => ({ label: c, value: c }))

const hasBreakdown = computed<boolean>(() => props.breakdown.length > 0)

const popover = ref<InstanceType<typeof Popover> | null>(null)
const toggle = (event: Event): void => {
  popover.value?.toggle(event)
}
</script>

<style lang="scss" scoped>
.plan-currency-cell {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.plan-currency-cell__select {
  width: 84px;
}

.plan-currency-cell__na {
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.plan-currency-cell__popover {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  min-width: 200px;
}

.plan-currency-cell__popover-title {
  margin: 0 0 $space-1;
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.plan-currency-cell__popover-row,
.plan-currency-cell__popover-base {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
  font-size: $font-size-sm;
}

.plan-currency-cell__popover-base {
  padding-top: $space-2;
  border-top: 1px solid $surface-200;
  font-weight: $font-weight-semibold;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.plan-currency-cell__popover-cur {
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.plan-currency-cell__popover-val {
  font-variant-numeric: tabular-nums;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-800);
  }
}
</style>
