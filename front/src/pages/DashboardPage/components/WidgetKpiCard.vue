<template>
  <!-- Loading skeleton -->
  <Skeleton v-if="loading" height="120px" border-radius="12px" />

  <!-- Empty placeholder (no group data for this key) -->
  <div v-else-if="!group" class="kpi-card kpi-card--empty">
    <i class="pi pi-chart-line kpi-card__empty-icon" />
    <span class="kpi-card__empty-text">{{ t('dashboard.empty.noDeals') }}</span>
  </div>

  <!-- Content -->
  <button
    v-else
    type="button"
    class="kpi-card"
    :class="{ 'kpi-card--hero': isHero }"
    :title="t('dashboard.statusGroups.openDeals', { label: labelText })"
    @click="emit('open')"
  >
    <span v-if="isHero" class="kpi-card__hero-bar" aria-hidden="true" />

    <div class="kpi-card__header">
      <span class="kpi-card__icon-tile" :class="`kpi-card__icon-tile--${group.key}`">
        <i :class="['pi', iconMap[group.key]]" />
      </span>
      <span class="kpi-card__label">{{ labelText }}</span>
    </div>

    <div class="kpi-card__values">
      <span class="kpi-card__count">{{ group.count }}</span>
      <span class="kpi-card__amount">{{ amountText }}</span>
    </div>

    <div class="kpi-card__trend">
      <span
        v-if="trendInfo.positive !== null"
        class="kpi-card__pill"
        :class="isGood ? 'kpi-card__pill--good' : 'kpi-card__pill--bad'"
      >
        <i :class="['pi', trendInfo.positive ? 'pi-arrow-up-right' : 'pi-arrow-down-right', 'kpi-card__pill-icon']" />
        {{ t('dashboard.statusGroups.trendVsPrev', { pct: Math.abs(group.trend_pct ?? 0).toFixed(1) }) }}
      </span>
      <span v-else class="kpi-card__no-compare">{{ t('dashboard.statusGroups.noCompare') }}</span>
    </div>
  </button>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import type { StatusGroup } from '@/entities/salesDashboard'
import { formatMoney, formatTrendPct } from '@/utils/chartFormatters'

const { t, locale } = useI18n()

const props = defineProps<{
  group: StatusGroup | null
  baseCurrency: string
  loading: boolean
}>()

const emit = defineEmits<{ open: [] }>()

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

const labelText = computed(() => (props.group ? labelMap.value[props.group.key] : ''))

const isHero = computed(() => props.group?.key === 'active')

const amountText = computed(() => {
  if (!props.group) return ''
  // Lost with zero amount → em-dash instead of "0" (prior behaviour).
  if (props.group.key === 'lost' && props.group.amount_kopecks === 0) return '—'
  return formatMoney(props.group.amount_kopecks, locale.value, props.baseCurrency)
})

const trendInfo = computed(() => formatTrendPct(props.group?.trend_pct ?? null))

// Inverted semantics for "lost": a rising loss count (positive trend) is BAD.
const isGood = computed<boolean>(() => {
  const positive = trendInfo.value.positive
  if (positive === null) return true
  if (props.group?.key === 'lost') return !positive
  return positive
})
</script>

<style lang="scss" scoped>
.kpi-card {
  position: relative;
  width: 100%;
  height: 100%;
  min-height: 120px;
  display: flex;
  flex-direction: column;
  gap: $space-3;
  text-align: left;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-4;
  box-shadow: $shadow-sm;
  cursor: pointer;
  overflow: hidden;
  transition: box-shadow var(--app-transition-fast);

  &:hover {
    box-shadow: $shadow-md;
  }

  // Hero accent (Активные): primary frame + 3px left bar.
  &--hero {
    border-color: $primary-color;

    .app-dark & {
      border-color: var(--p-primary-color);
    }
  }
}

.kpi-card__hero-bar {
  position: absolute;
  top: 0;
  left: 0;
  bottom: 0;
  width: 3px;
  background: $primary-color;

  .app-dark & {
    background: var(--p-primary-color);
  }
}

.kpi-card__header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.kpi-card__icon-tile {
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: $radius-md;
  font-size: $font-size-sm;

  &--active {
    background: $primary-100;
    color: $primary-color;

    .app-dark & {
      color: var(--p-primary-color);
    }
  }

  &--won {
    background: $status-success-bg;
    color: $status-success-text;
  }

  &--lost {
    background: $status-danger-bg;
    color: $status-danger-text;
  }

  &--total {
    background: $surface-100;
    color: $surface-600;
  }
}

.kpi-card__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  line-height: $line-height-tight;
}

.kpi-card__values {
  display: flex;
  align-items: baseline;
  gap: $space-2;
  flex-wrap: wrap;
}

.kpi-card__count {
  font-size: $font-size-icon-lg;
  font-weight: $font-weight-bold;
  color: $surface-900;
  line-height: 1;
}

.kpi-card__amount {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $primary-color;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.kpi-card__trend {
  margin-top: auto;
}

.kpi-card__pill {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  border-radius: $radius-pill;
  padding: 2px $space-2;

  &--good {
    color: $status-success-text;
    background: $status-success-bg;
  }

  &--bad {
    color: $status-danger-text;
    background: $status-danger-bg;
  }
}

.kpi-card__pill-icon {
  font-size: $font-size-3xs; // snap from 10px
}

.kpi-card__no-compare {
  font-size: $font-size-xs;
  color: $surface-500;
}

// ─── Empty / no-data placeholder ─────────────────────────────────────────────
.kpi-card--empty {
  cursor: default;
  align-items: center;
  justify-content: center;
  text-align: center;
  box-shadow: none;

  &:hover {
    box-shadow: none;
  }
}

.kpi-card__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.kpi-card__empty-text {
  font-size: $font-size-xs;
  color: $surface-500;
}
</style>
