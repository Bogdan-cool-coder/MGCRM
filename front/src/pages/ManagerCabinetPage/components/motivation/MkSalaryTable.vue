<template>
  <section class="mk-card mk-salary">
    <header class="mk-card__head">
      <span class="mk-eyebrow">{{ t('motivation.card.salary_components') }}</span>
    </header>

    <ul class="mk-salary__rows">
      <li
        v-for="(item, idx) in items"
        :key="`${item.kind}-${idx}`"
        class="mk-salary__row"
      >
        <!-- Row head -->
        <button
          type="button"
          class="mk-salary__row-head"
          :aria-expanded="expanded[idx]"
          @click="toggle(idx)"
        >
          <span class="mk-salary__icon-tile">
            <i :class="MK_ITEM_ICONS[item.kind]" aria-hidden="true" />
          </span>
          <span class="mk-salary__row-title">{{ item.name }}</span>
          <Tag
            v-if="positionTag(item)"
            :value="positionTag(item)"
            severity="secondary"
            class="mk-salary__row-tag"
          />
          <span class="mk-salary__spacer" />
          <i
            class="pi pi-chevron-down mk-salary__chevron"
            :class="{ 'mk-salary__chevron--open': expanded[idx] }"
            aria-hidden="true"
          />
        </button>

        <!-- Row detail -->
        <div v-show="expanded[idx]" class="mk-salary__detail">
          <div class="mk-salary__grid">
            <!-- Indicators -->
            <div class="mk-salary__cell-group">
              <span class="mk-label">{{ t('motivation.card.indicators') }}</span>
              <div class="mk-salary__kv">
                <span class="mk-salary__k">{{ t('motivation.card.col_plan') }}</span>
                <span class="mk-salary__v">{{ indicatorPlan(item) }}</span>
              </div>
              <div class="mk-salary__kv">
                <span class="mk-salary__k">{{ t('motivation.card.col_fact') }}</span>
                <span class="mk-salary__v">{{ indicatorFact(item) }}</span>
              </div>
            </div>

            <!-- Salary -->
            <div class="mk-salary__cell-group">
              <span class="mk-label">{{ t('motivation.card.salary_short') }}</span>
              <div class="mk-salary__kv">
                <span class="mk-salary__k">{{ t('motivation.card.col_salary_plan') }}</span>
                <span class="mk-salary__v">
                  {{ formatMkMoney(item.salary_plan_kopecks, item.currency) }}
                </span>
              </div>
              <div class="mk-salary__kv">
                <span class="mk-salary__k">{{ t('motivation.card.col_salary_fact') }}</span>
                <span class="mk-salary__v">
                  {{ formatMkMoney(item.salary_fact_kopecks, item.currency) }}
                </span>
              </div>
            </div>
          </div>

          <!-- % / manual badge row -->
          <div class="mk-salary__pct-row">
            <span class="mk-salary__k">{{ t('motivation.card.col_pct') }}</span>
            <Tag
              v-if="isManualKpi(item)"
              :severity="item.params?.manual_done ? 'success' : 'danger'"
              :value="item.params?.manual_done
                ? t('motivation.card.manual_done')
                : t('motivation.card.manual_not_done')"
            />
            <PctTag v-else :value="item.pct" :badge="item.badge" size="md" />
          </div>

          <!-- Payment note -->
          <p v-if="paymentNote(item)" class="mk-salary__note">{{ paymentNote(item) }}</p>

          <!-- Commission breakdown trigger -->
          <Button
            v-if="hasBreakdown(item)"
            text
            size="small"
            severity="secondary"
            icon="pi pi-list"
            :label="t('motivation.card.commission_breakdown')"
            class="mk-salary__breakdown-btn"
            @click="openBreakdown($event, item)"
          />
        </div>
      </li>
    </ul>

    <!-- Total -->
    <div class="mk-salary__total">
      <i class="pi pi-sigma mk-salary__total-icon" aria-hidden="true" />
      <span class="mk-salary__total-label">{{ t('motivation.card.total_label') }}</span>
      <span class="mk-salary__spacer" />
      <span class="mk-salary__total-plan">
        {{ formatMkMoney(total.salary_plan_kopecks, total.currency) }}
      </span>
      <i class="pi pi-arrow-right mk-salary__total-sep" aria-hidden="true" />
      <span class="mk-salary__total-fact">
        {{ formatMkMoney(total.salary_fact_kopecks, total.currency) }}
      </span>
    </div>

    <!-- Commission breakdown popover -->
    <Popover ref="breakdownPop">
      <div class="mk-salary__pop">
        <RouterLink
          v-for="row in activeBreakdown"
          :key="row.deal_id"
          :to="`/deals/${row.deal_id}`"
          class="mk-salary__pop-row"
        >
          <span class="mk-salary__pop-company">{{ row.company_name }}</span>
          <span class="mk-salary__pop-amount">
            {{ formatMkMoney(row.amount_kopecks, row.currency) }}
          </span>
        </RouterLink>
      </div>
    </Popover>
  </section>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Popover from 'primevue/popover'
import PctTag from '@/components/shared/PctTag.vue'
import { formatMkMoney, MK_EMPTY } from '@/utils/motivation'
import {
  MK_ITEM_ICONS,
  type MkCardItem,
  type MkTotal,
  type MkCommissionBreakdownRow,
} from '@/entities/motivation'

const props = defineProps<{
  items: MkCardItem[]
  total: MkTotal
}>()

const { t } = useI18n()

// All rows expanded by default (SPEC: «default: развёрнуто»).
const expanded = ref<boolean[]>([])
watch(
  () => props.items.length,
  (len) => {
    expanded.value = Array.from({ length: len }, () => true)
  },
  { immediate: true },
)

const toggle = (idx: number): void => {
  expanded.value[idx] = !expanded.value[idx]
}

const isManualKpi = (item: MkCardItem): boolean =>
  item.kind === 'kpi' && item.params?.kpi_type === 'manual'

const isCountKpi = (item: MkCardItem): boolean =>
  item.kind === 'kpi' && item.params?.kpi_type === 'count'

/** Indicator plan display — count KPIs show a raw count, money kinds show money. */
const indicatorPlan = (item: MkCardItem): string => {
  if (isManualKpi(item)) return MK_EMPTY
  if (isCountKpi(item)) {
    const n = item.params?.plan_count
    return n != null ? String(n) : MK_EMPTY
  }
  return formatMkMoney(item.plan_amount_kopecks, item.currency)
}

const indicatorFact = (item: MkCardItem): string => {
  if (isManualKpi(item)) return MK_EMPTY
  if (isCountKpi(item)) {
    const n = item.params?.fact_count
    return n != null ? String(n) : MK_EMPTY
  }
  return formatMkMoney(item.fact_amount_kopecks, item.currency)
}

const positionTag = (item: MkCardItem): string | null => {
  if (item.kind === 'commission' && item.params?.rate_pct_times_100 != null) {
    return `${(item.params.rate_pct_times_100 / 100).toFixed(0)}%`
  }
  if (item.kind === 'kpi' && item.params?.unit) return item.params.unit
  return null
}

const paymentNote = (item: MkCardItem): string | null => {
  const note = item.params?.payment_note
  if (note === 'immediate') return t('motivation.card.payment_note_commission')
  if (note === 'next_month') return t('motivation.card.payment_note_base')
  return null
}

const hasBreakdown = (item: MkCardItem): boolean =>
  !!item.params?.breakdown && item.params.breakdown.length > 0

// ─── Breakdown popover ─────────────────────────────────────────────────────
const breakdownPop = ref<InstanceType<typeof Popover> | null>(null)
const activeBreakdown = ref<MkCommissionBreakdownRow[]>([])

const openBreakdown = (event: Event, item: MkCardItem): void => {
  activeBreakdown.value = item.params?.breakdown ?? []
  breakdownPop.value?.toggle(event)
}
</script>

<style lang="scss" scoped>
@use '@/pages/ManagerCabinetPage/components/motivation/mk-shared' as *;

.mk-salary {
  padding: $space-4 $space-5 0;
}

.mk-salary__rows {
  list-style: none;
  margin: 0;
  padding: 0;
}

.mk-salary__row {
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }

  &:first-child {
    border-top: none;
  }
}

.mk-salary__row-head {
  display: flex;
  align-items: center;
  gap: $space-3;
  width: 100%;
  padding: $space-3 0;
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: left;
}

.mk-salary__icon-tile {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  background: var(--p-primary-100);
  color: $primary-900;
  flex-shrink: 0;
  font-size: $font-size-sm;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-100);
  }
}

.mk-salary__row-title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
}

.mk-salary__spacer {
  flex: 1;
}

.mk-salary__chevron {
  color: $surface-500;
  transition: transform var(--app-transition-fast);

  &--open {
    transform: rotate(180deg);
  }
}

.mk-salary__detail {
  padding: 0 0 $space-3 calc(28px + $space-3);
}

.mk-salary__grid {
  display: flex;
  gap: $space-4;
  flex-wrap: wrap;
}

.mk-salary__cell-group {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  min-width: 200px;
  flex: 1;
}

.mk-salary__kv {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
}

.mk-salary__k {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-salary__v {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
  text-align: right;
}

.mk-salary__pct-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  margin-top: $space-2;
}

.mk-salary__note {
  margin: $space-2 0 0;
  font-size: $font-size-xs;
  font-style: italic;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-salary__breakdown-btn {
  margin-top: $space-1;
  padding-left: 0;
}

// ─── Total ───────────────────────────────────────────────────────────────────
.mk-salary__total {
  display: flex;
  align-items: center;
  gap: $space-3;
  margin: 0 calc(-1 * $space-5);
  padding: $space-3 $space-5;
  border-top: 2px solid $surface-200;
  background: var(--p-primary-50);
  border-bottom-left-radius: $radius-lg;
  border-bottom-right-radius: $radius-lg;

  .app-dark & {
    border-top-color: var(--p-surface-200);
    background: var(--p-surface-200);
  }
}

.mk-salary__total-icon {
  color: $primary-900;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.mk-salary__total-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.mk-salary__total-plan {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  font-variant-numeric: tabular-nums;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.mk-salary__total-sep {
  font-size: $font-size-xs;
  color: $surface-500;
}

.mk-salary__total-fact {
  font-size: $font-size-lg;
  font-weight: $font-weight-bold;
  color: $primary-900;
  font-variant-numeric: tabular-nums;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

// ─── Popover ─────────────────────────────────────────────────────────────────
.mk-salary__pop {
  display: flex;
  flex-direction: column;
  min-width: 240px;
}

.mk-salary__pop-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-4;
  padding: $space-2 $space-1;
  text-decoration: none;
  border-radius: $radius-sm;
  transition: background var(--app-transition-fast);

  &:hover {
    background: var(--mg-surface-hover);
  }
}

.mk-salary__pop-company {
  font-size: $font-size-sm;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.mk-salary__pop-amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

@media (max-width: 575px) {
  .mk-salary__detail {
    padding-left: 0;
  }
  .mk-salary__cell-group {
    min-width: 100%;
  }
}
</style>
