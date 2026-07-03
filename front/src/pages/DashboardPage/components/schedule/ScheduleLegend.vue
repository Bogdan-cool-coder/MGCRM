<template>
  <div class="schedule-legend">
    <span
      v-for="item in items"
      :key="item.key"
      class="schedule-legend__item"
    >
      <span
        class="schedule-legend__swatch"
        :class="`schedule-legend__swatch--${item.key}`"
        aria-hidden="true"
      />
      {{ item.label }}
    </span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

const items = computed(() => [
  { key: 'plan', label: t('dashboard.schedule.legend_plan') },
  { key: 'fact', label: t('dashboard.schedule.legend_fact') },
  { key: 'slippage', label: t('dashboard.schedule.legend_slippage') },
  { key: 'weekend', label: t('dashboard.schedule.legend_weekend') },
])
</script>

<style lang="scss" scoped>
.schedule-legend {
  display: flex;
  flex-wrap: wrap;
  align-items: center;
  gap: $space-3;
}

.schedule-legend__item {
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-xs;
  color: $surface-600;
}

.schedule-legend__swatch {
  width: 12px;
  height: 12px;
  border-radius: $radius-2xs;
  flex-shrink: 0;

  // Semantic colours match the chart series (--p-* dark-aware where they differ).
  &--plan {
    background: var(--p-blue-500);

    .app-dark & {
      background: var(--p-blue-400);
    }
  }

  &--fact {
    background: var(--p-green-500);

    .app-dark & {
      background: var(--p-green-400);
    }
  }

  &--slippage {
    background: var(--p-red-500);

    .app-dark & {
      background: var(--p-red-400);
    }
  }

  // Weekend — dimmed surface tint; inverts with the surface scale.
  &--weekend {
    background: $surface-200;
  }
}
</style>
