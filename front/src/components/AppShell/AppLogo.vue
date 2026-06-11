<template>
  <div class="app-logo" :class="{ 'app-logo--collapsed': collapsed }">
    <!-- Collapsed: «MG» lettermark box -->
    <div v-if="collapsed" class="app-logo__mark">
      <span class="app-logo__mark-text">MG</span>
    </div>
    <!-- Expanded: SVG logo (белый инвертированный на тёмном sidebar) -->
    <img
      v-else
      src="/logo.svg"
      alt="MACRO Global CRM"
      class="app-logo__full"
      height="28"
    />
  </div>
</template>

<script setup lang="ts">
defineProps<{
  collapsed?: boolean
}>()
</script>

<style lang="scss" scoped>
.app-logo {
  display: flex;
  align-items: center;
  justify-content: flex-start;
  padding: 0 $space-4;
  height: var(--app-header-height);
  overflow: hidden;
  flex-shrink: 0;

  &--collapsed {
    justify-content: center;
    padding: 0;
  }
}

.app-logo__mark {
  width: 36px;
  height: 36px;
  border-radius: $radius-lg;
  background-color: var(--app-primary-light);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
}

.app-logo__mark-text {
  color: #ffffff;
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  letter-spacing: -0.02em;
  line-height: 1;
}

.app-logo__full {
  max-width: 180px;
  height: 28px;
  object-fit: contain;
  // Инвертируем в белый на тёмном фоне sidebar
  filter: brightness(0) invert(1);
}
</style>
