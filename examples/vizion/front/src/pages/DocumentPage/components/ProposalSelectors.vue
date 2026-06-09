<template>
  <div class="proposal-selectors">
    <!-- Object picker (async MacroData search) -->
    <div class="form-field">
      <label class="form-label">{{ t('object.label') }}</label>
      <Select
        :model-value="selectedEstateSellId"
        :options="objectOptions"
        option-value="value"
        option-label="label"
        :placeholder="t('object.placeholder')"
        :filter="true"
        :filter-placeholder="t('object.search')"
        :loading="objectsLoading"
        :clearable="true"
        class="w-full"
        @show="$emit('object-show')"
        @filter="$emit('object-filter', $event.value)"
        @update:model-value="$emit('object-select', $event)"
      >
        <template #empty>
          <span v-if="objectsLoading || !objectsLoadedOnce">{{ t('object.loading') }}</span>
          <span v-else>{{ t('object.noResults') }}</span>
        </template>
      </Select>
    </div>

    <!-- Promotion picker -->
    <div class="form-field">
      <div class="form-label-row">
        <label class="form-label">{{ t('promotion.label') }}</label>
        <!-- Unobtrusive admin shortcut to the promotion settings (was a header
             gear before — moved next to the field it configures). -->
        <a
          v-if="canManagePromotions"
          class="manage-link"
          role="button"
          tabindex="0"
          @click="$emit('open-promotions')"
          @keydown.enter.prevent="$emit('open-promotions')"
          @keydown.space.prevent="$emit('open-promotions')"
        >
          {{ t('settings.openPromotions') }}
        </a>
      </div>
      <Select
        :model-value="selectedPromotionId"
        :options="promotionOptions"
        option-value="value"
        option-label="label"
        :placeholder="t('promotion.placeholder')"
        :loading="promotionsLoading"
        :clearable="true"
        class="w-full"
        @update:model-value="$emit('promotion-select', $event)"
      >
        <template #empty>
          <span>{{ t('promotion.empty') }}</span>
        </template>
      </Select>
    </div>

    <!-- Discount calculator -->
    <div v-if="hasPromotion" class="form-field discount-field">
      <label class="form-label">
        {{ t('discount.label') }}
        <span class="discount-range">
          {{ discountMin }}–{{ discountMax }}{{ isPercentDiscount ? ' %' : '' }}
        </span>
      </label>

      <div class="discount-controls">
        <InputNumber
          :model-value="discount"
          :min="discountMin"
          :max="discountMax"
          :suffix="isPercentDiscount ? ' %' : ''"
          :max-fraction-digits="2"
          show-buttons
          class="discount-input"
          @update:model-value="$emit('discount-input', $event)"
        />
        <Slider
          :model-value="discount"
          :min="discountMin"
          :max="discountMax"
          :step="isPercentDiscount ? 1 : 1000"
          class="discount-slider"
          @update:model-value="$emit('slider-input', $event)"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import Select from 'primevue/select'
import InputNumber from 'primevue/inputnumber'
import Slider from 'primevue/slider'
import type { EstateSellOptionDto } from '@/api/types/macrodata'

interface PromotionOption {
  value: number
  label: string
}

interface Props {
  t: (_key: string) => string
  selectedEstateSellId: number | null
  objectOptions: EstateSellOptionDto[]
  objectsLoading: boolean
  objectsLoadedOnce: boolean
  promotionOptions: PromotionOption[]
  promotionsLoading: boolean
  selectedPromotionId: number | null
  hasPromotion: boolean
  discount: number
  discountMin: number
  discountMax: number
  isPercentDiscount: boolean
  /** Admin-only: shows the "configure promotions" shortcut next to the field. */
  canManagePromotions?: boolean
}

withDefaults(defineProps<Props>(), {
  canManagePromotions: false,
})

defineEmits<{
  'object-show': []
  'object-filter': [query: string]
  'object-select': [value: number | null]
  'promotion-select': [value: number | null]
  'discount-input': [value: number | null]
  'slider-input': [value: number | number[]]
  'open-promotions': []
}>()
</script>

<style lang="scss" scoped>
.proposal-selectors {
  display: flex;
  flex-direction: column;
  gap: 1.25rem;

  .form-field {
    display: flex;
    flex-direction: column;
    gap: 0.5rem;

    .form-label-row {
      display: flex;
      align-items: baseline;
      justify-content: space-between;
      gap: 0.75rem;
    }

    .form-label {
      font-weight: $font-weight-semibold;
      font-size: $font-size-sm;
      color: $surface-700;
      display: flex;
      align-items: center;
      gap: 0.5rem;

      .discount-range {
        font-weight: 400;
        color: $surface-500;
      }
    }

    .manage-link {
      font-size: $font-size-xs;
      font-weight: $font-weight-medium;
      color: $primary-color;
      cursor: pointer;
      white-space: nowrap;

      &:hover {
        text-decoration: underline;
      }

      &:focus-visible {
        outline: 2px solid $primary-color;
        outline-offset: 2px;
        border-radius: 2px;
      }
    }
  }

  .discount-controls {
    display: flex;
    flex-direction: column;
    gap: 0.75rem;

    .discount-input,
    .discount-slider {
      width: 100%;
    }
  }
}
</style>
