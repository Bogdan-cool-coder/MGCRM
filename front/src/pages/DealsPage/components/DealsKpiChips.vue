<template>
  <div class="deals-kpi">
    <!-- In work: unique companies -->
    <span class="deals-kpi__chip deals-kpi__chip--brand">
      <i class="pi pi-building deals-kpi__chip-icon" />
      {{ t('sales.deals.page.kpi.inWork') }}:
      <strong>{{ t('sales.deals.page.kpi.inWorkValue', { n: inWork }) }}</strong>
    </span>

    <!-- Categories L/M/S -->
    <span class="deals-kpi__chip deals-kpi__chip--info">
      <i class="pi pi-chart-bar deals-kpi__chip-icon" />
      {{ t('sales.deals.page.kpi.categories') }}:
      <strong>{{ catL }}L / {{ catM }}M / {{ catS }}S</strong>
    </span>

    <!-- Won -->
    <span class="deals-kpi__chip deals-kpi__chip--success">
      <i class="pi pi-check-circle deals-kpi__chip-icon" />
      {{ t('sales.deals.page.kpi.won') }}:
      <strong>{{ won }}</strong>
    </span>

    <!-- No task -->
    <span class="deals-kpi__chip deals-kpi__chip--warning">
      <i class="pi pi-clock deals-kpi__chip-icon" />
      {{ t('sales.deals.page.kpi.noTask') }}:
      <strong>{{ noTask }}</strong>
    </span>

    <!-- Overdue -->
    <span class="deals-kpi__chip deals-kpi__chip--danger">
      <i class="pi pi-exclamation-circle deals-kpi__chip-icon" />
      {{ t('sales.deals.page.kpi.overdue') }}:
      <strong>{{ overdue }}</strong>
    </span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { DealDto } from '@/entities/sales'

const props = defineProps<{
  deals: DealDto[]
}>()

const { t } = useI18n()

const inWork = computed(() => {
  const ids = new Set<number>()
  for (const d of props.deals) {
    if (!d.stage.is_won) {
      ids.add(d.company.id)
    }
  }
  return ids.size
})

const catL = computed(() => props.deals.filter((d) => d.category === 'L').length)
const catM = computed(() => props.deals.filter((d) => d.category === 'M').length)
// S = S1 + S2
const catS = computed(() => props.deals.filter((d) => d.category === 'S1' || d.category === 'S2').length)

const won = computed(() => props.deals.filter((d) => d.stage.is_won).length)

const noTask = computed(() => props.deals.filter((d) => !d.next_task).length)

const overdue = computed(() => props.deals.filter((d) => d.next_task?.is_overdue).length)
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
    font-weight: $font-weight-semibold;
  }

  &--brand {
    background: $primary-100;
    color: $primary-900;

    .app-dark & {
      background: rgba(23, 39, 71, 0.35);
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
    color: var(--p-orange-700);

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
</style>
