<template>
  <div class="deals-kpi">
    <!-- Loading skeleton: show muted chips while fetching -->
    <template v-if="props.loading">
      <span v-for="i in 5" :key="i" class="deals-kpi__chip deals-kpi__chip--skeleton" aria-hidden="true">
        <i class="pi pi-spin pi-spinner deals-kpi__chip-icon" />
        &nbsp;
      </span>
    </template>

    <template v-else>
      <!-- In work: unique companies with non-won deals -->
      <span class="deals-kpi__chip deals-kpi__chip--brand">
        <i class="pi pi-briefcase deals-kpi__chip-icon" />
        {{ t('sales.deals.page.kpi.inWork') }}:
        <strong>{{ t('sales.deals.page.kpi.inWorkValue', { n: props.kpi.in_work }) }}</strong>
      </span>

      <!-- Categories L/M/S -->
      <span class="deals-kpi__chip deals-kpi__chip--info">
        <i class="pi pi-tags deals-kpi__chip-icon" />
        {{ t('sales.deals.page.kpi.categories') }}:
        <strong>{{ props.kpi.cat_l }}L / {{ props.kpi.cat_m }}M / {{ props.kpi.cat_s }}S</strong>
      </span>

      <!-- Won -->
      <span class="deals-kpi__chip deals-kpi__chip--success">
        <i class="pi pi-check-circle deals-kpi__chip-icon" />
        {{ t('sales.deals.page.kpi.won') }}:
        <strong>{{ props.kpi.won }}</strong>
      </span>

      <!-- No task -->
      <span class="deals-kpi__chip deals-kpi__chip--warning">
        <i class="pi pi-clock deals-kpi__chip-icon" />
        {{ t('sales.deals.page.kpi.noTask') }}:
        <strong>{{ props.kpi.no_task }}</strong>
      </span>

      <!-- Overdue -->
      <span class="deals-kpi__chip deals-kpi__chip--danger">
        <i class="pi pi-exclamation-circle deals-kpi__chip-icon" />
        {{ t('sales.deals.page.kpi.overdue') }}:
        <strong>{{ props.kpi.overdue }}</strong>
      </span>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { DealKpiDto } from '@/entities/sales'

const props = defineProps<{
  kpi: DealKpiDto
  loading?: boolean
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.deals-kpi {
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-lg;
  box-shadow: $shadow-card;
  padding: 12px 14px;
  margin-bottom: 14px;
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
}

.deals-kpi__chip {
  display: inline-flex;
  align-items: center;
  gap: 7px;
  padding: 6px 13px;
  border-radius: $radius-pill;
  font-size: $font-size-xs;

  strong {
    font-weight: $font-weight-bold;
  }

  &--skeleton {
    background: var(--p-surface-200);
    color: transparent;
    min-width: 90px;
    animation: kpi-pulse 1.2s ease-in-out infinite;

    .app-dark & {
      background: var(--p-surface-100);
    }
  }

  &--brand {
    background: $primary-100;
    color: $primary-900;

    .app-dark & {
      background: color-mix(in srgb, #{$primary-900} 35%, transparent);
      color: var(--p-primary-200);
    }
  }

  &--info {
    background: var(--p-blue-50);
    color: var(--p-blue-700);

    .app-dark & {
      background: rgba(30, 80, 200, 0.2);
      color: var(--p-blue-300);
    }
  }

  &--success {
    background: var(--p-green-50);
    color: var(--p-green-700);

    .app-dark & {
      background: rgba(34, 130, 70, 0.2);
      color: var(--p-green-300);
    }
  }

  &--warning {
    background: var(--p-orange-50);
    color: var(--p-orange-900);

    .app-dark & {
      background: rgba(200, 120, 30, 0.2);
      color: var(--p-orange-300);
    }
  }

  &--danger {
    background: var(--p-red-50);
    color: var(--p-red-700);

    .app-dark & {
      background: rgba(200, 50, 50, 0.2);
      color: var(--p-red-300);
    }
  }
}

.deals-kpi__chip-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

@keyframes kpi-pulse {
  0%, 100% { opacity: 1; }
  50% { opacity: 0.4; }
}
</style>
