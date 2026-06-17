<template>
  <div class="stage-color-picker">
    <!-- Neutral option -->
    <button
      type="button"
      class="stage-color-picker__swatch stage-color-picker__swatch--neutral"
      :class="{ 'stage-color-picker__swatch--active': modelValue === null }"
      :title="t('sales.stageEditor.colorPicker.none')"
      @click="emit('update:modelValue', null)"
    >
      <i v-if="modelValue === null" class="pi pi-check stage-color-picker__check" />
    </button>

    <!-- Bright colors -->
    <button
      v-for="color in BRIGHT_COLORS"
      :key="color.hex"
      type="button"
      class="stage-color-picker__swatch"
      :class="{ 'stage-color-picker__swatch--active': modelValue === color.hex }"
      :style="{ backgroundColor: color.hex }"
      :title="color.label"
      @click="emit('update:modelValue', color.hex)"
    >
      <i
        v-if="modelValue === color.hex"
        class="pi pi-check stage-color-picker__check"
        :style="{ color: color.checkColor }"
      />
    </button>

    <!-- Soft colors -->
    <button
      v-for="color in SOFT_COLORS"
      :key="color.hex"
      type="button"
      class="stage-color-picker__swatch"
      :class="{ 'stage-color-picker__swatch--active': modelValue === color.hex }"
      :style="{ backgroundColor: color.hex, border: `2px solid ${color.borderHex}` }"
      :title="color.label"
      @click="emit('update:modelValue', color.hex)"
    >
      <i
        v-if="modelValue === color.hex"
        class="pi pi-check stage-color-picker__check"
        :style="{ color: color.checkColor }"
      />
    </button>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

defineProps<{
  modelValue: string | null
}>()

const emit = defineEmits<{
  'update:modelValue': [value: string | null]
}>()

const { t } = useI18n()

const BRIGHT_COLORS = [
  { hex: '#1D9E75', label: 'Teal', checkColor: '#ffffff' },
  { hex: '#378ADD', label: 'Blue', checkColor: '#ffffff' },
  { hex: '#EF9F27', label: 'Amber', checkColor: '#6B4A00' },
  { hex: '#D4537E', label: 'Pink', checkColor: '#ffffff' },
  { hex: '#7F77DD', label: 'Purple', checkColor: '#ffffff' },
]

const SOFT_COLORS = [
  { hex: '#E1F5EE', label: 'Soft Teal', checkColor: '#0D5C44', borderHex: '#0D5C4433' },
  { hex: '#E6F1FB', label: 'Soft Blue', checkColor: '#1A4F8A', borderHex: '#1A4F8A33' },
  { hex: '#FAEEDA', label: 'Soft Amber', checkColor: '#6B4A00', borderHex: '#6B4A0033' },
  { hex: '#FBEAF0', label: 'Soft Pink', checkColor: '#7A2347', borderHex: '#7A234733' },
  { hex: '#EAF3DE', label: 'Soft Green', checkColor: '#3A6020', borderHex: '#3A602033' },
]
</script>

<style lang="scss" scoped>
.stage-color-picker {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  align-items: center;
}

.stage-color-picker__swatch {
  width: 28px;
  height: 28px;
  border-radius: 50%;
  border: 2px solid transparent;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: transform var(--app-transition-fast), box-shadow var(--app-transition-fast);
  padding: 0;

  &:hover {
    transform: scale(1.15);
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.2);
  }

  &--active {
    box-shadow: 0 0 0 3px var(--p-primary-color);
  }

  &--neutral {
    background: var(--p-surface-200);
    border-color: var(--p-surface-300);

    :global(.app-dark) & {
      background: var(--p-surface-600);
      border-color: var(--p-surface-500);
    }
  }
}

.stage-color-picker__check {
  font-size: 12px;
}
</style>
