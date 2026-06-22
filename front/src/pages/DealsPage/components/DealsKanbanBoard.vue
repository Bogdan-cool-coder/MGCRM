<template>
  <div class="kanban-board">
    <!-- Loading: 4 skeleton columns -->
    <template v-if="loading">
      <div
        v-for="i in 4"
        :key="i"
        class="kanban-board__skeleton-col"
      >
        <Skeleton height="28px" class="mb-2" />
        <Skeleton v-for="j in 3" :key="j" height="80px" class="mb-2" />
      </div>
    </template>

    <!-- Empty state: no deals in pipeline at all -->
    <div
      v-else-if="!loading && allColumnsEmpty"
      class="kanban-board__empty"
    >
      <i class="pi pi-briefcase kanban-board__empty-icon" />
      <p class="kanban-board__empty-title">{{ t('sales.deals.page.empty.title') }}</p>
      <p class="kanban-board__empty-subtitle">{{ t('sales.deals.page.empty.subtitle') }}</p>
      <Button
        icon="pi pi-plus"
        :label="t('sales.deals.page.create')"
        @click="emit('create')"
      />
    </div>

    <!-- Columns (visible only; hidden stages controlled via FilterPanel) -->
    <template v-else>
      <DealsKanbanColumn
        v-for="(col, idx) in visibleColumns"
        :key="col.stage.id"
        :data-stage-id="col.stage.id"
        :column="col"
        :column-index="idx"
        :loading="loading"
        @drop="onDrop"
        @title-change="(cid: number, title: string) => emit('titleChange', cid, title)"
        @load-more="emit('loadMore', $event)"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import DealsKanbanColumn from './DealsKanbanColumn.vue'
import type { BoardColumnDto, DealCardDto } from '@/entities/sales'

const props = defineProps<{
  visibleColumns: BoardColumnDto[]
  loading: boolean
}>()

const emit = defineEmits<{
  drop: [card: DealCardDto, fromStageId: number, toStageId: number]
  titleChange: [cardId: number, title: string]
  loadMore: [stageId: number]
  create: []
}>()

const { t } = useI18n()

const allColumnsEmpty = computed(() => {
  return props.visibleColumns.every((col) => col.total === 0)
})

function onDrop(card: DealCardDto, fromStageId: number, toStageId: number) {
  emit('drop', card, fromStageId, toStageId)
}
</script>

<style lang="scss" scoped>
.kanban-board {
  display: flex;
  flex-direction: row;
  gap: $space-3;
  overflow-x: auto;
  overflow-y: hidden;
  padding-bottom: $space-4;
  min-height: 400px;
  align-items: flex-start;

  // Smooth scroll
  scroll-behavior: smooth;
  -webkit-overflow-scrolling: touch;

  // Custom scrollbar
  scrollbar-width: thin;
  scrollbar-color: $surface-300 transparent;

  &::-webkit-scrollbar {
    height: 6px;
  }

  &::-webkit-scrollbar-track {
    background: transparent;
  }

  &::-webkit-scrollbar-thumb {
    background: $surface-300;
    border-radius: $radius-xs;
  }
}

.kanban-board__skeleton-col {
  width: 284px;
  min-width: 284px;
  flex-shrink: 0;
  padding: $space-3;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.kanban-board__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  width: 100%;
  color: $surface-400;
}

.kanban-board__empty-icon {
  font-size: $font-size-icon-2xl;
  color: $surface-400;
}

.kanban-board__empty-title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
}

.kanban-board__empty-subtitle {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}
</style>
