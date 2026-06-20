<template>
  <div class="entity-kpi-strip" role="list" :aria-label="t('crm.entity.kpiStrip.label')">
    <!-- Loading skeleton -->
    <template v-if="loading">
      <Skeleton v-for="i in 5" :key="i" width="80px" height="16px" class="entity-kpi-strip__skeleton-item" />
    </template>

    <!-- KPI items -->
    <template v-else>
      <template v-for="(item, idx) in items" :key="item.key">
        <!-- Divider between items -->
        <span v-if="idx > 0" class="entity-kpi-strip__divider" aria-hidden="true" />

        <div
          class="entity-kpi-strip__item"
          :class="{
            'entity-kpi-strip__item--clickable': item.clickable,
          }"
          role="listitem"
          @click="item.clickable && item.onClick ? item.onClick() : undefined"
        >
          <i :class="['pi', item.icon, 'entity-kpi-strip__icon', accentIconClass(item.accent)]" />
          <span
            class="entity-kpi-strip__value"
            :class="accentClass(item.accent)"
          >{{ item.value }}</span>
          <span class="entity-kpi-strip__label">{{ t(item.label) }}</span>
        </div>
      </template>
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
  clickable?: boolean
  onClick?: () => void
}

defineProps<{
  items: KpiItem[]
  loading?: boolean
}>()

const { t } = useI18n()

function accentClass(accent?: string): string {
  switch (accent) {
    case 'success': return 'entity-kpi-strip__value--success'
    case 'warning': return 'entity-kpi-strip__value--warning'
    case 'danger':  return 'entity-kpi-strip__value--danger'
    case 'info':    return 'entity-kpi-strip__value--info'
    case 'brand':   return 'entity-kpi-strip__value--brand'
    case 'teal':    return 'entity-kpi-strip__value--teal'
    case 'amber':   return 'entity-kpi-strip__value--amber'
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
    default:        return ''
  }
}
</script>

<style lang="scss" scoped>
.entity-kpi-strip {
  display: flex;
  align-items: center;
  gap: 0;
  padding: $space-2 $space-4;
  background: var(--p-surface-50);
  border-bottom: 1px solid var(--p-surface-200);
  flex-wrap: wrap;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-700);
  }

  @media (max-width: 767px) {
    overflow-x: auto;
    flex-wrap: nowrap;

    &::-webkit-scrollbar {
      display: none;
    }

    scrollbar-width: none;
  }

  @media (min-width: 768px) and (max-width: 1023px) {
    flex-wrap: wrap;
    row-gap: $space-2;
  }
}

.entity-kpi-strip__skeleton-item {
  margin-right: $space-3;

  &:last-child {
    margin-right: 0;
  }
}

.entity-kpi-strip__divider {
  width: 1px;
  height: 14px;
  background: var(--p-surface-300);
  opacity: 0.5;
  flex-shrink: 0;
  margin: 0 $space-3;

  .app-dark & {
    background: var(--p-surface-600);
  }
}

.entity-kpi-strip__item {
  display: flex;
  align-items: center;
  gap: 5px;
  white-space: nowrap;
  padding: $space-1 0;

  &--clickable {
    cursor: pointer;
    border-radius: $radius-sm;
    transition: opacity var(--app-transition-fast);

    &:hover {
      opacity: 0.75;

      .entity-kpi-strip__value {
        text-decoration: underline;
      }
    }
  }
}

.entity-kpi-strip__icon {
  font-size: 12px;
  color: $surface-400;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }

  &--info {
    color: var(--p-blue-500);
    .app-dark & { color: var(--p-blue-400); }
  }

  &--brand {
    color: var(--p-primary-color);
    .app-dark & { color: var(--p-blue-300); }
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
}

.entity-kpi-strip__value {
  font-size: 13px;
  font-weight: 600;
  color: var(--p-text-color);

  &--success {
    color: var(--p-green-500);
    .app-dark & { color: var(--p-green-400); }
  }

  &--warning {
    color: var(--p-orange-400);
    .app-dark & { color: var(--p-orange-300); }
  }

  &--danger {
    color: var(--p-red-400);
    .app-dark & { color: var(--p-red-300); }
  }

  &--info {
    color: var(--p-blue-500);
    .app-dark & { color: var(--p-blue-400); }
  }

  &--brand {
    color: var(--p-primary-color);
    .app-dark & { color: var(--p-blue-300); }
  }

  &--teal {
    color: var(--p-teal-500);
    .app-dark & { color: var(--p-teal-400); }
  }

  &--amber {
    color: var(--p-amber-500);
    .app-dark & { color: var(--p-orange-400); }
  }
}

.entity-kpi-strip__label {
  font-size: 12px;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}
</style>
