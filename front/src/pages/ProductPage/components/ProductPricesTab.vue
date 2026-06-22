<template>
  <div class="prices-tab">
    <!-- Add plan button (not for fixed/custom) -->
    <div v-if="canAddPlan" class="prices-tab__toolbar">
      <Button
        icon="pi pi-plus"
        :label="t('catalog.product.page.prices.addPlan')"
        severity="secondary"
        outlined
        @click="$emit('add-plan')"
      />
    </div>

    <!-- Empty state for tiered with no plans -->
    <div
      v-if="['tiered', 'per_minute', 'package'].includes(product.pricing_type) && !hasPrices"
      class="prices-tab__empty"
    >
      <i class="pi pi-list prices-tab__empty-icon" />
      <p class="prices-tab__empty-text">{{ t('catalog.product.page.prices.emptyPlans') }}</p>
      <Button
        v-if="canAddPlan"
        icon="pi pi-plus"
        :label="t('catalog.product.page.prices.addPlan')"
        @click="$emit('add-plan')"
      />
    </div>

    <!-- Plan × Currency table -->
    <DataTable
      v-else
      :value="tableRows"
      size="small"
      striped-rows
      class="prices-tab__table"
    >
      <!-- Plan name column -->
      <Column
        :header="t('catalog.product.page.plan.fields.name')"
        style="min-width: 180px"
      >
        <template #body="{ data }">
          <div class="prices-tab__plan-cell">
            <span class="prices-tab__plan-name">{{ data.name }}</span>
            <Tag
              v-if="data.unit"
              :value="unitLabel(data.unit)"
              size="small"
              severity="secondary"
            />
            <div v-if="data.planId !== null && canWrite" class="prices-tab__plan-actions">
              <Button
                icon="pi pi-pencil"
                size="small"
                text
                severity="secondary"
                @click="onEditPlan(data.planId!)"
              />
              <Button
                icon="pi pi-trash"
                size="small"
                text
                severity="danger"
                @click="onDeletePlan(data.planId!)"
              />
            </div>
          </div>
        </template>
      </Column>

      <!-- Currency columns -->
      <Column
        v-for="currency in CURRENCY_WHITELIST"
        :key="currency"
        :header="currency"
        style="min-width: 130px"
      >
        <template #body="{ data }">
          <PriceCellEdit
            :value-kopecks="getCellPrice(data, currency)"
            :currency="currency"
            :saving="saving"
            :disabled="!canWrite"
            @save="(v) => $emit('save-price', data.planId, currency, v)"
          />
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
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import { CURRENCY_WHITELIST } from '@/utils/currency'
import { useUserStore } from '@/stores/user'
import type { ProductDto, ProductPlanDto } from '@/entities/catalog'
import PriceCellEdit from './PriceCellEdit.vue'

const props = defineProps<{
  product: ProductDto
  saving?: boolean
}>()

const { t } = useI18n()
const userStore = useUserStore()

const canWrite = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

const canAddPlan = computed(() => {
  const type = props.product.pricing_type
  return canWrite.value && type !== 'fixed' && type !== 'custom'
})

interface PriceRow {
  name: string
  unit: string | null
  planId: number | null
  priceMap: Record<string, { id: number | null; kopecks: number } | null>
}

const tableRows = computed<PriceRow[]>(() => {
  const rows: PriceRow[] = []
  const allPrices = props.product.prices ?? []

  // Base row (no plan)
  const basePrices = allPrices.filter((p) => p.plan_id === null)
  if (props.product.pricing_type === 'fixed' || basePrices.length > 0) {
    const map: PriceRow['priceMap'] = {}
    for (const c of CURRENCY_WHITELIST) {
      const p = basePrices.find((pr) => pr.currency_code === c)
      map[c] = p ? { id: p.id, kopecks: p.amount } : null
    }
    rows.push({
      name: t('catalog.product.page.prices.basePriceRow'),
      unit: null,
      planId: null,
      priceMap: map,
    })
  }

  // Plan rows
  const plans = props.product.plans ?? []
  for (const plan of plans) {
    const planPrices = [
      ...allPrices.filter((p) => p.plan_id === plan.id),
      ...(plan.prices ?? []),
    ]
    // Deduplicate by currency
    const seen = new Set<string>()
    const deduped = planPrices.filter((p) => {
      if (seen.has(p.currency_code)) return false
      seen.add(p.currency_code)
      return true
    })

    const map: PriceRow['priceMap'] = {}
    for (const c of CURRENCY_WHITELIST) {
      const p = deduped.find((pr) => pr.currency_code === c)
      map[c] = p ? { id: p.id, kopecks: p.amount } : null
    }
    rows.push({
      name: plan.name,
      unit: plan.unit,
      planId: plan.id,
      priceMap: map,
    })
  }

  return rows
})

const hasPrices = computed(() => tableRows.value.length > 0)

function getCellPrice(row: PriceRow, currency: string): number | null {
  const cell = row.priceMap[currency]
  return cell ? cell.kopecks : null
}

function unitLabel(unit: string): string {
  return t(`catalog.products.unit.${unit}`, unit)
}

function getPlanById(planId: number): ProductPlanDto | undefined {
  return props.product.plans?.find((p) => p.id === planId)
}

const emit = defineEmits<{
  'add-plan': []
  'edit-plan': [plan: ProductPlanDto]
  'delete-plan': [plan: ProductPlanDto]
  'save-price': [planId: number | null, currency: string, valueInUnits: number]
}>()

function onEditPlan(planId: number) {
  const plan = getPlanById(planId)
  if (plan) emit('edit-plan', plan)
}

function onDeletePlan(planId: number) {
  const plan = getPlanById(planId)
  if (plan) emit('delete-plan', plan)
}
</script>

<style lang="scss" scoped>
.prices-tab {
  &__toolbar {
    display: flex;
    justify-content: flex-end;
    margin-bottom: $space-3;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    justify-content: center;
    padding: $space-6;
    gap: $space-3;
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-lg;
    color: $surface-400;
  }

  &__empty-text {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
  }

  &__plan-cell {
    display: flex;
    align-items: center;
    gap: $space-2;
    flex-wrap: wrap;
  }

  &__plan-name {
    font-weight: $font-weight-medium;
    font-size: $font-size-sm;
  }

  &__plan-actions {
    display: flex;
    gap: $space-1;
    margin-left: auto;
  }
}
</style>
