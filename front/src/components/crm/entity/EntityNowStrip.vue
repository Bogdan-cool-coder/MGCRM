<template>
  <div v-if="items.length > 0" class="entity-now-strip">
    <span class="entity-now-strip__label">{{ t('crm.entity.nowStrip.label') }}:</span>
    <template v-for="(item, idx) in items" :key="idx">
      <span v-if="idx > 0" class="entity-now-strip__dot" aria-hidden="true">·</span>
      <span
        class="entity-now-strip__item"
        :class="severityClass(item.severity)"
        :style="item.onClick ? 'cursor: pointer' : undefined"
        @click="item.onClick ? item.onClick() : undefined"
      >
        <span class="entity-now-strip__item-label">{{ item.label }}:</span>
        <span class="entity-now-strip__item-value">{{ item.value }}</span>
      </span>
    </template>
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

function severityClass(severity?: string): string {
  switch (severity) {
    case 'danger':  return 'entity-now-strip__item--danger'
    case 'warning': return 'entity-now-strip__item--warning'
    case 'success': return 'entity-now-strip__item--success'
    default:        return ''
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
  font-size: 12px;
  font-weight: 600;
  color: $surface-500;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.entity-now-strip__dot {
  color: $surface-400;
  opacity: 0.3;
  font-size: 14px;
  line-height: 1;
}

.entity-now-strip__item {
  display: flex;
  align-items: center;
  gap: 3px;
  font-size: 12px;

  &--danger .entity-now-strip__item-value {
    color: var(--p-red-400);
    font-weight: 600;
  }

  &--warning .entity-now-strip__item-value {
    color: var(--p-orange-400);
    font-weight: 600;
  }

  &--success .entity-now-strip__item-value {
    color: var(--p-green-500);
  }
}

.entity-now-strip__item-label {
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.entity-now-strip__item-value {
  color: var(--p-text-color);
  font-weight: 500;
}
</style>
