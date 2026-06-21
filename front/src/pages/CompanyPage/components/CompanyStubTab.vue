<template>
  <div class="stub-tab">
    <i class="pi pi-clock stub-tab__icon" />
    <p class="stub-tab__text">{{ message }}</p>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  message: string
}>()
</script>

<style lang="scss" scoped>
.stub-tab {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-4;
  padding: $space-8;
  min-height: 200px;
  text-align: center;
}

.stub-tab__icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.stub-tab__text {
  font-size: $font-size-sm;
  color: $surface-500;
  margin: 0;
}
</style>
