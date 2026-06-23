<template>
  <div class="company-mini-deals">
    <!-- Empty -->
    <div v-if="deals.length === 0" class="company-mini-deals__empty">
      <i class="pi pi-briefcase company-mini-deals__empty-icon" />
      <p class="company-mini-deals__empty-text">{{ t('company.page.deals.empty') }}</p>
    </div>

    <!-- Mini rows: Сделка · Этап · Сумма (3 columns) -->
    <div
      v-for="deal in deals"
      :key="deal.id"
      class="company-mini-deals__row"
    >
      <RouterLink :to="`/deals/${deal.id}`" class="company-mini-deals__name">
        {{ deal.title || `#${deal.id}` }}
      </RouterLink>
      <!-- E5: '22' (8%) opacity was too pale — bumped to '33' (20%) and text forced opaque for legibility -->
      <Tag
        v-if="deal.stage?.name"
        :value="deal.stage.name"
        severity="secondary"
        size="small"
        class="company-mini-deals__stage"
        :style="deal.stage?.color ? { background: deal.stage.color + '33', color: deal.stage.color, fontWeight: '600' } : {}"
      />
      <span v-else class="company-mini-deals__stage-empty">—</span>
      <span class="company-mini-deals__amount">{{ formatKopecks(deal.amount, deal.currency) }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'
import type { DealDto } from '@/entities/sales'

defineProps<{
  deals: DealDto[]
}>()

const { t } = useI18n()

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
</script>

<style lang="scss" scoped>
.company-mini-deals {
  display: flex;
  flex-direction: column;
}

.company-mini-deals__empty {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3;
  color: $surface-400;
  font-size: $font-size-sm;
}

.company-mini-deals__empty-icon {
  font-size: $font-size-md;
}

.company-mini-deals__empty-text {
  margin: 0;
}

// ── Row ───────────────────────────────────────────────────────────────────────

.company-mini-deals__row {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-100);
  transition: background var(--app-transition-fast);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-100);
    }
  }
}

.company-mini-deals__name {
  flex: 1;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: var(--p-primary-color);
  text-decoration: none;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &:hover {
    text-decoration: underline;
  }
}

.company-mini-deals__stage {
  flex-shrink: 0;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 120px; // fixed width for alignment
  overflow: hidden;
  text-overflow: ellipsis;
}

.company-mini-deals__stage-empty {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 120px;
  flex-shrink: 0;
  color: $surface-400;
  font-size: $font-size-sm;
}

.company-mini-deals__amount {
  flex-shrink: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $primary-900;
  text-align: right;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  min-width: 80px;

  .app-dark & {
    color: var(--p-primary-300);
  }
}
</style>
