<template>
  <InfoPanel
    :title="t('crm.company.sections.totals')"
    icon="pi-chart-bar"
    panel-key="company-totals"
    :default-collapsed="false"
  >
    <!-- Loading -->
    <div v-if="loading" class="multi-totals__skeleton">
      <Skeleton height="24px" class="mb-2" />
      <Skeleton height="24px" class="mb-2" />
      <Skeleton height="32px" />
    </div>

    <!-- Empty — no completed deals -->
    <div v-else-if="!totals || Object.keys(totals.per_currency).length === 0" class="multi-totals__empty">
      <i class="pi pi-chart-bar multi-totals__empty-icon" />
      <p class="multi-totals__empty-text">{{ t('company.page.totals.empty') }}</p>
    </div>

    <!-- Subtotals per currency + base total -->
    <div v-else class="multi-totals__content">
      <dl class="multi-totals__list">
        <template v-for="(kopecks, currency) in totals.per_currency" :key="currency">
          <dt class="multi-totals__currency">{{ currency }}</dt>
          <dd class="multi-totals__amount">{{ formatKopecks(kopecks, currency) }}</dd>
        </template>
      </dl>

      <!-- Divider -->
      <div class="multi-totals__divider" />

      <!-- Base total row -->
      <div class="multi-totals__base-row">
        <span class="multi-totals__base-label">
          {{ t('company.page.totals.total', { currency: totals.base_currency }) }}
        </span>
        <span class="multi-totals__base-value">
          {{ formatKopecks(totals.base_total, totals.base_currency) }}
        </span>
      </div>

      <p class="multi-totals__meta">
        {{ t('company.page.totals.asOf', { date: formatDate(totals.as_of_date) }) }}
      </p>
    </div>
  </InfoPanel>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import InfoPanel from '@/components/crm/entity/InfoPanel.vue'
import type { DealTotalsDto } from '@/entities/crm'

defineProps<{
  totals: DealTotalsDto | null | undefined
  loading: boolean
}>()

const { t } = useI18n()

/**
 * Format kopecks → human-readable currency string.
 * Money is ALWAYS integers (kopecks). Never float.
 */
function formatKopecks(kopecks: number, currency: string): string {
  const units = Math.round(kopecks / 100)
  try {
    return new Intl.NumberFormat('ru-RU', {
      style: 'currency',
      currency,
      minimumFractionDigits: 0,
      maximumFractionDigits: 0,
    }).format(units)
  } catch {
    return `${units.toLocaleString('ru-RU')} ${currency}`
  }
}

function formatDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })
}
</script>

<style lang="scss" scoped>
.multi-totals__skeleton {
  display: flex;
  flex-direction: column;
  padding: 0 0 $space-3;
}

.multi-totals__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-6 $space-4;
  text-align: center;
}

.multi-totals__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-300;
}

.multi-totals__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

.multi-totals__content {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  padding-bottom: $space-2;
}

.multi-totals__list {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: $space-1 $space-3;
  margin: 0;
}

.multi-totals__currency {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-500;
  display: flex;
  align-items: center;
  padding: 2px 0;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.multi-totals__amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  margin: 0;
  padding: 2px 0;
  text-align: right;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.multi-totals__divider {
  height: 1px;
  background: var(--p-surface-200);

  .app-dark & {
    background: var(--p-surface-700);
  }
}

.multi-totals__base-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
}

.multi-totals__base-label {
  font-size: $font-size-xs;
  color: $surface-600;
  font-weight: $font-weight-medium;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.multi-totals__base-value {
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $surface-900;

  .app-dark & {
    color: var(--p-surface-50);
  }
}

.multi-totals__meta {
  font-size: $font-size-xs;
  color: $surface-400;
  margin: 0;
}
</style>
