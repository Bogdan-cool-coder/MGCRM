<template>
  <dl class="key-facts">
    <slot />
  </dl>
</template>

<script setup lang="ts">
// Container for key-value pairs using InlineEditableField.
// Usage: wrap <KeyFactsItem> inside this.
</script>

<style lang="scss" scoped>
.key-facts {
  display: grid;
  grid-template-columns: auto 1fr;
  gap: $space-1 $space-3;
  margin: 0;
  align-items: start;
}
</style>
