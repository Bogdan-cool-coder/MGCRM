<template>
  <section class="widget-lib-section">
    <button
      type="button"
      class="widget-lib-section__header"
      :aria-expanded="open"
      @click="open = !open"
    >
      <i :class="['pi', open ? 'pi-chevron-down' : 'pi-chevron-right']" aria-hidden="true" />
      <span class="widget-lib-section__title">{{ title }}</span>
      <span class="widget-lib-section__count">{{ items.length }}</span>
    </button>

    <div v-show="open" class="widget-lib-section__grid">
      <WidgetPreviewCard
        v-for="item in items"
        :key="item.id"
        :item="item"
        :status="preview.statusOf(item.id).value"
        :data="preview.dataOf(item.id).value"
        @ensure="preview.ensure"
        @pick="$emit('pick', $event)"
        @delete="$emit('delete', $event)"
      />
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import WidgetPreviewCard from './WidgetPreviewCard.vue'
import type { LocalizedWidgetItem } from './WidgetLibraryModal.vue'
import type { WidgetPreviewDataApi } from '../composables/useWidgetPreviewData'

interface Props {
  title: string
  items: LocalizedWidgetItem[]
  preview: WidgetPreviewDataApi
}

defineProps<Props>()

defineEmits<{
  pick: [widgetId: number]
  delete: [item: LocalizedWidgetItem]
}>()

const open = ref(true)
</script>

<style lang="scss" scoped>
.widget-lib-section {
  display: flex;
  flex-direction: column;
  gap: 0.5rem;

  &__header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: transparent;
    border: none;
    padding: 0;
    cursor: pointer;
    color: $surface-700;
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    text-transform: uppercase;
    letter-spacing: 0.04em;

    .pi {
      font-size: 0.7rem;
    }
  }

  &__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.3rem;
    height: 1.3rem;
    padding: 0 0.35rem;
    border-radius: 999px;
    background: $surface-200;
    color: $surface-700;
    font-size: $font-size-xs;
  }

  &__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 0.6rem;
  }
}
</style>
