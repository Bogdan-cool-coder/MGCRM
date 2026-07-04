<template>
  <Card class="widget-card h-100">
    <template #title>{{ t('dashboard.forecast.title') }}</template>
    <template #content>
      <!-- Loading skeleton: hero + bar + 3 rows -->
      <template v-if="loading">
        <div class="forecast-skeleton">
          <Skeleton height="18px" width="60%" border-radius="6px" class="mb-3" />
          <Skeleton height="32px" width="55%" border-radius="6px" class="mb-3" />
          <Skeleton height="12px" border-radius="999px" class="mb-3" />
          <Skeleton v-for="n in 3" :key="n" height="40px" border-radius="8px" class="mb-2" />
        </div>
      </template>

      <!-- Empty state -->
      <template v-else-if="!forecast">
        <div class="widget-empty">
          <i class="pi pi-calculator widget-empty__icon" />
          <p class="widget-empty__text">{{ t('dashboard.empty.noForecast') }}</p>
        </div>
      </template>

      <!-- Content: hero + composition bar + legend -->
      <template v-else>
        <p class="forecast__caption">{{ t('dashboard.forecast.weightedOnPeriod') }}</p>
        <p class="forecast__hero">
          {{ formatMoney(forecast.total_weighted_kopecks, locale, baseCurrency) }}
        </p>

        <!-- Stacked composition bar (HOT / Warm / Trial) -->
        <div
          class="forecast__bar"
          :class="{ 'forecast__bar--empty': totalParts === 0 }"
          role="img"
          :aria-label="t('dashboard.forecast.title')"
        >
          <template v-if="totalParts > 0">
            <span
              v-for="p in parts"
              :key="p.key"
              class="forecast__bar-seg"
              :class="`forecast__bar-seg--${p.key}`"
              :style="{ width: `${p.pct}%` }"
              :title="`${p.label} · ${p.pct}%`"
            />
          </template>
        </div>

        <!-- Legend rows (NOT clickable — ГЭП-4, hover-highlight only) -->
        <div class="forecast__legend">
          <div
            v-for="p in parts"
            :key="p.key"
            class="forecast__row"
          >
            <span class="forecast__row-tile" :class="`forecast__row-tile--${p.key}`">
              <i :class="['pi', p.icon]" />
            </span>
            <span class="forecast__row-label">{{ p.label }}</span>
            <span class="forecast__row-pct">{{ totalParts > 0 ? `${p.pct}%` : '—' }}</span>
            <span class="forecast__row-amount">
              {{ formatMoney(p.kopecks, locale, baseCurrency) }}
            </span>
          </div>
        </div>
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Skeleton from 'primevue/skeleton'
import type { ForecastData } from '@/entities/salesDashboard'
import { formatMoney } from '@/utils/chartFormatters'

const { t, locale } = useI18n()

const props = defineProps<{
  forecast: ForecastData | null
  baseCurrency: string
  loading: boolean
}>()

type PartKey = 'hot' | 'warm' | 'trial'

interface ForecastPart {
  key: PartKey
  icon: string
  label: string
  kopecks: number
  pct: number
}

const totalParts = computed<number>(() => {
  const f = props.forecast
  if (!f) return 0
  return f.hot_kopecks + f.warm_kopecks + f.trial_kopecks
})

const parts = computed<ForecastPart[]>(() => {
  const f = props.forecast
  if (!f) return []
  const total = totalParts.value
  const pct = (k: number): number => (total > 0 ? Math.round((k / total) * 100) : 0)
  return [
    { key: 'hot', icon: 'pi-fire', label: t('dashboard.forecast.hot'), kopecks: f.hot_kopecks, pct: pct(f.hot_kopecks) },
    { key: 'warm', icon: 'pi-sun', label: t('dashboard.forecast.warm'), kopecks: f.warm_kopecks, pct: pct(f.warm_kopecks) },
    { key: 'trial', icon: 'pi-clock', label: t('dashboard.forecast.trial'), kopecks: f.trial_kopecks, pct: pct(f.trial_kopecks) },
  ]
})
</script>

<style lang="scss" scoped>
.widget-card {
  :deep(.p-card-title) {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    color: $surface-800;
  }
}

.widget-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-6;
  gap: $space-3;
  min-height: 160px;
}

.widget-empty__icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;
}

.widget-empty__text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}

// ─── Hero ────────────────────────────────────────────────────────────────────
.forecast__caption {
  margin: 0 0 $space-1;
  font-size: $font-size-xs;
  color: $surface-500;
}

.forecast__hero {
  margin: 0 0 $space-4;
  font-size: $font-size-icon-lg; // 32px hero number
  font-weight: $font-weight-bold;
  color: $surface-900;
  line-height: 1;
}

// ─── Stacked composition bar ─────────────────────────────────────────────────
.forecast__bar {
  display: flex;
  height: 12px;
  border-radius: $radius-pill;
  overflow: hidden;
  gap: 2px;
  margin-bottom: $space-4;
  background: $surface-100;
}

.forecast__bar--empty {
  background: $surface-200;
}

.forecast__bar-seg {
  height: 100%;

  // Composition colours read on both themes via PrimeVue palette tokens.
  &--hot {
    background: var(--p-orange-500);
  }

  &--warm {
    background: $status-warning-text;
  }

  &--trial {
    background: var(--p-blue-500);
  }
}

// ─── Legend rows ─────────────────────────────────────────────────────────────
.forecast__legend {
  display: flex;
  flex-direction: column;
}

.forecast__row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-1;
  border-top: 1px solid $surface-200;
  transition: background var(--app-transition-fast);

  &:hover {
    background: var(--mg-surface-hover);
  }
}

.forecast__row-tile {
  width: 24px;
  height: 24px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: $radius-sm;
  font-size: $font-size-xs;

  // Tinted plate = 15% of the segment colour over the card surface.
  &--hot {
    color: var(--p-orange-500);
    background: color-mix(in srgb, var(--p-orange-500) 15%, $surface-card);
  }

  &--warm {
    color: $status-warning-text;
    background: color-mix(in srgb, $status-warning-text 15%, $surface-card);
  }

  &--trial {
    color: var(--p-blue-500);
    background: color-mix(in srgb, var(--p-blue-500) 15%, $surface-card);
  }
}

.forecast__row-label {
  flex: 1;
  min-width: 0;
  font-size: $font-size-sm;
  color: $surface-800;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.forecast__row-pct {
  width: 40px;
  text-align: right;
  font-size: $font-size-xs;
  color: $surface-500;
  flex-shrink: 0;
}

.forecast__row-amount {
  width: 96px;
  text-align: right;
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  color: $surface-900;
  flex-shrink: 0;
}
</style>
