<template>
  <div
    class="entity-avatar"
    :class="[`entity-avatar--${size}`, { 'entity-avatar--on-brand': onBrand }]"
    :style="onBrand ? undefined : { background: bgColor }"
    :aria-label="initials"
  >
    <span class="entity-avatar__initials">{{ displayInitials }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const AVATAR_PALETTE = [
  '#3B6CB7',
  '#2E8B57',
  '#D46A26',
  '#7B4EA0',
  '#C0392B',
  '#1A7A7A',
  '#B8860B',
  '#C2185B',
]

const props = withDefaults(
  defineProps<{
    entityId: number
    initials: string
    size?: 'sm' | 'md' | 'lg'
    /** When true, renders brand-header variant: rgba(255,255,255,0.14) bg + white initials.
     *  Used on navy EntityInfoHeader (baked-in brand invariant — rgba allowed on navy panel). */
    onBrand?: boolean
  }>(),
  {
    size: 'md',
    onBrand: false,
  },
)

const bgColor = computed(() =>
  props.onBrand ? undefined : AVATAR_PALETTE[props.entityId % AVATAR_PALETTE.length],
)

const displayInitials = computed(() => props.initials.slice(0, 3).toUpperCase())
</script>

<style lang="scss" scoped>
.entity-avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: $radius-circle;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border: 2px solid rgba(255, 255, 255, 0.25); // brand invariant: avatar ring on navy panel
  flex-shrink: 0;

  &--sm {
    width: 32px;
    height: 32px;

    .entity-avatar__initials {
      font-size: $font-size-xs;
    }
  }

  &--md {
    width: 56px;
    height: 56px;

    .entity-avatar__initials {
      font-size: $font-size-lg;
    }
  }

  &--lg {
    width: 72px;
    height: 72px;

    .entity-avatar__initials {
      font-size: $font-size-icon-md;
    }
  }
}

.entity-avatar__initials {
  color: $surface-0;
  font-weight: $font-weight-semibold;
  line-height: 1;
  letter-spacing: 0.02em;
  font-family: $font-family-sans;
  user-select: none;
}

// ── Brand-header variant (navy panel) ─────────────────────────────────────────
// rgba(255,255,255,0.14) is a brand invariant for semi-transparent white on navy bg.
// stylelint-disable-next-line scale-unlimited/declaration-strict-value
.entity-avatar--on-brand {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: rgba(255, 255, 255, 0.14); // brand invariant: avatar on navy panel

  .entity-avatar__initials {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: #fff; // brand invariant: white initials on navy panel
  }
}
</style>
