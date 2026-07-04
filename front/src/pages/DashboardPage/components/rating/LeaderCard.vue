<template>
  <section class="leader-card">
    <!-- Trophy + avatar cluster -->
    <div class="leader-card__figure">
      <i class="pi pi-trophy leader-card__trophy" aria-hidden="true" />
      <EntityAvatar
        :name="leader.user.full_name"
        :entity-id="leader.user.id"
        :pixel-size="72"
      />
    </div>

    <!-- Identity + hero points -->
    <div class="leader-card__body">
      <p class="leader-card__eyebrow">{{ t('dashboard.rating.leader_of', { year }) }}</p>
      <h2 class="leader-card__name">{{ leader.user.full_name }}</h2>
      <p class="leader-card__division">
        <i class="pi pi-sitemap leader-card__division-icon" aria-hidden="true" />
        {{ leader.division }}
      </p>
    </div>

    <!-- Hero points + supporting metrics -->
    <div class="leader-card__stats">
      <div class="leader-card__points">
        <span class="leader-card__points-value">{{ formatPoints(leader.total_points) }}</span>
        <span class="leader-card__points-label">{{ t('dashboard.rating.points') }}</span>
      </div>
      <dl class="leader-card__metrics">
        <div class="leader-card__metric">
          <dt>{{ t('dashboard.rating.col_deals') }}</dt>
          <dd>{{ formatCount(leader.won_deals) }}</dd>
        </div>
        <div class="leader-card__metric">
          <dt>{{ t('dashboard.rating.col_income') }}</dt>
          <dd>{{ formatMkMoney(leader.new_income_base_kopecks, baseCurrency) }}</dd>
        </div>
      </dl>
    </div>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'
import { formatMkMoney } from '@/utils/motivation'
import type { BestManagerLeader } from '@/entities/reports'

defineProps<{
  leader: BestManagerLeader
  year: number
  baseCurrency: string
}>()

const { t, locale } = useI18n()

const numberLocale = () => (locale.value === 'en' ? 'en-US' : 'ru-RU')

const formatPoints = (n: number): string =>
  new Intl.NumberFormat(numberLocale()).format(n)

const formatCount = (n: number): string =>
  new Intl.NumberFormat(numberLocale()).format(n)
</script>

<style lang="scss" scoped>
// $surface-* / $primary-color alias to --p-* which PrimeVue inverts on .app-dark,
// so the surfaces are theme-reactive from the BASE rule. The primary accent (navy
// in light / lighter navy in dark) needs the one dark override on the hero number.
.leader-card {
  display: flex;
  align-items: center;
  flex-wrap: wrap;
  gap: $space-5;
  padding: $space-5 $space-6;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-left: 3px solid $primary-color;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;

  // The base $surface-200 border needs the dark override on this scoped element;
  // the brand-navy left accent lifts to the theme-reactive primary in dark.
  .app-dark & {
    border-color: var(--p-surface-200);
    border-left-color: var(--p-primary-color);
  }
}

// ── Figure (trophy badge over avatar) ────────────────────────────────────────
.leader-card__figure {
  position: relative;
  flex: 0 0 auto;
}

.leader-card__trophy {
  position: absolute;
  top: calc(-1 * $space-2);
  left: calc(-1 * $space-2);
  z-index: 1;
  font-size: $font-size-lg;
  color: $orange-500;

  .app-dark & {
    color: var(--p-orange-400);
  }
}

// ── Body (identity) ──────────────────────────────────────────────────────────
.leader-card__body {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  min-width: 0;
}

.leader-card__eyebrow {
  margin: 0;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: $surface-500;
}

.leader-card__name {
  margin: 0;
  font-size: $font-size-xl;
  font-weight: $font-weight-bold;
  color: $surface-900;
}

.leader-card__division {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-600;
}

.leader-card__division-icon {
  font-size: $font-size-xs;
}

// ── Stats (hero points + metrics) ────────────────────────────────────────────
.leader-card__stats {
  display: flex;
  align-items: center;
  gap: $space-6;
  margin-left: auto;
  flex-wrap: wrap;
}

.leader-card__points {
  display: flex;
  flex-direction: column;
  align-items: flex-end;
}

.leader-card__points-value {
  font-size: $font-size-3xl;
  font-weight: $font-weight-bold;
  line-height: $line-height-tight;
  font-variant-numeric: tabular-nums;
  color: $primary-color;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.leader-card__points-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.04em;
  color: $surface-500;
}

.leader-card__metrics {
  display: flex;
  gap: $space-5;
  margin: 0;
}

.leader-card__metric {
  display: flex;
  flex-direction: column;
  gap: $space-1;

  dt {
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
    color: $surface-500;
  }

  dd {
    margin: 0;
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    font-variant-numeric: tabular-nums;
    color: $surface-800;
  }
}

// ── Adaptive (≤1280) ─────────────────────────────────────────────────────────
@media (max-width: 1280px) {
  .leader-card__stats {
    margin-left: 0;
    width: 100%;
    justify-content: space-between;
  }
}
</style>
