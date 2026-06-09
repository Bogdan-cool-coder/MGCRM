<template>
  <section class="dashboard-section">
    <button
      type="button"
      class="dashboard-section__header"
      :aria-expanded="open"
      @click="open = !open"
    >
      <i :class="['pi', open ? 'pi-chevron-down' : 'pi-chevron-right']" aria-hidden="true" />
      <span class="dashboard-section__title">{{ title }}</span>
      <span class="dashboard-section__count">{{ items.length }}</span>
    </button>

    <div v-show="open" class="dashboard-section__grid">
      <DashboardCard
        v-for="item in items"
        :key="item.id"
        :title="item.localizedName"
        :is-system="item.isSystem"
        :is-published="item.isPublished"
        :widgets-count="item.widgetsCount"
        @click="$emit('open', item.id)"
      />
    </div>
  </section>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import DashboardCard from './DashboardCard.vue'
import type { LocalizedDashboardItem } from '../composables/useDashboardsPageData'

interface Props {
  title: string
  items: LocalizedDashboardItem[]
}

defineProps<Props>()

defineEmits<{
  open: [id: number]
}>()

const open = ref(true)
</script>

<style lang="scss" scoped>
.dashboard-section {
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
}
</style>
