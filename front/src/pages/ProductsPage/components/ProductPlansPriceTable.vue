<template>
  <div class="plans-price-table">
    <DataTable
      :value="rows"
      size="small"
      striped-rows
      class="plans-price-table__dt"
    >
      <Column
        :header="t('catalog.product.page.plan.fields.name')"
        style="min-width: 160px"
      >
        <template #body="{ data }">
          <span class="plans-price-table__plan-name">{{ data.name }}</span>
          <Tag
            v-if="data.unit"
            :value="unitLabel(data.unit)"
            size="small"
            severity="secondary"
            class="ms-2"
          />
        </template>
      </Column>
      <Column
        v-for="currency in CURRENCY_WHITELIST"
        :key="currency"
        :header="currency"
        style="min-width: 130px"
      >
        <template #body="{ data }">
          <span
            v-if="getPriceForCell(data, currency) !== null"
            class="plans-price-table__price"
            style="white-space: nowrap"
          >
            {{ formatCurrency(getPriceForCell(data, currency)!, currency) }}
          </span>
          <span v-else class="plans-price-table__no-price">—</span>
        </template>
      </Column>
    </DataTable>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Tag from 'primevue/tag'
import { formatCurrency, CURRENCY_WHITELIST } from '@/utils/currency'
import type { ProductDto, ProductPlanDto } from '@/entities/catalog'

const props = defineProps<{
  product: ProductDto
}>()

const { t } = useI18n()

interface PlanRow {
  name: string
  unit: string | null
  planId: number | null
  prices: Record<string, number> // currency_code → amount (kopecks integer)
}

const rows = computed<PlanRow[]>(() => {
  const result: PlanRow[] = []
  const allPrices = props.product.prices ?? []

  // Base row (plan_id = null)
  const basePrices = allPrices.filter((p) => p.plan_id === null)
  if (basePrices.length > 0) {
    const priceMap: Record<string, number> = {}
    for (const p of basePrices) priceMap[p.currency_code] = p.amount
    result.push({
      name: t('catalog.product.page.prices.basePriceRow'),
      unit: null,
      planId: null,
      prices: priceMap,
    })
  }

  // Plan rows
  const plans: ProductPlanDto[] = props.product.plans ?? []
  for (const plan of plans) {
    const planPrices = allPrices.filter((p) => p.plan_id === plan.id)
    const priceMap: Record<string, number> = {}
    for (const p of planPrices) priceMap[p.currency_code] = p.amount
    // Also check prices on the plan itself (eager-loaded)
    for (const p of plan.prices ?? []) {
      priceMap[p.currency_code] = p.amount
    }
    result.push({
      name: plan.name,
      unit: plan.unit,
      planId: plan.id,
      prices: priceMap,
    })
  }

  return result
})

function getPriceForCell(row: PlanRow, currency: string): number | null {
  const val = row.prices[currency]
  return val !== undefined ? val : null
}

function unitLabel(unit: string): string {
  return t(`catalog.products.unit.${unit}`, unit)
}
</script>

<style lang="scss" scoped>
.plans-price-table {
  padding: $space-3 $space-4;
  background: $surface-50;

  &__plan-name {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-800;
  }

  &__price {
    font-size: $font-size-sm;
    color: $surface-900;
    font-variant-numeric: tabular-nums;
  }

  &__no-price {
    color: $surface-400;
    font-size: $font-size-sm;
  }

  &__dt {
    font-size: $font-size-sm;
  }
}

// Dark mode
:global(.app-dark) .plans-price-table {
  background: var(--p-surface-900);
}
</style>
