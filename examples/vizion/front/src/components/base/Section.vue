<template>
  <section class="section">
    <div v-if="$slots.header || title" class="section-header">
      <h2 v-if="title" class="section-title">{{ title }}</h2>
      <slot name="header"></slot>
    </div>
    <div class="section-content">
      <slot></slot>
    </div>
  </section>
</template>

<script setup lang="ts">
defineProps<{
  title?: string
  scrollable?: boolean
}>()
</script>

<style lang="scss" scoped>
.section {
  background: $surface-0;
  border-radius: $card-border-radius;
  box-shadow: $shadow-md;
  display: flex;
  flex-direction: column;
  flex: 1;
  min-height: 0;
  margin-bottom: $space-6;

  &:last-child {
    margin-bottom: 0;
  }

  @media (max-width: 767px) {
    margin-bottom: $space-4;
  }

  .section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: $space-4 $space-4 0 $space-4;
    margin-bottom: $space-3;
    gap: $space-4;
    flex-shrink: 0;

    @media (max-width: 767px) {
      flex-wrap: wrap;
      gap: $space-3;
      padding: $space-3 $space-3 0 $space-3;
    }

    .section-title {
      margin: 0;
      font-size: $font-size-xl;
      font-weight: $font-weight-semibold;
      color: $surface-900;

      @media (max-width: 767px) {
        font-size: $font-size-lg;
      }
    }
  }

  .section-content {
    padding: 0 $space-4 $space-4 $space-4;
    flex: 1;
    min-height: 0;
    overflow: auto;

    @media (max-width: 767px) {
      padding: 0 $space-3 $space-3 $space-3;
    }
  }
}
</style>
