<template>
  <div class="kanban-col" :data-stage-id="column.stage.id">
    <!-- Column header: tint bg + border-top color strip -->
    <div
      class="kanban-col__header"
      :style="headerStyle"
    >
      <!-- Title row: count pill (left) · stage name (centre) · spacer (right) -->
      <div class="kanban-col__title-row">
        <span class="kanban-col__count">
          {{ column.total }}
        </span>
        <span class="kanban-col__name">
          {{ column.stage.name }}
        </span>
        <span />
      </div>
      <!-- Sum row: centred -->
      <div class="kanban-col__sum-row">
        <span
          ref="sumRef"
          class="kanban-col__sum"
          @mouseenter="showPopover"
          @mouseleave="hidePopover"
          @click="showPopover"
        >
          {{ formattedSum }}
        </span>
        <i
          v-if="column.multi_currency_warning"
          class="pi pi-info-circle kanban-col__multi-icon"
        />
      </div>
    </div>

    <!-- Multi-currency Popover -->
    <Popover ref="popoverRef">
      <div class="kanban-col__currency-popup">
        <div class="kanban-col__currency-title">
          {{ column.fx_rate_available
            ? t('sales.deals.page.currency.tooltipTitle', { sum: plainSum })
            : t('sales.deals.page.currency.nativeTooltipTitle') }}
        </div>
        <div v-if="Object.keys(column.amounts_by_currency).length === 0" class="kanban-col__currency-empty">
          {{ t('sales.deals.page.currency.noRate') }}
        </div>
        <template v-else>
          <div
            v-for="(amount, cur) in sortedAmountsByCurrency"
            :key="cur"
            class="kanban-col__currency-row"
          >
            <span class="kanban-col__currency-code">{{ cur }}</span>
            <span class="kanban-col__currency-amount">{{ formatNativeCurrency(amount, cur) }}</span>
          </div>
        </template>
      </div>
    </Popover>

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
          :stage="column.stage"
          class="kanban-col__card"
          @title-change="(id, title) => emit('titleChange', id, title)"
        />
      </template>
    </draggable>

    <!-- Load more (hidden until backend supports no-pagination — B4) -->
    <div v-if="false && column.has_more" class="kanban-col__load-more">
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
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import draggable from 'vuedraggable'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Popover from 'primevue/popover'
import DealsKanbanCard from './DealsKanbanCard.vue'
import type { BoardColumnDto, DealCardDto } from '@/entities/sales'

const props = defineProps<{
  column: BoardColumnDto
  loading?: boolean
  loadingMore?: boolean
  columnIndex?: number
}>()

const emit = defineEmits<{
  drop: [card: DealCardDto, fromStageId: number, toStageId: number]
  titleChange: [cardId: number, title: string]
  loadMore: [stageId: number]
}>()

const { t } = useI18n()

// ── Local deals (vuedraggable) ─────────────────────────────────────────────────

const localDeals = ref<DealCardDto[]>([...props.column.deals])

watch(
  () => props.column.deals,
  (next) => { localDeals.value = [...next] },
)

function onDragEnd(event: { item: HTMLElement; from: HTMLElement; to: HTMLElement; oldIndex: number; newIndex: number }) {
  const fromStageId = parseInt(
    (event.from as HTMLElement).closest('[data-stage-id]')?.getAttribute('data-stage-id') ?? '0',
    10,
  )
  const toStageId = parseInt(
    (event.to as HTMLElement).closest('[data-stage-id]')?.getAttribute('data-stage-id') ?? '0',
    10,
  )
  if (!fromStageId || !toStageId) return
  if (fromStageId === toStageId) return

  const movedCard = localDeals.value[event.newIndex]
  if (!movedCard) return
  emit('drop', movedCard, fromStageId, toStageId)
}

// ── Header style — tint 13% + border-top 3px ──────────────────────────────────

// DS stage palette fallback by index (teal/blue/amber/pink/purple)
const DS_STAGE_PALETTE = [
  '#0D9488', // teal
  '#2563EB', // blue
  '#D97706', // amber
  '#DB2777', // pink
  '#7C3AED', // purple
  '#059669', // emerald
  '#DC2626', // red
  '#0891B2', // cyan
]

const effectiveStageColor = computed(() => {
  if (props.column.stage.color) return props.column.stage.color
  const idx = (props.columnIndex ?? 0) % DS_STAGE_PALETTE.length
  return DS_STAGE_PALETTE[idx] as string
})

const headerStyle = computed(() => {
  const color = effectiveStageColor.value
  return {
    borderTop: `3px solid ${color}`,
    backgroundColor: `color-mix(in srgb, ${color} 13%, var(--p-surface-card))`,
  }
})

// ── Sum formatting ─────────────────────────────────────────────────────────────

const plainSum = computed(() => {
  const kopecks = props.column.sum_amount
  const rub = kopecks / 100
  const cur = props.column.base_currency || props.column.currency || 'RUB'
  const sign = cur === 'RUB' ? '₽' : cur === 'KZT' ? '₸' : cur === 'USD' ? '$' : cur

  if (rub >= 1_000_000) {
    return `${(rub / 1_000_000).toFixed(1)} млн ${sign}`
  }
  if (rub >= 1_000) {
    return `${Math.round(rub / 1_000)} тыс. ${sign}`
  }
  return `${Math.round(rub)} ${sign}`
})

const formattedSum = computed(() => {
  if (!props.column.fx_rate_available) {
    const keys = Object.keys(props.column.amounts_by_currency)
    if (keys.length === 1 && keys[0]) {
      const cur = keys[0]
      const amount = props.column.amounts_by_currency[cur]
      if (amount !== undefined) {
        return formatNativeCurrency(amount, cur)
      }
    }
    if (keys.length > 1) {
      const firstKey = keys[0]
      if (firstKey) {
        const amount = props.column.amounts_by_currency[firstKey]
        if (amount !== undefined) {
          return `${formatNativeCurrency(amount, firstKey)} …`
        }
      }
    }
    return plainSum.value
  }
  return `≈ ${plainSum.value}`
})

const sortedAmountsByCurrency = computed(() => {
  return Object.fromEntries(
    Object.entries(props.column.amounts_by_currency).sort(([, a], [, b]) => b - a),
  )
})

function formatNativeCurrency(kopecks: number, currency: string): string {
  const amount = kopecks / 100
  const sign = currency === 'RUB' ? '₽' : currency === 'KZT' ? '₸' : currency === 'USD' ? '$' : currency
  return `${amount.toLocaleString('ru-RU')} ${sign}`
}

// ── Popover ────────────────────────────────────────────────────────────────────

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)
const sumRef = ref<HTMLElement | null>(null)

function showPopover(event: MouseEvent) {
  popoverRef.value?.show(event)
}

function hidePopover() {
  popoverRef.value?.hide()
}
</script>

<style lang="scss" scoped>
.kanban-col {
  width: 284px;
  min-width: 284px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-lg;
  overflow: hidden;
  max-height: 100%;

  .app-dark & {
    border-color: var(--p-surface-300);
  }
}

// ─── Header ───────────────────────────────────────────────────────────────────

.kanban-col__header {
  padding: 11px 13px 9px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border-bottom: 1px solid rgba(0, 0, 0, 0.08); // subtle divider under tint header — no token matches alpha-blend intent
  flex-shrink: 0;
}

// Title row: grid 34px 1fr 34px
.kanban-col__title-row {
  display: grid;
  grid-template-columns: 34px 1fr 34px;
  align-items: center;
  margin-bottom: $space-1;
}

.kanban-col__count {
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  color: $surface-600;
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-pill;
  padding: 1px 0;
  min-width: 26px;
  text-align: center;
  justify-self: start;

  .app-dark & {
    background: var(--p-surface-200);
    border-color: var(--p-surface-300);
    color: var(--p-surface-50);
  }
}

.kanban-col__name {
  text-align: center;
  font-size: $font-size-sm; // 14px — uppercase stage name
  font-weight: $font-weight-bold;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: $surface-900;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-50);
  }
}

// Sum row: centred under the name
.kanban-col__sum-row {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: $space-1;
}

.kanban-col__sum {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  cursor: pointer;

  &:hover {
    text-decoration: underline;
  }

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.kanban-col__multi-icon {
  font-size: $font-size-2xs;
  color: $surface-400;
}

// ─── Currency popup ───────────────────────────────────────────────────────────

.kanban-col__currency-popup {
  min-width: 180px;
  padding: $space-1 0;
}

.kanban-col__currency-title {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  padding: $space-1 $space-3 $space-2;
  border-bottom: 1px solid $surface-100;
  margin-bottom: $space-1;

  .app-dark & {
    color: var(--p-surface-100);
    border-bottom-color: var(--p-surface-700);
  }
}

.kanban-col__currency-empty {
  font-size: $font-size-xs;
  color: $surface-400;
  padding: $space-1 $space-3;
}

.kanban-col__currency-row {
  display: flex;
  justify-content: space-between;
  align-items: center;
  gap: $space-4;
  padding: $space-1 $space-3;
  font-size: $font-size-xs;
}

.kanban-col__currency-code {
  color: $surface-500;
  font-weight: $font-weight-medium;
}

.kanban-col__currency-amount {
  color: $surface-700;
  font-weight: $font-weight-semibold;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

// ─── Card list ────────────────────────────────────────────────────────────────

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

.kanban-col__load-more {
  padding: $space-1 $space-2;
  border-top: 1px solid $surface-100;
  display: flex;
  justify-content: center;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
