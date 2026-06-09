<template>
  <div class="widget-prompt-presets">
    <p class="widget-prompt-presets__greeting">{{ t('widgetGenerationModal.presets.greeting') }}</p>

    <div class="widget-prompt-presets__chips">
      <Button
        v-for="key in PRESET_KEYS"
        :key="key"
        class="widget-prompt-presets__chip"
        :label="t(`widgetGenerationModal.presets.items.${key}`)"
        severity="secondary"
        outlined
        size="small"
        type="button"
        @click="$emit('pick', t(`widgetGenerationModal.presets.items.${key}`))"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import { useLocalI18n } from '@/composables/useLocalI18n'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

defineEmits<{
  /** The fully-resolved preset phrase to drop into the chat input. */
  pick: [phrase: string]
}>()

/**
 * Fixed set of starter prompts. The phrases themselves are translatable and
 * live in `locale/{ru,en}.json` under `widgetGenerationModal.presets.items`;
 * only the key order is pinned here.
 */
const PRESET_KEYS = [
  'dealsByManager',
  'salesAmountByComplex',
  'dealsByStatus',
  'salesDynamics',
  'paymentsByObject',
] as const
</script>

<style lang="scss" scoped>
.widget-prompt-presets {
  display: flex;
  flex-direction: column;
  gap: $space-3;
  align-items: center;
  text-align: center;
  padding: $space-4;

  &__greeting {
    margin: 0;
    max-width: 32rem;
    color: $surface-600;
    font-size: $font-size-sm;
    line-height: 1.5;
  }

  &__chips {
    display: flex;
    flex-wrap: wrap;
    justify-content: center;
    gap: $space-2;
  }

  &__chip {
    border-radius: 999px;
  }
}
</style>
