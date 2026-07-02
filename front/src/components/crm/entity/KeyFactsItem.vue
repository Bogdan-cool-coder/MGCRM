<template>
  <dt class="key-facts-item__label">
    {{ label }}<span v-if="required" class="key-facts-item__req" aria-hidden="true"> *</span>
  </dt>
  <dd class="key-facts-item__value">
    <slot />
  </dd>
</template>

<script setup lang="ts">
defineProps<{
  label: string
  required?: boolean
}>()
</script>

<style lang="scss" scoped>
.key-facts-item__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  color: $surface-500;
  white-space: nowrap;
  padding: $space-1 0;
  display: flex;
  align-items: center;
  min-height: 32px;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.key-facts-item__req {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  line-height: 1;
  margin-left: $space-1;
}

.key-facts-item__value {
  font-size: $font-size-sm;
  color: $surface-800;
  margin: 0;
  padding: 0;
  min-height: 32px;
  display: flex;
  align-items: center;

  .app-dark & {
    color: var(--p-surface-100);
  }
}
</style>
