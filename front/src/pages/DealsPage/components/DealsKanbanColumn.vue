<template>
  <div class="kanban-col">
    <!-- Column header -->
    <div class="kanban-col__header">
      <div class="kanban-col__title-row">
        <span
          class="kanban-col__dot"
          :style="{ backgroundColor: column.stage.color ?? 'var(--p-surface-400)' }"
        />
        <span class="kanban-col__name">{{ column.stage.name }}</span>
      </div>
      <div class="kanban-col__stats">
        <span class="kanban-col__count">
          {{ t('sales.deals.page.kanban.deals', { count: column.total }) }}
        </span>
        <span class="kanban-col__sum">
          {{ formatCurrency(column.sum_amount, column.currency) }}
        </span>
      </div>
    </div>

    <!-- Loading skeleton -->
    <template v-if="loading">
      <div v-for="i in 3" :key="i" class="kanban-col__skeleton">
        <Skeleton height="80px" />
      </div>
    </template>

    <!-- Draggable card list -->
    <draggable
      v-else
      v-model="localDeals"
      group="deals"
      item-key="id"
      :animation="200"
      ghost-class="kanban-card--ghost"
      drag-class="kanban-card--dragging"
      class="kanban-col__list"
      :class="{ 'kanban-col__list--empty': localDeals.length === 0 }"
      @end="onDragEnd"
    >
      <template #item="{ element }">
        <DealsKanbanCard
          :card="element"
          class="kanban-col__card"
          @title-change="(id, title) => emit('titleChange', id, title)"
        />
      </template>
    </draggable>

    <!-- Load more -->
    <div v-if="column.has_more" class="kanban-col__load-more">
      <Button
        :label="t('sales.deals.page.kanban.loadMore', { n: column.total - localDeals.length })"
        severity="secondary"
        text
        size="small"
        :loading="loadingMore"
        @click="emit('loadMore', column.stage.id)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import DealsKanbanCard from './DealsKanbanCard.vue'
import { formatCurrency } from '@/utils/currency'
import type { BoardColumnDto, DealCardDto } from '@/entities/sales'

const props = defineProps<{
  column: BoardColumnDto
  loading?: boolean
  loadingMore?: boolean
}>()

const emit = defineEmits<{
  drop: [card: DealCardDto, fromStageId: number, toStageId: number]
  titleChange: [cardId: number, title: string]
  loadMore: [stageId: number]
}>()

const { t } = useI18n()

// Local reactive deals list (vuedraggable v-model)
const localDeals = ref<DealCardDto[]>([...props.column.deals])

// Sync when column.deals changes externally (after rollback, refresh)
watch(
  () => props.column.deals,
  (next) => {
    localDeals.value = [...next]
  },
)

function onDragEnd(event: { item: HTMLElement; from: HTMLElement; to: HTMLElement; oldIndex: number; newIndex: number }) {
  // Determine source stageId from the from-container's data attribute
  const fromStageId = parseInt(
    (event.from as HTMLElement).closest('[data-stage-id]')?.getAttribute('data-stage-id') ?? '0',
    10,
  )
  const toStageId = parseInt(
    (event.to as HTMLElement).closest('[data-stage-id]')?.getAttribute('data-stage-id') ?? '0',
    10,
  )

  if (!fromStageId || !toStageId) return
  if (fromStageId === toStageId) return // reordering within same column — no API call

  // Find the moved card
  const movedCard = localDeals.value[event.newIndex]
  if (!movedCard) return

  emit('drop', movedCard, fromStageId, toStageId)
}
</script>

<style lang="scss" scoped>
.kanban-col {
  width: 280px;
  min-width: 280px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  overflow: hidden;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.kanban-col__header {
  padding: $space-3 $space-3 $space-2;
  border-bottom: 1px solid $surface-100;
  flex-shrink: 0;

  :global(.app-dark) & {
    border-bottom-color: var(--p-surface-700);
  }
}

.kanban-col__title-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-bottom: $space-1;
}

.kanban-col__dot {
  width: 10px;
  height: 10px;
  border-radius: 50%;
  flex-shrink: 0;
}

.kanban-col__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.kanban-col__stats {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.kanban-col__count {
  font-size: $font-size-xs;
  color: $surface-500;
}

.kanban-col__sum {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $primary-color;
}

.kanban-col__list {
  flex: 1;
  overflow-y: auto;
  padding: $space-2;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  min-height: 80px;

  &--empty {
    min-height: 100px;
  }
}

.kanban-col__skeleton {
  padding: $space-1 $space-2;
}

.kanban-col__card {
  // Cards don't need extra margin — gap on list handles it
}

.kanban-col__load-more {
  padding: $space-1 $space-2;
  border-top: 1px solid $surface-100;
  display: flex;
  justify-content: center;

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
