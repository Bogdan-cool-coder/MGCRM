<template>
  <header class="page-header">
    <div class="page-header__left">
      <i v-if="icon" :class="['page-header__icon', icon]" />
      <div class="page-header__titles">
        <h1 class="page-header__title">{{ title }}</h1>
        <p v-if="subtitle" class="page-header__subtitle">{{ subtitle }}</p>
      </div>
    </div>
    <div v-if="$slots.actions" class="page-header__actions">
      <slot name="actions" />
    </div>
  </header>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  subtitle?: string
  icon?: string
}>()
</script>

<style lang="scss" scoped>
.page-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-4;
  padding: $space-4 $space-6;
  background-color: $surface-card;
  border-bottom: 1px solid $surface-200;
  min-height: 56px;
  flex-shrink: 0;
}

.page-header__left {
  display: flex;
  align-items: center;
  gap: $space-3;
  min-width: 0;
}

.page-header__icon {
  font-size: $font-size-xl;
  color: $primary-900;
  flex-shrink: 0;
}

.page-header__titles {
  min-width: 0;
}

.page-header__title {
  font-size: $font-size-xl;
  font-weight: $font-weight-semibold;
  color: $surface-900;
  margin: 0;
  line-height: $line-height-tight;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.page-header__subtitle {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 2px 0 0;
  line-height: $line-height-normal;
}

.page-header__actions {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-shrink: 0;
}
</style>
