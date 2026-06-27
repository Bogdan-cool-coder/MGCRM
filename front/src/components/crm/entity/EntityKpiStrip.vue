<template>
  <div class="entity-kpi-strip" role="list" :aria-label="t('crm.entity.kpiStrip.label')">
    <!-- Loading skeleton -->
    <template v-if="loading">
      <Skeleton v-for="i in 5" :key="i" width="100px" height="30px" border-radius="999px" class="entity-kpi-strip__skeleton-item" />
    </template>

    <!-- KPI pill items -->
    <template v-else>
      <div
        v-for="item in items"
        :key="item.key"
        v-tooltip.bottom="item.tooltip ? t(item.tooltip) : undefined"
        class="entity-kpi-strip__item"
        :class="[accentBgClass(item.accent), { 'entity-kpi-strip__item--clickable': item.clickable }]"
        role="listitem"
        @click="item.clickable && item.onClick ? item.onClick() : undefined"
      >
        <i :class="['pi', item.icon, 'entity-kpi-strip__icon', accentIconClass(item.accent)]" />
        <span class="entity-kpi-strip__label">{{ t(item.label) }}:</span>
        <span class="entity-kpi-strip__value" :class="accentValueClass(item.accent)">{{ item.value }}</span>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'

export interface KpiItem {
  key: string
  icon: string
  label: string
  value: string | number
  accent?: 'success' | 'warning' | 'danger' | 'info' | 'neutral' | 'brand' | 'teal' | 'amber'
  tooltip?: string
  clickable?: boolean
  onClick?: () => void
}

defineProps<{
  items: KpiItem[]
  loading?: boolean
}>()

const { t } = useI18n()

function accentBgClass(accent?: string): string {
  switch (accent) {
    case 'info':    return 'entity-kpi-strip__item--info'
    case 'brand':   return 'entity-kpi-strip__item--brand'
    case 'success': return 'entity-kpi-strip__item--success'
    case 'teal':    return 'entity-kpi-strip__item--teal'
    case 'amber':   return 'entity-kpi-strip__item--amber'
    case 'danger':  return 'entity-kpi-strip__item--danger'
    case 'warning': return 'entity-kpi-strip__item--warning'
    default:        return 'entity-kpi-strip__item--neutral'
  }
}

function accentValueClass(accent?: string): string {
  switch (accent) {
    case 'info':    return 'entity-kpi-strip__value--info'
    case 'brand':   return 'entity-kpi-strip__value--brand'
    case 'success': return 'entity-kpi-strip__value--success'
    case 'teal':    return 'entity-kpi-strip__value--teal'
    case 'amber':   return 'entity-kpi-strip__value--amber'
    case 'danger':  return 'entity-kpi-strip__value--danger'
    case 'warning': return 'entity-kpi-strip__value--warning'
    default:        return ''
  }
}

function accentIconClass(accent?: string): string {
  switch (accent) {
    case 'success': return 'entity-kpi-strip__icon--success'
    case 'info':    return 'entity-kpi-strip__icon--info'
    case 'brand':   return 'entity-kpi-strip__icon--brand'
    case 'teal':    return 'entity-kpi-strip__icon--teal'
    case 'amber':   return 'entity-kpi-strip__icon--amber'
    case 'danger':  return 'entity-kpi-strip__icon--danger'
    case 'warning': return 'entity-kpi-strip__icon--warning'
    default:        return ''
  }
}
</script>

<style lang="scss" scoped>
.entity-kpi-strip {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-3 $space-4;
  background: $surface-card;
  border-bottom: 1px solid var(--p-surface-200);
  flex-wrap: wrap;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }

  @media (max-width: 767px) {
    overflow-x: auto;
    flex-wrap: nowrap;
    padding: $space-2 $space-3;

    &::-webkit-scrollbar {
      display: none;
    }

    scrollbar-width: none;
  }
}

.entity-kpi-strip__skeleton-item {
  flex-shrink: 0;
}

// ── Pill base ──────────────────────────────────────────────────────────────────

.entity-kpi-strip__item {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 6px 13px; // spec: 6px 13px — no matching token pair
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border-radius: 999px; // pill — spec invariant
  font-size: $font-size-sm;
  white-space: nowrap;
  cursor: default;
  transition: box-shadow var(--app-transition-fast), opacity var(--app-transition-fast);

  &--clickable {
    cursor: pointer;

    &:hover {
      opacity: 0.85;
    }
  }

  // ── accent variants ────────────────────────────────────────────────────────
  &--info {
    background: $blue-100;
    color: var(--p-blue-700);

    .app-dark & {
      background: var(--p-blue-900);
      color: var(--p-blue-300);
    }
  }

  &--brand {
    background: $primary-100;
    color: $primary-900;

    .app-dark & {
      background: var(--p-primary-900);
      color: var(--p-primary-300);
    }
  }

  &--success {
    background: $green-100;
    color: $green-900;

    .app-dark & {
      background: var(--p-green-900);
      color: var(--p-green-300);
    }
  }

  &--teal {
    background: $teal-100;
    color: $teal-700;

    .app-dark & {
      background: var(--p-teal-900);
      color: var(--p-teal-300);
    }
  }

  &--amber {
    background: $orange-100;
    color: $orange-900;

    .app-dark & {
      background: var(--p-orange-900);
      color: var(--p-orange-300);
    }
  }

  &--danger {
    background: $red-100;
    color: $red-700;

    .app-dark & {
      background: var(--p-red-900);
      color: var(--p-red-300);
    }
  }

  &--warning {
    background: $orange-100;
    color: $orange-900;

    .app-dark & {
      background: var(--p-orange-900);
      color: var(--p-orange-300);
    }
  }

  &--neutral {
    background: var(--p-surface-100);
    color: $surface-600;

    .app-dark & {
      background: var(--p-surface-200);
      color: var(--p-surface-300);
    }
  }
}

// ── Icon ──────────────────────────────────────────────────────────────────────

.entity-kpi-strip__icon {
  font-size: $font-size-xs;
  flex-shrink: 0;

  &--info {
    color: var(--p-blue-500);
    .app-dark & { color: var(--p-blue-400); }
  }

  &--brand {
    color: var(--p-primary-color);
    .app-dark & { color: var(--p-primary-400); }
  }

  &--teal {
    color: var(--p-teal-500);
    .app-dark & { color: var(--p-teal-400); }
  }

  &--amber {
    color: var(--p-amber-500);
    .app-dark & { color: var(--p-orange-400); }
  }

  &--success {
    color: var(--p-green-500);
    .app-dark & { color: var(--p-green-400); }
  }

  &--danger {
    color: var(--p-red-400);
    .app-dark & { color: var(--p-red-400); }
  }

  &--warning {
    color: var(--p-orange-400);
    .app-dark & { color: var(--p-orange-300); }
  }
}

// ── Label ─────────────────────────────────────────────────────────────────────

.entity-kpi-strip__label {
  font-size: $font-size-sm;
  opacity: 0.75;
  flex-shrink: 0;
}

// ── Value ─────────────────────────────────────────────────────────────────────

.entity-kpi-strip__value {
  font-weight: $font-weight-semibold;
  font-size: $font-size-sm;

  &--info    { color: var(--p-blue-700);    .app-dark & { color: var(--p-blue-300); } }
  &--brand   { color: $primary-900;         .app-dark & { color: var(--p-primary-300); } }
  &--success { color: $green-900;           .app-dark & { color: var(--p-green-300); } }
  &--teal    { color: $teal-700;            .app-dark & { color: var(--p-teal-300); } }
  &--amber   { color: $orange-900;          .app-dark & { color: var(--p-orange-300); } }
  &--danger  { color: $red-700;             .app-dark & { color: var(--p-red-300); } }
  &--warning { color: $orange-900;          .app-dark & { color: var(--p-orange-300); } }
}
</style>
