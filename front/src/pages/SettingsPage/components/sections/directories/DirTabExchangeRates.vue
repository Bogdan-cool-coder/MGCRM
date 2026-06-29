<template>
  <div class="dir-tab-exchange-rates">
    <div class="dir-tab-toolbar">
      <div class="dir-tab-toolbar__spacer" />
      <div v-if="pageRef?.canWrite" class="dir-tab-toolbar__actions">
        <Button
          icon="pi pi-refresh"
          :label="t('catalog.exchangeRates.page.refresh')"
          severity="secondary"
          :loading="pageRef?.refreshing"
          @click="pageRef?.refreshRates()"
        />
        <Button
          icon="pi pi-plus"
          :label="t('catalog.exchangeRates.page.addManual')"
          @click="pageRef?.openCreateDialog()"
        />
      </div>
    </div>

    <div class="dir-tab-body">
      <ExchangeRatesPage ref="pageRef" :embedded="true" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import ExchangeRatesPage from '@/pages/ExchangeRatesPage/index.vue'

const { t } = useI18n()

const pageRef = ref<InstanceType<typeof ExchangeRatesPage> | null>(null)
</script>

<style lang="scss" scoped>
.dir-tab-exchange-rates {
  display: flex;
  flex-direction: column;
}

.dir-tab-toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);
  background: $surface-card;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
    border-bottom-color: var(--p-surface-200);
  }
}

.dir-tab-toolbar__spacer {
  flex: 1;
}

.dir-tab-toolbar__actions {
  display: flex;
  align-items: center;
  gap: $space-2;

  // Dark mode: filled secondary buttons resolve {surface.700} → #E3E4E6 (light)
  // due to inverted dark surface scale. Override to a legible dark fill.
  .app-dark & :deep(.p-button-secondary:not(.p-button-text):not(.p-button-outlined)) {
    background: var(--p-surface-200);
    border-color: var(--p-surface-300);
    color: var(--p-surface-900);

    &:hover {
      background: var(--p-surface-300);
      border-color: var(--p-surface-400);
    }
  }
}

.dir-tab-body {
  flex: 1;
}
</style>
