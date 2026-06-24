<template>
  <div class="widget-status-groups">
    <!-- Loading skeleton -->
    <template v-if="loading">
      <div class="row g-3">
        <div v-for="n in 4" :key="n" class="col-6 col-md-3">
          <Skeleton height="88px" border-radius="8px" />
        </div>
      </div>
    </template>

    <!-- Empty state -->
    <template v-else-if="isEmpty">
      <div class="widget-status-groups__empty">
        <i class="pi pi-chart-line widget-status-groups__empty-icon" />
        <p class="widget-status-groups__empty-text">{{ t('dashboard.empty.noDeals') }}</p>
      </div>
    </template>

    <!-- Content -->
    <template v-else>
      <div class="row g-3">
        <div
          v-for="group in groups"
          :key="group.key"
          class="col-6 col-md-3"
        >
          <div class="kpi-card" :class="`kpi-card--${group.key}`">
            <div class="kpi-card__header">
              <i :class="['pi', iconMap[group.key], 'kpi-card__icon', `kpi-card__icon--${group.key}`]" />
              <span class="kpi-card__label">{{ labelMap[group.key] }}</span>
            </div>
            <div class="kpi-card__count">{{ group.count }}</div>
            <div class="kpi-card__amount">
              {{ group.key === 'lost' && group.amount_kopecks === 0
                ? '—'
                : formatMoney(group.amount_kopecks, locale, props.baseCurrency) }}
            </div>
            <div class="kpi-card__trend">
              <template v-if="trend(group).positive !== null">
                <Tag
                  :severity="trendSeverity(group)"
                  :value="trend(group).text"
                  class="kpi-card__trend-tag"
                />
              </template>
              <span v-else class="kpi-card__trend-dash">{{ t('dashboard.statusGroups.noTrend') }}</span>
            </div>
          </div>
        </div>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import type { StatusGroup } from '@/entities/salesDashboard'
import { formatMoney, formatTrendPct } from '@/utils/chartFormatters'

const { t, locale } = useI18n()

const props = defineProps<{
  groups: StatusGroup[]
  baseCurrency: string
  loading: boolean
}>()

const isEmpty = computed(
  () => props.groups.length === 0 || props.groups.every((g) => g.count === 0),
)

const iconMap: Record<StatusGroup['key'], string> = {
  active: 'pi-briefcase',
  won: 'pi-check-circle',
  lost: 'pi-times-circle',
  total: 'pi-chart-line',
}

const labelMap = computed<Record<StatusGroup['key'], string>>(() => ({
  active: t('dashboard.statusGroups.active'),
  won: t('dashboard.statusGroups.won'),
  lost: t('dashboard.statusGroups.lost'),
  total: t('dashboard.statusGroups.total'),
}))

const trend = (group: StatusGroup) => formatTrendPct(group.trend_pct)

const trendSeverity = (group: StatusGroup): 'success' | 'danger' => {
  const positive = trend(group).positive
  if (group.key === 'lost') {
    // For lost: more losses (positive trend) is bad
    return positive ? 'danger' : 'success'
  }
  return positive ? 'success' : 'danger'
}
</script>

<style lang="scss" scoped>
.kpi-card {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  height: 100%;
}

.kpi-card__header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.kpi-card__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  line-height: $line-height-tight;
}

.kpi-card__icon {
  font-size: $font-size-lg;
  flex-shrink: 0;
}

.kpi-card__icon--active { color: $primary-color; }
.kpi-card__icon--won { color: var(--p-green-500); }
.kpi-card__icon--lost { color: var(--p-red-500); }
.kpi-card__icon--total { color: $surface-600; }

.kpi-card__count {
  font-size: $font-size-icon-lg;
  font-weight: $font-weight-bold;
  color: $surface-900;
  line-height: 1;
}

.kpi-card__amount {
  font-size: $font-size-sm;
  color: $surface-600;
}

.kpi-card__trend {
  margin-top: auto;
}

.kpi-card__trend-dash {
  font-size: $font-size-sm;
  color: $surface-400;
}

.kpi-card__trend-tag {
  font-size: $font-size-xs;
}

.widget-status-groups__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 88px;
}

.widget-status-groups__empty-icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;
}

.widget-status-groups__empty-text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}
</style>
