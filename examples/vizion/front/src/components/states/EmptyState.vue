<template>
  <div class="empty-state">
    <i :class="icon" class="empty-icon"></i>
    <p class="empty-message">{{ message }}</p>
    <slot name="action"></slot>
  </div>
</template>

<script setup lang="ts">
withDefaults(
  defineProps<{
    message: string
    icon?: string
  }>(),
  {
    icon: 'pi pi-inbox',
  },
)
</script>

<style lang="scss" scoped>
.empty-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;
  color: $surface-600;
  background: $surface-0;
  border-radius: $card-border-radius;
  border: 1px dashed $surface-200;

  .empty-icon {
    font-size: $font-size-4xl;
    margin-bottom: 1rem;
    opacity: 0.5;
  }

  .empty-message {
    margin: 0;
    font-size: $font-size-lg;
  }
}
</style>
