<template>
  <div
    class="crm-avatar"
    :class="[square ? 'crm-avatar--square' : 'crm-avatar--round']"
    :style="avatarStyle"
    :aria-label="name"
  >
    <span class="crm-avatar__initials">{{ initials }}</span>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'

const props = withDefaults(
  defineProps<{
    name: string
    size?: number
    square?: boolean
  }>(),
  {
    size: 32,
    square: false,
  },
)

const initials = computed(() => {
  const words = props.name.trim().split(/\s+/).filter(Boolean)
  if (words.length === 0) return '?'
  const first = words[0] ?? ''
  if (words.length === 1) return first.charAt(0).toUpperCase()
  const second = words[1] ?? ''
  return (first.charAt(0) + second.charAt(0)).toUpperCase()
})

const avatarStyle = computed(() => ({
  width: `${props.size}px`,
  height: `${props.size}px`,
  fontSize: `${Math.round(props.size * 0.38)}px`,
}))
</script>

<style lang="scss" scoped>
.crm-avatar {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  background: $primary-900;
  flex-shrink: 0;
  user-select: none;

  &--round {
    border-radius: $radius-circle;
  }

  &--square {
    border-radius: $radius-md;
  }
}

.crm-avatar__initials {
  color: $surface-0;
  font-weight: $font-weight-semibold;
  line-height: 1;
  letter-spacing: 0.02em;
}
</style>
