<template>
  <div class="product-deals-stub">
    <i class="pi pi-briefcase product-deals-stub__icon" />
    <p class="product-deals-stub__text">{{ t('catalog.product.page.stub.deals') }}</p>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.product-deals-stub {
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  padding: $space-8;
  gap: $space-3;
  min-height: 200px;

  &__icon {
    font-size: 2rem;
    color: $surface-400;
  }

  &__text {
    font-size: $font-size-sm;
    color: $surface-500;
    margin: 0;
    text-align: center;
  }
}
</style>
