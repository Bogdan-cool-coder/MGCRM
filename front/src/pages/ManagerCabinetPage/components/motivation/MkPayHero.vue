<template>
  <!--
    Pay hero (ManagerCabinet-v2 §3.2): salary fact as a 38px hero on the left,
    team-bonus forecast (60/40 split + gate) on the right. Replaces the sticky
    vertical MkTotalCard column with a 2-column panel.
  -->
  <section class="mk-payhero">
    <!-- Left: salary -->
    <div class="mk-payhero__pane">
      <span class="mk-eyebrow">{{ t('motivation.card.salary_for', { period: periodLabel }) }}</span>
      <div class="mk-payhero__hero">{{ formatMkMoney(total.salary_fact_kopecks, total.currency) }}</div>
      <div class="mk-payhero__plan-line">
        <span class="mk-payhero__plan">
          {{ t('motivation.card.plan_short') }} {{ formatMkMoney(total.salary_plan_kopecks, total.currency) }}
        </span>
        <span v-if="factSource === 'won_deals'" class="mk-payhero__basis">
          {{ t('motivation.card.fact_basis_short') }}
          <i
            v-tooltip.top="t('motivation.card.fact_basis_won_deals_hint')"
            class="pi pi-info-circle"
            aria-hidden="true"
          />
        </span>
      </div>
    </div>

    <!-- Right: team-bonus forecast -->
    <div v-if="forecast" class="mk-payhero__pane mk-payhero__pane--right">
      <div class="mk-payhero__forecast-head">
        <span class="mk-eyebrow">{{ t('motivation.card.team_bonus_forecast') }}</span>
        <PctTag :value="forecast.dept_pct" size="md" quiet />
      </div>

      <div class="mk-payhero__parts">
        <div class="mk-payhero__kv">
          <span class="mk-payhero__kv-label">{{ t('motivation.card.team_bonus_part1') }}</span>
          <span class="mk-payhero__kv-value">
            {{ formatMkMoney(forecast.part1_kopecks, forecast.currency) }}
          </span>
        </div>
        <div class="mk-payhero__kv">
          <span class="mk-payhero__kv-label">{{ t('motivation.card.team_bonus_part2') }}</span>
          <span class="mk-payhero__kv-value">
            {{ formatMkMoney(forecast.part2_kopecks, forecast.currency) }}
          </span>
        </div>
      </div>

      <div class="mk-payhero__total-row">
        <span class="mk-payhero__total-label">{{ t('motivation.card.forecast_total') }}</span>
        <span class="mk-payhero__total-value">
          {{ formatMkMoney(forecast.total_kopecks, forecast.currency) }}
        </span>
      </div>

      <div
        class="mk-payhero__gate"
        :class="`mk-payhero__gate--${forecast.gate_passed ? 'passed' : 'locked'}`"
      >
        <i
          :class="forecast.gate_passed ? 'pi pi-check-circle' : 'pi pi-lock'"
          aria-hidden="true"
        />
        <span>
          <template v-if="forecast.gate_passed">
            {{ t('motivation.card.gate_reached') }}
          </template>
          <template v-else>
            {{ t('motivation.card.gate_locked_short', {
              pct: formatMkPct(forecast.dept_pct),
              threshold: forecast.threshold_pct,
            }) }}
          </template>
        </span>
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PctTag from '@/components/shared/PctTag.vue'
import { formatMkMoney, formatMkPct } from '@/utils/motivation'
import type { MkTotal, MkTeamBonusForecast, FactBasis } from '@/entities/motivation'

defineProps<{
  total: MkTotal
  forecast: MkTeamBonusForecast | null
  factSource: FactBasis
  periodLabel: string
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
@use '@/pages/ManagerCabinetPage/components/motivation/mk-shared' as *;

.mk-payhero {
  display: grid;
  grid-template-columns: 1.3fr 1fr;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.mk-payhero__pane {
  padding: $space-5 $space-6;
  min-width: 0;
}

.mk-payhero__pane--right {
  border-left: 1px solid $surface-200;

  .app-dark & {
    border-left-color: var(--p-surface-200);
  }
}

.mk-payhero__hero {
  margin: $space-2 0 $space-1;
  font-size: $font-size-4xl;
  font-weight: $font-weight-bold;
  line-height: $line-height-tight;
  letter-spacing: -0.01em;
  // Hero salary — accent token; self-lightens on navy in dark.
  color: var(--p-primary-color);
  font-variant-numeric: tabular-nums;
}

.mk-payhero__plan-line {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
}

.mk-payhero__plan {
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.mk-payhero__basis {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

// ── Forecast (right) ─────────────────────────────────────────────────────────
.mk-payhero__forecast-head {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
}

.mk-payhero__parts {
  margin: $space-3 0;
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.mk-payhero__kv {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
}

.mk-payhero__kv-label {
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.mk-payhero__kv-value {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

.mk-payhero__total-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  padding-top: $space-2;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.mk-payhero__total-label {
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.mk-payhero__total-value {
  font-size: $font-size-lg;
  font-weight: $font-weight-bold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

.mk-payhero__gate {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-top: $space-3;
  font-size: $font-size-xs;

  &--passed {
    color: $status-success-text;
  }

  &--locked {
    color: $status-warning-text;
  }
}

// ── Tablet / mobile: forecast under salary ───────────────────────────────────
@media (max-width: 991px) {
  .mk-payhero {
    grid-template-columns: 1fr;
  }

  .mk-payhero__pane--right {
    border-left: none;
    border-top: 1px solid $surface-200;

    .app-dark & {
      border-top-color: var(--p-surface-200);
    }
  }
}
</style>
