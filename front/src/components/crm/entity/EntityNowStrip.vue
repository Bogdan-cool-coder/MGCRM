<template>
  <div class="entity-now-strip">
    <span class="entity-now-strip__label">{{ t('crm.entity.nowStrip.label') }}:</span>
    <span
      v-for="(item, idx) in items"
      :key="idx"
      class="entity-now-strip__chip"
      :class="chipClass(item.severity)"
      :style="item.onClick ? 'cursor: pointer' : undefined"
      @click="item.onClick ? item.onClick() : undefined"
    >
      <i :class="['pi', chipIcon(item.severity), 'entity-now-strip__chip-icon']" aria-hidden="true" />
      <span class="entity-now-strip__chip-label">{{ item.label }}:</span>
      <span class="entity-now-strip__chip-value">{{ item.value }}</span>
    </span>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

export interface NowItem {
  label: string
  value: string | number
  severity?: 'success' | 'warning' | 'danger' | 'neutral'
  onClick?: () => void
}

defineProps<{
  items: NowItem[]
}>()

const { t } = useI18n()

function chipClass(severity?: string): string {
  switch (severity) {
    case 'danger':  return 'entity-now-strip__chip--danger'
    case 'warning': return 'entity-now-strip__chip--warning'
    case 'success': return 'entity-now-strip__chip--success'
    default:        return 'entity-now-strip__chip--neutral'
  }
}

function chipIcon(severity?: string): string {
  switch (severity) {
    case 'danger':  return 'pi-exclamation-circle'
    case 'warning': return 'pi-list-check'
    case 'success': return 'pi-calendar-check'
    default:        return 'pi-minus-circle'
  }
}
</script>

<style lang="scss" scoped>
.entity-now-strip {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 0;
  flex-wrap: wrap;
}

.entity-now-strip__label {
  font-size: $font-size-2xs;
  font-weight: 600;
  color: $surface-500;
  white-space: nowrap;
  text-transform: uppercase;
  letter-spacing: 0.04em;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// Base pill chip
.entity-now-strip__chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px 3px 6px;
  border-radius: $radius-pill;
  font-size: $font-size-xs;
  line-height: 1.4;
  white-space: nowrap;
  transition: opacity var(--app-transition-fast, 0.15s);

  &[style*='cursor: pointer']:hover {
    opacity: 0.8;
  }

  // Neutral / gray — always shown
  &--neutral {
    background: var(--p-surface-100);
    color: var(--p-surface-600);

    .app-dark & {
      background: var(--p-surface-700);
      color: var(--p-surface-300);
    }

    .entity-now-strip__chip-icon {
      color: var(--p-surface-400);
      .app-dark & { color: var(--p-surface-400); }
    }
  }

  // Success / teal-green — "Посл. контакт" ≤7 дней
  &--success {
    background: var(--p-green-50);
    color: var(--p-green-700);

    .app-dark & {
      background: var(--p-green-900);
      color: var(--p-green-200);
    }

    .entity-now-strip__chip-icon {
      color: var(--p-green-500);
      .app-dark & { color: var(--p-green-400); }
    }
  }

  // Warning / blue-info — "Открытых задач" > 0
  &--warning {
    background: var(--p-blue-50);
    color: var(--p-blue-700);

    .app-dark & {
      background: var(--p-blue-900);
      color: var(--p-blue-200);
    }

    .entity-now-strip__chip-icon {
      color: var(--p-blue-500);
      .app-dark & { color: var(--p-blue-400); }
    }
  }

  // Danger / red — "Просрочено" > 0 or "Посл. контакт" > 30 дней
  &--danger {
    background: var(--p-red-50);
    color: var(--p-red-700);

    .app-dark & {
      background: var(--p-red-900);
      color: var(--p-red-200);
    }

    .entity-now-strip__chip-icon {
      color: var(--p-red-500);
      .app-dark & { color: var(--p-red-400); }
    }
  }
}

.entity-now-strip__chip-icon {
  font-size: $font-size-2xs;
  flex-shrink: 0;
}

.entity-now-strip__chip-label {
  font-weight: 500;
  opacity: 0.8;
}

.entity-now-strip__chip-value {
  font-weight: 700;
}
</style>
