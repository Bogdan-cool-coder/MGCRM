<template>
  <div
    role="tablist"
    class="segmented"
    :aria-label="ariaLabel"
  >
    <button
      v-for="opt in options"
      :key="opt.value"
      role="tab"
      type="button"
      :aria-selected="modelValue === opt.value"
      :class="['segmented__btn', { 'segmented__btn--active': modelValue === opt.value }]"
      @click="emit('update:modelValue', opt.value)"
    >
      {{ opt.label }}
      <span
        v-if="opt.count != null && opt.count > 0"
        :class="['segmented__count', { 'segmented__count--danger': opt.severity === 'danger' }]"
      >{{ opt.count }}</span>
    </button>
  </div>
</template>

<script setup lang="ts" generic="T extends string">
/**
 * Shared segmented control — Periphery Uplift §0 canon (ContactsToolbar__type-switch).
 * Track container + active pill; optional per-segment count badge with `danger`
 * accent (used by MyCourses «Просроченные»). Dark active pill uses surface-200
 * (lighter than the #444547 track) so it reads.
 */
export interface SegmentedOption<V extends string> {
  value: V
  label: string
  count?: number
  severity?: 'danger'
}

defineProps<{
  modelValue: T
  options: SegmentedOption<T>[]
  ariaLabel?: string
}>()

const emit = defineEmits<{
  'update:modelValue': [value: T]
}>()
</script>

<style lang="scss" scoped>
.segmented {
  display: inline-flex;
  gap: 2px;
  padding: 3px;
  height: 38px;
  box-sizing: border-box;
  border-radius: $radius-md;
  background: var(--p-surface-100);
  flex-shrink: 0;
  align-items: stretch;
}

.segmented__btn {
  flex: 1 0 auto;
  display: inline-flex;
  align-items: center;
  gap: $space-2;
  padding: 0 $space-4;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  border: 0;
  border-radius: $radius-sm;
  cursor: pointer;
  background: transparent;
  color: var(--p-text-muted-color);
  line-height: 1;
  box-sizing: border-box;
  transition: background 0.15s, color 0.15s, box-shadow 0.15s;
  white-space: nowrap;

  &:hover:not(.segmented__btn--active) {
    color: var(--p-text-color);
  }
}

// Active pill uses a dedicated single BEM modifier (NOT a compound `&.is-active`):
// the Vue scoped compiler reliably emits `.app-dark .…--active[data-v]` for a
// single-class modifier with nested `.app-dark &`; a compound parent gets dropped.
// Same pattern as ContactsToolbar__type-btn--active.
.segmented__btn--active {
  background: var(--p-surface-0);
  color: $primary-900;
  box-shadow: $shadow-sm;

  .app-dark & {
    background: var(--p-surface-200);
    color: var(--p-text-color);
  }
}

.segmented__count {
  min-width: 18px;
  height: 18px;
  padding: 0 5px;
  border-radius: $radius-badge;
  background: var(--p-surface-200);
  color: var(--p-text-muted-color);
  font-size: $font-size-3xs;
  font-weight: $font-weight-bold;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  box-sizing: border-box;

  .app-dark & {
    background: var(--p-surface-300);
    color: var(--p-surface-900);
  }

  &--danger {
    background: $status-danger-bg;
    color: $status-danger-text;
  }
}

// Active segment: count chip picks up the pill accent for contrast.
.segmented__btn--active .segmented__count:not(.segmented__count--danger) {
  background: $primary-100;
  color: $primary-900;

  .app-dark & {
    background: color-mix(in srgb, $primary-900 45%, transparent);
    color: var(--p-primary-200);
  }
}
</style>
