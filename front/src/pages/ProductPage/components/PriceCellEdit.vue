<template>
  <div class="price-cell" :class="{ 'price-cell--editing': isEditing }">
    <!-- Display mode -->
    <div
      v-if="!isEditing"
      class="price-cell__display"
      :class="{ 'price-cell__display--clickable': !disabled }"
      :title="disabled ? undefined : t('catalog.product.page.prices.inlineEditHint')"
      @dblclick="startEdit"
    >
      <span v-if="valueKopecks !== null" class="price-cell__value">
        {{ formatCurrency(valueKopecks, currency) }}
      </span>
      <span v-else class="price-cell__empty">—</span>
    </div>

    <!-- Edit mode -->
    <div v-else class="price-cell__edit-row">
      <InputNumber
        ref="inputRef"
        v-model="localValue"
        :min="0"
        :min-fraction-digits="0"
        :max-fraction-digits="2"
        :disabled="saving"
        class="price-cell__input"
        @keydown="onKeydown"
      />
      <Button
        icon="pi pi-check"
        size="small"
        :loading="saving"
        class="price-cell__btn-save"
        @click="onSave"
      />
      <Button
        icon="pi pi-times"
        size="small"
        severity="secondary"
        text
        :disabled="saving"
        @click="cancel"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import { formatCurrency, fromKopecks } from '@/utils/currency'

const props = defineProps<{
  valueKopecks: number | null
  currency: string
  saving?: boolean
  disabled?: boolean
}>()

const emit = defineEmits<{
  save: [valueInUnits: number]
}>()

const { t } = useI18n()

const isEditing = ref(false)
const localValue = ref<number | null>(null)
const inputRef = ref<{ $el?: HTMLElement } | null>(null)

function startEdit() {
  if (props.disabled) return
  isEditing.value = true
  localValue.value =
    props.valueKopecks !== null ? fromKopecks(props.valueKopecks) : null
  nextTick(() => {
    const el = inputRef.value?.$el?.querySelector<HTMLInputElement>('input')
    el?.focus()
    el?.select()
  })
}

function cancel() {
  isEditing.value = false
  localValue.value = null
}

async function onSave() {
  if (localValue.value === null || localValue.value < 0) {
    cancel()
    return
  }
  emit('save', localValue.value)
  isEditing.value = false
}

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    void onSave()
  } else if (e.key === 'Escape') {
    cancel()
  }
}
</script>

<style lang="scss" scoped>
.price-cell {
  &__display {
    padding: $space-1 $space-2;
    border-radius: $radius-sm;
    border: 1px solid transparent;
    min-height: 32px;
    display: flex;
    align-items: center;
    transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast);

    &--clickable {
      cursor: pointer;

      &:hover {
        // reactive raised step (light grey / navy #172847) — visible hover in both themes.
        // Was surface-50 + a dead `:global(.app-dark) .class:hover` override (class dropped).
        border-color: $surface-300;
        background-color: $surface-200;
      }
    }
  }

  &__value {
    font-size: $font-size-sm;
    color: $surface-900;
    font-variant-numeric: tabular-nums;
  }

  &__empty {
    color: $surface-400;
    font-size: $font-size-sm;
  }

  &__edit-row {
    display: flex;
    align-items: center;
    gap: $space-1;
  }

  &__input {
    // Sized so the whole edit-row (input + save/cancel) fits the 170px currency
    // column min-width → no horizontal layout shift on entering edit mode.
    width: 92px;
  }

  &__btn-save {
    flex-shrink: 0;
  }
}

// Dark hover handled reactively in the base `&--clickable:hover` rule above.
// Removed dead `:global(.app-dark) .price-cell__display--clickable:hover` (class after
// the paren is dropped by the scoped compiler → bare `.app-dark{}`).
</style>
