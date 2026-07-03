<template>
  <aside class="mk-card mk-total">
    <!-- ЗП план -->
    <div class="mk-total__block">
      <span class="mk-eyebrow">{{ t('motivation.card.total_plan') }}</span>
      <span class="mk-total__value mk-total__value--plan">
        {{ formatMkMoney(total.salary_plan_kopecks, total.currency) }}
      </span>
    </div>

    <!-- ЗП факт (hero) -->
    <div class="mk-total__block">
      <span class="mk-eyebrow">{{ t('motivation.card.total_fact') }}</span>
      <span class="mk-total__value mk-total__value--hero">
        {{ formatMkMoney(total.salary_fact_kopecks, total.currency) }}
      </span>
      <span class="mk-total__abbrev">
        {{ formatMkAbbrev(total.salary_fact_kopecks, total.currency) }}
      </span>
      <!-- Interim fact basis (Phase A) -->
      <span v-if="factSource === 'won_deals'" class="mk-total__basis">
        <i
          v-tooltip.top="t('motivation.card.fact_basis_won_deals')"
          class="pi pi-info-circle"
          aria-hidden="true"
        />
        {{ t('motivation.card.fact_basis_won_deals') }}
      </span>
    </div>

    <template v-if="forecast">
      <hr class="mk-total__divider" />

      <!-- Team bonus forecast -->
      <div class="mk-total__block">
        <span class="mk-eyebrow">{{ t('motivation.card.team_bonus_forecast') }}</span>
        <div class="mk-total__badge-row">
          <span class="mk-label">{{ t('motivation.card.dept_pct') }}</span>
          <PctTag :value="forecast.dept_pct" size="md" />
        </div>

        <div class="mk-total__part">
          <span class="mk-total__part-label">{{ t('motivation.card.team_bonus_part1') }}</span>
          <span class="mk-total__part-value">
            {{ formatMkMoney(forecast.part1_kopecks, forecast.currency) }}
          </span>
        </div>
        <div class="mk-total__part">
          <span class="mk-total__part-label">{{ t('motivation.card.team_bonus_part2') }}</span>
          <span class="mk-total__part-value">
            {{ formatMkMoney(forecast.part2_kopecks, forecast.currency) }}
          </span>
        </div>
      </div>

      <hr class="mk-total__divider" />

      <!-- Forecast total -->
      <div class="mk-total__block">
        <span class="mk-eyebrow">{{ t('motivation.card.forecast_total') }}</span>
        <span class="mk-total__value mk-total__value--forecast">
          {{ formatMkMoney(forecast.total_kopecks, forecast.currency) }}
        </span>
      </div>

      <!-- Gate -->
      <div class="mk-total__gate" :class="`mk-total__gate--${forecast.gate_passed ? 'passed' : 'locked'}`">
        <i
          :class="forecast.gate_passed ? 'pi pi-check-circle' : 'pi pi-lock'"
          class="mk-total__gate-icon"
          aria-hidden="true"
        />
        <span class="mk-total__gate-text">
          <template v-if="forecast.gate_passed">
            {{ t('motivation.card.gate_passed') }}: {{ formatMkPct(forecast.dept_pct) }}
          </template>
          <template v-else>
            {{ t('motivation.card.gate_locked') }}
            <small class="mk-total__gate-hint">
              {{ t('motivation.card.gate_hint', {
                pct: formatMkPct(forecast.dept_pct),
                threshold: forecast.threshold_pct,
              }) }}
            </small>
          </template>
        </span>
      </div>
    </template>
  </aside>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PctTag from '@/components/shared/PctTag.vue'
import { formatMkMoney, formatMkAbbrev, formatMkPct } from '@/utils/motivation'
import type { MkTotal, MkTeamBonusForecast, FactBasis } from '@/entities/motivation'

defineProps<{
  total: MkTotal
  forecast: MkTeamBonusForecast | null
  factSource: FactBasis
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
@use '@/pages/ManagerCabinetPage/components/motivation/mk-shared' as *;

.mk-total {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  padding: $space-5 $space-6;
  position: sticky;
  top: $space-6;
}

.mk-total__block {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.mk-total__value {
  font-variant-numeric: tabular-nums;
  color: $surface-900;

  &--plan {
    font-size: $font-size-lg;
    font-weight: $font-weight-semibold;
  }

  &--hero {
    // SPEC §Фиксы «криво» #1/#6 — hero через var(--p-primary-color), не хардкод navy.
    font-size: $font-size-3xl;
    font-weight: $font-weight-bold;
    line-height: $line-height-tight;
    color: var(--p-primary-color);
  }

  &--forecast {
    font-size: $font-size-2xl;
    font-weight: $font-weight-bold;
  }
}

.mk-total__abbrev {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-total__basis {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  margin-top: $space-1;
  font-size: $font-size-2xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.mk-total__divider {
  border: none;
  border-top: 1px solid $surface-200;
  margin: $space-1 0;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.mk-total__badge-row {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.mk-total__part {
  display: flex;
  align-items: baseline;
  justify-content: space-between;
  gap: $space-3;
}

.mk-total__part-label {
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.mk-total__part-value {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  font-variant-numeric: tabular-nums;
}

.mk-total__gate {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-radius: $radius-md;
  background: $surface-100;

  .app-dark & {
    background: var(--p-surface-100);
  }

  &--passed .mk-total__gate-icon {
    color: var(--p-green-500);
  }
  &--locked .mk-total__gate-icon {
    color: var(--p-orange-500);
  }
}

.mk-total__gate-icon {
  font-size: $font-size-base;
  flex-shrink: 0;
  margin-top: 2px;
}

.mk-total__gate-text {
  display: flex;
  flex-direction: column;
  font-size: $font-size-sm;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.mk-total__gate-hint {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}
</style>
