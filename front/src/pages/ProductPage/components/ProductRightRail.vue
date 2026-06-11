<template>
  <div class="product-rail">
    <!-- Active -->
    <div class="product-rail__section">
      <p class="product-rail__label">{{ t('catalog.product.page.rail.isActive') }}</p>
      <div class="product-rail__row">
        <ToggleSwitch
          :model-value="product.is_active"
          :disabled="!canWrite"
          @update:model-value="$emit('toggle-active')"
        />
        <span class="product-rail__value">
          {{ product.is_active ? t('common.yes') : t('common.no') }}
        </span>
      </div>
    </div>

    <!-- Pricing Type -->
    <div class="product-rail__section">
      <p class="product-rail__label">{{ t('catalog.product.page.rail.pricingType') }}</p>
      <ProductPricingTypeTag :pricing-type="product.pricing_type" />
    </div>

    <!-- Template code -->
    <div v-if="product.maps_to_product_code" class="product-rail__section">
      <p class="product-rail__label">{{ t('catalog.product.page.rail.templateCode') }}</p>
      <span class="product-rail__code">{{ product.maps_to_product_code }}</span>
    </div>

    <!-- Created at -->
    <div v-if="product.created_at" class="product-rail__section">
      <p class="product-rail__label">{{ t('catalog.product.page.rail.createdAt') }}</p>
      <span class="product-rail__value">{{ formatDate(product.created_at) }}</span>
    </div>

    <!-- Updated at -->
    <div v-if="product.updated_at" class="product-rail__section">
      <p class="product-rail__label">{{ t('catalog.product.page.rail.updatedAt') }}</p>
      <span class="product-rail__value">{{ formatDate(product.updated_at) }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import ToggleSwitch from 'primevue/toggleswitch'
import { useUserStore } from '@/stores/user'
import ProductPricingTypeTag from '@/pages/ProductsPage/components/ProductPricingTypeTag.vue'
import type { ProductDto } from '@/entities/catalog'

defineProps<{
  product: ProductDto
}>()

defineEmits<{
  'toggle-active': []
}>()

const { t } = useI18n()
const userStore = useUserStore()

const canWrite = computed(() => {
  const role = userStore.getUserRole
  return role === 'admin' || role === 'director'
})

function formatDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU')
}
</script>

<style lang="scss" scoped>
.product-rail {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  padding: $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-4;

  &__section {
    display: flex;
    flex-direction: column;
    gap: $space-1;
  }

  &__label {
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
    color: $surface-500;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    margin: 0;
  }

  &__row {
    display: flex;
    align-items: center;
    gap: $space-2;
  }

  &__value {
    font-size: $font-size-sm;
    color: $surface-800;
  }

  &__code {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    color: $surface-600;
    background: $surface-100;
    border-radius: $radius-sm;
    padding: 2px $space-2;
  }
}
</style>
