<template>
  <div class="not-found-state">
    <i class="pi pi-exclamation-triangle not-found-icon"></i>
    <h2 class="not-found-title">{{ title }}</h2>
    <p v-if="description" class="not-found-description">{{ description }}</p>
    <slot name="action"></slot>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  description?: string
}>()
</script>

<style lang="scss" scoped>
.not-found-state {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: 4rem 2rem;
  text-align: center;

  .not-found-icon {
    font-size: $font-size-4xl;
    color: $orange-500;
    margin-bottom: 1rem;
  }

  .not-found-title {
    margin: 0 0 0.5rem;
    color: $surface-700;
  }

  .not-found-description {
    margin: 0;
    color: $surface-600;
  }
}
</style>
