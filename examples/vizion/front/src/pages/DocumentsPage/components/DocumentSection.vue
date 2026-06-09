<template>
  <section class="document-section">
    <button
      type="button"
      class="document-section__header"
      :aria-expanded="open"
      @click="open = !open"
    >
      <i :class="['pi', open ? 'pi-chevron-down' : 'pi-chevron-right']" aria-hidden="true" />
      <span class="document-section__title">{{ title }}</span>
      <span class="document-section__count">{{ items.length }}</span>
    </button>

    <div v-show="open" class="document-section__grid">
      <div v-for="item in items" :key="item.id" class="document-section__cell">
        <!-- A list card is open-only — it navigates to the document page on
             click. Per-document actions (publish / delete / edit) live on the
             detail page header (DocumentActionsMenu), not on the library tile. -->
        <DocumentCard
          :title="item.localizedName"
          :description="item.localizedDescription"
          :type="item.type"
          :is-system="item.isSystem"
          :is-published="item.isPublished"
          @open="$emit('open', item.id)"
        />
      </div>
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import DocumentCard from './DocumentCard.vue'
import type { LocalizedDocumentItem } from '../composables/useDocumentsPageData'

interface Props {
  title: string
  items: LocalizedDocumentItem[]
}

defineProps<Props>()

defineEmits<{
  open: [id: number]
}>()

const open = ref(true)
</script>

<style lang="scss" scoped>
.document-section {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;

  &__header {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    background: transparent;
    border: none;
    padding: 0;
    cursor: pointer;
    color: $surface-700;
    font-size: $font-size-sm;
    font-weight: $font-weight-semibold;
    text-transform: uppercase;
    letter-spacing: 0.04em;

    .pi {
      font-size: 0.75rem;
    }
  }

  &__count {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 1.4rem;
    height: 1.4rem;
    padding: 0 0.4rem;
    border-radius: 999px;
    background: $surface-200;
    color: $surface-700;
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;
  }

  &__grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
    gap: 1.5rem;
  }

  &__cell {
    display: flex;
  }
}
</style>
