<template>
  <div class="widget-variants-panel">
    <p class="widget-variants-panel__hint">
      {{ t('widgetGenerationModal.variants.hint') }}
    </p>

    <div class="widget-variants-panel__grid">
      <WidgetVariantCard
        v-for="variant in variants"
        :key="variant.index"
        :variant="variant"
        :disabled="disabled"
        @pick="$emit('pick', $event)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import WidgetVariantCard from './WidgetVariantCard.vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type { WidgetVariantDto } from '@/api/types/chats'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

defineProps<{
  variants: WidgetVariantDto[]
  /** Locked once the user has chosen a variant. */
  disabled?: boolean
}>()

defineEmits<{
  /** 1-based variant index the user picked. */
  pick: [index: number]
}>()
</script>

<style lang="scss" scoped>
.widget-variants-panel {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  padding: $space-4;
  overflow-y: auto;

  &__hint {
    margin: 0;
    color: $surface-600;
    font-size: $font-size-sm;
    line-height: 1.5;
  }

  &__grid {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: $space-3;

    @media (max-width: 560px) {
      grid-template-columns: minmax(0, 1fr);
    }
  }
}
</style>
