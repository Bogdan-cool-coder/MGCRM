<template>
  <Card class="widget-card h-100">
    <template #title>{{ t('dashboard.forecast.title') }}</template>
    <template #content>
      <!-- Loading skeleton -->
      <template v-if="loading">
        <div class="row g-3">
          <div v-for="n in 4" :key="n" class="col-6">
            <Skeleton height="72px" border-radius="8px" />
          </div>
        </div>
      </template>

      <!-- Empty state -->
      <template v-else-if="!forecast">
        <div class="widget-empty">
          <i class="pi pi-calculator widget-empty__icon" />
          <p class="widget-empty__text">{{ t('dashboard.empty.noForecast') }}</p>
        </div>
      </template>

      <!-- KPI cards 2×2 -->
      <template v-else>
        <div class="row g-3">
          <div
            v-for="item in forecastItems"
            :key="item.key"
            class="col-6"
          >
            <div class="forecast-kpi">
              <div class="forecast-kpi__header">
                <i :class="['pi', item.icon, 'forecast-kpi__icon']" />
                <span class="forecast-kpi__label">{{ item.label }}</span>
              </div>
              <div
                class="forecast-kpi__amount"
                :class="item.amountClass"
              >
                {{ formatMoney(item.kopecks, locale, props.baseCurrency) }}
              </div>
            </div>
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

const forecastItems = computed(() => {
  if (!props.forecast) return []
  return [
    {
      key: 'weighted',
      icon: 'pi-calculator',
      label: t('dashboard.forecast.totalWeighted'),
      kopecks: props.forecast.total_weighted_kopecks,
      amountClass: 'forecast-kpi__amount--weighted',
    },
    {
      key: 'hot',
      icon: 'pi-fire',
      label: t('dashboard.forecast.hot'),
      kopecks: props.forecast.hot_kopecks,
      amountClass: 'forecast-kpi__amount--hot',
    },
    {
      key: 'warm',
      icon: 'pi-sun',
      label: t('dashboard.forecast.warm'),
      kopecks: props.forecast.warm_kopecks,
      amountClass: '',
    },
    {
      key: 'trial',
      icon: 'pi-clock',
      label: t('dashboard.forecast.trial'),
      kopecks: props.forecast.trial_kopecks,
      amountClass: '',
    },
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

.forecast-kpi {
  border: 1px solid $surface-200;
  background-color: $surface-50;
  border-radius: $radius-md;
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-2;

  // Dark mode override — $surface-50 is very light in dark themes
  :global(.app-dark) & {
    background-color: $surface-card;
  }
}

.forecast-kpi__header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.forecast-kpi__icon {
  font-size: $font-size-sm;
  color: $surface-500;
}

.forecast-kpi__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-600;
}

.forecast-kpi__amount {
  font-size: $font-size-md;
  font-weight: $font-weight-bold;
  color: $surface-900;

  &--weighted {
    font-size: $font-size-lg;
  }

  &--hot {
    color: var(--p-orange-500);
  }
}
</style>
