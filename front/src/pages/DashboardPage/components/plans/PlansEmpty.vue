<template>
  <div class="plans-empty">
    <i :class="['pi', icon, 'plans-empty__icon']" aria-hidden="true" />
    <h3 class="plans-empty__title">{{ title }}</h3>
    <p v-if="hint" class="plans-empty__hint">{{ hint }}</p>
  </div>
</template>

<script setup lang="ts">
/**
 * Shared empty-state for the Планы metric panels (income · tasks · conversions).
 * Icon + title + optional hint, centred — one visual language across sub-metrics.
 */
defineProps<{
  icon: string
  title: string
  hint?: string
}>()
</script>

<style lang="scss" scoped>
.plans-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-2;
  min-height: 260px;
  padding: $space-8 $space-6;
  text-align: center;
}

.plans-empty__icon {
  font-size: $font-size-icon-xl;
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.plans-empty__title {
  margin: 0;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.plans-empty__hint {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-500);
  }
}
</style>
