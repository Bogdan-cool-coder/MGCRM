<template>
  <!-- TODO: финансовые условия из договора — отдельный спринт -->
  <div class="deal-tab-finances">
    <div class="deal-tab-finances__stub">
      <i class="pi pi-lock deal-tab-finances__stub-icon" />
      <p class="deal-tab-finances__stub-title">{{ t('sales.deal.finances.stub') }}</p>
      <p class="deal-tab-finances__stub-hint">{{ t('sales.deal.finances.stubHint') }}</p>

      <div class="deal-tab-finances__skeletons">
        <Skeleton height="18px" class="mb-2" />
        <Skeleton height="18px" class="mb-2" />
        <Skeleton height="18px" width="60%" />
      </div>

      <Tag severity="warn" :value="t('sales.deal.finances.waitingForDoc')" class="mt-3" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.deal-tab-finances {
  padding: $space-4 $space-3;

  &__stub {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-2;
    text-align: center;
    padding: $space-6 $space-4;
  }

  &__stub-icon {
    font-size: 2rem;
    color: var(--p-surface-400);
    opacity: 0.6;
  }

  &__stub-title {
    font-size: $font-size-base;
    font-weight: $font-weight-semibold;
    color: $surface-700;
    margin: 0;

    .app-dark & {
      color: $surface-200;
    }
  }

  &__stub-hint {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
    max-width: 240px;
  }

  &__skeletons {
    width: 100%;
    max-width: 280px;
    margin-top: $space-2;
  }
}
</style>
