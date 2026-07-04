<template>
  <Popover ref="panelRef" class="products-filter-panel">
    <div class="products-filter-panel__body">
      <!-- Group -->
      <div class="products-filter-panel__field">
        <label class="products-filter-panel__label">{{ t('catalog.products.page.filters.group') }}</label>
        <Select
          :model-value="filter.group_id"
          :options="groups"
          option-label="name"
          option-value="id"
          :placeholder="t('catalog.products.page.filters.groupPlaceholder')"
          show-clear
          class="products-filter-panel__control"
          @update:model-value="update('group_id', $event)"
        />
      </div>

      <!-- Pricing type -->
      <div class="products-filter-panel__field">
        <label class="products-filter-panel__label">{{ t('catalog.products.page.filters.pricingType') }}</label>
        <Select
          :model-value="filter.pricing_type"
          :options="pricingTypeOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('catalog.products.page.filters.pricingTypePlaceholder')"
          show-clear
          class="products-filter-panel__control"
          @update:model-value="update('pricing_type', $event)"
        />
      </div>

      <!-- Status -->
      <div class="products-filter-panel__field">
        <label class="products-filter-panel__label">{{ t('catalog.products.page.filters.status') }}</label>
        <Select
          :model-value="filter.active_only"
          :options="statusOptions"
          option-label="label"
          option-value="value"
          class="products-filter-panel__control"
          @update:model-value="update('active_only', $event)"
        />
      </div>

      <!-- Footer actions -->
      <div class="products-filter-panel__footer">
        <Button
          v-if="activeFilterCount > 0"
          :label="t('catalog.products.page.filters.reset')"
          severity="secondary"
          text
          size="small"
          @click="onReset"
        />
        <Button
          :label="t('catalog.products.page.toolbar.done')"
          severity="primary"
          size="small"
          @click="close"
        />
      </div>
    </div>
  </Popover>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Select from 'primevue/select'
import Button from 'primevue/button'
import type { ProductGroupDto } from '@/entities/catalog'
import type { ProductsFilter } from '../composables/useProductsPageData'

defineProps<{
  filter: ProductsFilter
  groups: ProductGroupDto[]
  activeFilterCount: number
}>()

const emit = defineEmits<{
  'update:filter': [patch: Partial<ProductsFilter>]
  apply: []
  reset: []
}>()

const { t } = useI18n()

const panelRef = ref<InstanceType<typeof Popover> | null>(null)

const pricingTypeOptions = computed(() => [
  { value: 'fixed', label: t('catalog.products.pricingType.fixed') },
  { value: 'tiered', label: t('catalog.products.pricingType.tiered') },
  { value: 'per_minute', label: t('catalog.products.pricingType.per_minute') },
  { value: 'package', label: t('catalog.products.pricingType.package') },
  { value: 'custom', label: t('catalog.products.pricingType.custom') },
])

const statusOptions = computed(() => [
  { label: t('catalog.products.page.filters.statusAll'), value: null },
  { label: t('catalog.products.page.filters.statusActive'), value: true },
  { label: t('catalog.products.page.filters.statusArchived'), value: false },
])

function update<K extends keyof ProductsFilter>(key: K, value: ProductsFilter[K]) {
  emit('update:filter', { [key]: value } as Partial<ProductsFilter>)
  emit('apply')
}

function onReset() {
  emit('reset')
}

function toggle(event: Event) {
  panelRef.value?.toggle(event)
}

function close() {
  panelRef.value?.hide()
}

defineExpose({ toggle })
</script>

<style lang="scss" scoped>
.products-filter-panel__body {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  min-width: 260px;
  padding: $space-1;
}

.products-filter-panel__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.products-filter-panel__label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.products-filter-panel__control {
  width: 100%;
}

.products-filter-panel__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  padding-top: $space-2;
  border-top: 1px solid $surface-100;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
