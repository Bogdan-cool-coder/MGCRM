<template>
  <div class="page-header">
    <h1 class="page-title">{{ title }}</h1>
    <div v-if="$slots.actions" class="header-actions">
      <slot name="actions"></slot>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
}>()
</script>

<style lang="scss" scoped>
.page-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  margin-bottom: $space-6;
  flex-wrap: wrap;
  gap: $space-4;

  .page-title {
    margin: 0;
    font-size: $font-size-2xl;
    font-weight: $font-weight-semibold;
    color: $surface-900;
    
    @media (max-width: 767px) {
      font-size: $font-size-xl;
    }
  }

  .header-actions {
    display: flex;
    gap: $space-2;
    flex-shrink: 0;
  }
}
</style>
