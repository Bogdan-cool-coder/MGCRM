<template>
  <div class="coming-soon-block">
    <i class="pi pi-clock coming-soon-block__icon" />
    <p>{{ t('settings.comingSoon') }}</p>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.coming-soon-block {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  color: $surface-500;

  p {
    margin: 0;
    font-size: $font-size-md;
  }

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.coming-soon-block__icon {
  font-size: $font-size-icon-2xl;
  opacity: 0.4;
}
</style>
