<template>
  <div class="tab-registry">
    <!-- Endpoint not yet deployed (parallel backend) -->
    <Message
      v-if="endpointMissing && !loading"
      severity="info"
      :closable="false"
      icon="pi pi-info-circle"
      class="tab-registry__notice"
    >
      {{ t('dashboard.registry.endpoint_missing') }}
    </Message>

    <!-- Multi-currency warning -->
    <Message
      v-else-if="report?.meta?.multi_currency_warning"
      severity="warn"
      :closable="false"
      icon="pi pi-info-circle"
      class="tab-registry__notice"
    >
      {{ t('dashboard.multiCurrencyWarning') }}
    </Message>

    <!-- Loading skeleton shaped like two table sections -->
    <div v-if="loading" class="tab-registry__skeleton">
      <Skeleton height="2rem" width="18rem" class="mb-3" />
      <Skeleton v-for="n in 4" :key="`e${n}`" height="2.75rem" class="mb-2" />
      <Skeleton height="2rem" width="16rem" class="mt-5 mb-3" />
      <Skeleton v-for="n in 3" :key="`s${n}`" height="2.75rem" class="mb-2" />
    </div>

    <template v-else-if="report">
      <!-- Squeeze «no pay date» highlight — expandable list -->
      <Message
        v-if="noDateCount > 0"
        severity="warn"
        :closable="false"
        icon="pi pi-exclamation-triangle"
        class="tab-registry__notice"
      >
        <div class="tab-registry__no-date">
          <button
            type="button"
            class="tab-registry__no-date-toggle"
            @click="noDateOpen = !noDateOpen"
          >
            {{ t('dashboard.registry.no_date_banner', { n: noDateCount }) }}
            <i
              :class="noDateOpen ? 'pi pi-chevron-up' : 'pi pi-chevron-down'"
              aria-hidden="true"
            />
          </button>
          <ul v-if="noDateOpen" class="tab-registry__no-date-list">
            <li v-for="row in noDateRows" :key="row.deal_id">
              <RouterLink :to="`/deals/${row.deal_id}`" class="tab-registry__no-date-link">
                {{ row.title }}
              </RouterLink>
              <span class="tab-registry__no-date-amount">
                {{ formatMkMoney(row.amount_base_kopecks, baseCurrency) }}
              </span>
            </li>
          </ul>
        </div>
      </Message>

      <!-- StatCard summary strip: expected (info) + squeeze (danger). Reuses the
           tab-schedule__kpi tile so the two report totals read at a glance (§3.2). -->
      <div class="tab-registry__stats">
        <div class="tab-registry__stat">
          <span class="tab-registry__stat-label">
            <i class="pi pi-wallet tab-registry__stat-icon" aria-hidden="true" />
            {{ t('dashboard.registry.stat_expected') }}
          </span>
          <span class="tab-registry__stat-value">
            {{ formatMkMoney(report.expected.total_base_kopecks, baseCurrency) }}
          </span>
        </div>
        <div class="tab-registry__stat">
          <span class="tab-registry__stat-label">
            <i
              class="pi pi-exclamation-triangle tab-registry__stat-icon tab-registry__stat-icon--danger"
              aria-hidden="true"
            />
            {{ t('dashboard.registry.stat_squeeze') }}
          </span>
          <span class="tab-registry__stat-value tab-registry__stat-value--danger">
            {{ formatMkMoney(report.squeeze.total_base_kopecks, baseCurrency) }}
          </span>
        </div>
      </div>

      <!-- Expected income -->
      <RegistryTable
        :rows="report.expected.rows"
        :total-base-kopecks="report.expected.total_base_kopecks"
        :title="t('dashboard.registry.expected_title')"
        :period-label="periodLabel"
        :empty-text="t('dashboard.registry.empty_expected')"
        :base-currency="baseCurrency"
        severity="info"
        class="tab-registry__section"
      />

      <!-- Squeeze (overdue) -->
      <RegistryTable
        :rows="report.squeeze.rows"
        :total-base-kopecks="report.squeeze.total_base_kopecks"
        :title="t('dashboard.registry.slippage_title')"
        :period-label="periodLabel"
        :empty-text="t('dashboard.registry.empty_slippage')"
        :base-currency="baseCurrency"
        severity="danger"
        class="tab-registry__section"
      />
    </template>

    <!-- No data (endpoint-missing already shows its own hint above) -->
    <div v-else-if="!endpointMissing" class="tab-registry__empty">
      <i class="pi pi-inbox tab-registry__empty-icon" aria-hidden="true" />
      <h3 class="tab-registry__empty-title">{{ t('dashboard.registry.empty_period') }}</h3>
      <p class="tab-registry__empty-hint">{{ t('dashboard.registry.empty_period_hint') }}</p>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import RegistryTable from '../registry/RegistryTable.vue'
import { useRegistryTab } from '../../composables/useRegistryTab'
import { formatMkMoney } from '@/utils/motivation'
import type { RegistryRow } from '@/entities/reports'

const props = defineProps<{
  year: number
  month: number
  pipelineId: number | null
  managerId: number | null
}>()

const { t } = useI18n()

const { report, loading, endpointMissing } = useRegistryTab({
  year: () => props.year,
  month: () => props.month,
  pipelineId: () => props.pipelineId,
  managerId: () => props.managerId,
})

const baseCurrency = computed<string>(() => report.value?.meta.base_currency ?? 'RUB')

const periodLabel = computed<string>(() => report.value?.meta.period.label ?? '')

const noDateCount = computed<number>(() => report.value?.squeeze.no_date_count ?? 0)

// The no-date deals are their own list on the block — the squeeze `rows` are
// built with whereNotNull(expected_payment_date), so filtering them for a null
// date is always empty. Field is optional until the backend ships it.
const noDateRows = computed<RegistryRow[]>(() => report.value?.squeeze.no_date_rows ?? [])

const noDateOpen = ref(false)
</script>

<style lang="scss" scoped>
.tab-registry {
  display: flex;
  flex-direction: column;
  gap: $space-6;
}

.tab-registry__notice {
  margin: 0;
}

.tab-registry__skeleton {
  padding: $space-2 0;
}

// ── StatCard summary strip (§3.2) — reuses the tab-schedule__kpi tile style ────
.tab-registry__stats {
  display: grid;
  grid-template-columns: repeat(2, 1fr);
  gap: $space-3;
}

.tab-registry__stat {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  padding: $space-3 $space-4;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  background: $surface-card;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.tab-registry__stat-label {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-500;
}

.tab-registry__stat-icon {
  font-size: $font-size-sm;
  color: var(--p-blue-500);

  &--danger {
    color: var(--p-red-500);
  }

  .app-dark & {
    color: var(--p-blue-400);
  }

  .app-dark &--danger {
    color: var(--p-red-400);
  }
}

.tab-registry__stat-value {
  font-size: $font-size-lg;
  font-weight: $font-weight-bold;
  font-variant-numeric: tabular-nums;
  color: $surface-900;

  &--danger {
    color: var(--p-red-500);
  }

  .app-dark &--danger {
    color: var(--p-red-400);
  }
}

@media (max-width: 768px) {
  .tab-registry__stats {
    grid-template-columns: 1fr;
  }
}

// ── No-date banner (expandable) ──────────────────────────────────────────────
.tab-registry__no-date {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  width: 100%;
}

.tab-registry__no-date-toggle {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: inherit;
}

.tab-registry__no-date-list {
  list-style: none;
  margin: 0;
  padding: 0;
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.tab-registry__no-date-list li {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  font-size: $font-size-sm;
}

.tab-registry__no-date-link {
  color: $primary-color;
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.tab-registry__no-date-amount {
  font-variant-numeric: tabular-nums;
  font-weight: $font-weight-semibold;
  white-space: nowrap;
}

// ── Empty state ──────────────────────────────────────────────────────────────
.tab-registry__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 260px;
  padding: $space-8 $space-6;
  text-align: center;
}

// $surface-* inverts on .app-dark via PrimeVue — reactive from the base rule.
.tab-registry__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;
}

.tab-registry__empty-title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;
}

.tab-registry__empty-hint {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-500;
}
</style>
