<template>
  <div class="row g-4 hr-kpi-cards">
    <div
      v-for="tile in tiles"
      :key="tile.key"
      class="col-6 col-lg-3"
    >
      <Skeleton v-if="loading" height="76px" border-radius="12px" />
      <div v-else class="hr-kpi-card">
        <div class="hr-kpi-card__header">
          <span class="hr-kpi-card__icon-tile" :class="`hr-kpi-card__icon-tile--${tile.key}`">
            <i :class="['pi', tile.icon]" />
          </span>
          <span class="hr-kpi-card__label">{{ tile.label }}</span>
        </div>
        <div class="hr-kpi-card__value">{{ tile.value }}</div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import type { HrProgressSummary } from '@/api/onboardingAdmin'

const props = defineProps<{
  summary: HrProgressSummary | null
  loading: boolean
}>()

const { t } = useI18n()

type TileKey = 'total' | 'completed' | 'inProgress' | 'overdue'

// Legalized summary-plate recipe per WidgetKpiCard (icon-tile 28×28 + semantic
// tint), keeping the HR-specific counter shape (no money/trend). Semantics:
// total→neutral, completed→success, in_progress→info, overdue→danger.
const tiles = computed<{ key: TileKey; icon: string; label: string; value: number }[]>(() => [
  {
    key: 'total',
    icon: 'pi-users',
    label: t('onboarding.hrProgress.kpi.total'),
    value: props.summary?.total ?? 0,
  },
  {
    key: 'completed',
    icon: 'pi-check-circle',
    label: t('onboarding.hrProgress.kpi.completed'),
    value: props.summary?.completed ?? 0,
  },
  {
    key: 'inProgress',
    icon: 'pi-spinner',
    label: t('onboarding.hrProgress.kpi.inProgress'),
    value: props.summary?.in_progress ?? 0,
  },
  {
    key: 'overdue',
    icon: 'pi-exclamation-triangle',
    label: t('onboarding.hrProgress.kpi.overdue'),
    value: props.summary?.overdue ?? 0,
  },
])
</script>

<style lang="scss" scoped>
// Keep the default row negative gutters (margin-block:0 only) so the KPI card
// row aligns edge-to-edge with the matrix card in the parent page. Full margin:0
// left a 12px inset mismatch (audit L1); the page body padding absorbs the
// horizontal negative margin.
.hr-kpi-cards {
  margin-block: 0;
}

.hr-kpi-card {
  height: 100%;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  padding: $space-4;
  box-shadow: $shadow-sm;
}

.hr-kpi-card__header {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.hr-kpi-card__icon-tile {
  width: 28px;
  height: 28px;
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: $radius-md;
  font-size: $font-size-sm;

  &--total {
    background: $surface-100;
    color: $surface-600;
  }

  &--completed {
    background: $status-success-bg;
    color: $status-success-text;
  }

  &--inProgress {
    background: $status-info-bg;
    color: $status-info-text;
  }

  &--overdue {
    background: $status-danger-bg;
    color: $status-danger-text;
  }
}

.hr-kpi-card__label {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  line-height: $line-height-tight;
}

.hr-kpi-card__value {
  font-size: $font-size-icon-lg;
  font-weight: $font-weight-bold;
  color: $surface-900;
  line-height: 1;
}
</style>
