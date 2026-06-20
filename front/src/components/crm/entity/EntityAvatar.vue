<template>
  <div
    class="entity-avatar"
    :class="[`entity-avatar--${size}`]"
    :style="{ background: bgColor }"
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
  }>(),
  {
    size: 'md',
  },
)

const bgColor = computed(() => AVATAR_PALETTE[props.entityId % AVATAR_PALETTE.length])

const displayInitials = computed(() => props.initials.slice(0, 3).toUpperCase())
</script>

<style lang="scss" scoped>
.entity-avatar {
  display: flex;
  align-items: center;
  justify-content: center;
  border-radius: 50%;
  border: 2px solid rgba(255, 255, 255, 0.25);
  flex-shrink: 0;

  &--sm {
    width: 32px;
    height: 32px;

    .entity-avatar__initials {
      font-size: 12px;
    }
  }

  &--md {
    width: 56px;
    height: 56px;

    .entity-avatar__initials {
      font-size: 18px;
    }
  }

  &--lg {
    width: 72px;
    height: 72px;

    .entity-avatar__initials {
      font-size: 22px;
    }
  }
}

.entity-avatar__initials {
  color: #fff;
  font-weight: 600;
  line-height: 1;
  letter-spacing: 0.02em;
  font-family: Inter, 'SF UI Display', system-ui, sans-serif;
  user-select: none;
}
</style>
