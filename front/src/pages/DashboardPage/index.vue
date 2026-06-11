<template>
  <div class="dashboard-page">
    <PageHeader :title="t('dashboard.title')" icon="pi pi-home" />

    <div class="dashboard-page__content">
      <div class="row g-4">
        <div class="col-12">
          <div class="dashboard-empty">
            <i class="pi pi-chart-bar dashboard-empty__icon" />
            <p class="dashboard-empty__text">{{ t('dashboard.coming_soon') }}</p>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.dashboard-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  // Компенсируем padding layout__content для header
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.dashboard-page__content {
  padding: $space-6;
  flex: 1;
}

.dashboard-empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  background-color: $surface-0;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  gap: $space-4;
  min-height: 200px;
}

.dashboard-empty__icon {
  font-size: 48px;
  color: $surface-400;
}

.dashboard-empty__text {
  font-size: $font-size-md;
  color: $surface-500;
  margin: 0;
}
</style>
