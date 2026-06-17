<template>
  <div class="kanban-col" :data-stage-id="column.stage.id">
    <!-- Column header with full color fill -->
    <div
      class="kanban-col__header"
      :style="headerStyle"
    >
      <div class="kanban-col__title-row">
        <span class="kanban-col__name" :style="{ color: headerTextColor }">
          {{ column.stage.name }}
        </span>
        <span class="kanban-col__count" :style="{ color: headerTextColorMuted }">
          {{ column.total }}
        </span>
        <Button
          icon="pi pi-plus"
          text
          size="small"
          class="kanban-col__add-btn"
          :style="{ color: headerTextColor }"
          :title="t('sales.deals.page.kanban.addDeal')"
          @click="emit('addDeal', column.stage.id)"
        />
      </div>
      <div class="kanban-col__sum-row">
        <span
          ref="sumRef"
          class="kanban-col__sum"
          :style="{ color: headerTextColorMuted }"
          @mouseenter="showPopover"
          @mouseleave="hidePopover"
          @click="showPopover"
        >
          {{ formattedSum }}
        </span>
        <i
          v-if="column.multi_currency_warning"
          class="pi pi-info-circle kanban-col__multi-icon"
          :style="{ color: headerTextColorMuted }"
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
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useThemeStore } from '@/stores/theme'
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
}>()

const emit = defineEmits<{
  drop: [card: DealCardDto, fromStageId: number, toStageId: number]
  titleChange: [cardId: number, title: string]
  loadMore: [stageId: number]
  addDeal: [stageId: number]
}>()

const { t } = useI18n()
const themeStore = useThemeStore()

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

// ── Stage color palette ────────────────────────────────────────────────────────

const BRIGHT_COLORS = new Set([
  '#1D9E75', '#378ADD', '#EF9F27', '#D4537E', '#7F77DD',
])

const SOFT_TEXT_MAP: Record<string, string> = {
  '#E1F5EE': '#0D5C44',
  '#E6F1FB': '#1A4F8A',
  '#FAEEDA': '#6B4A00',
  '#FBEAF0': '#7A2347',
  '#EAF3DE': '#3A6020',
}

// Bright border colour to use in dark mode for each soft colour (same hue family)
const SOFT_DARK_BORDER_MAP: Record<string, string> = {
  '#E1F5EE': '#1D9E75',
  '#E6F1FB': '#378ADD',
  '#FAEEDA': '#EF9F27',
  '#FBEAF0': '#D4537E',
  '#EAF3DE': '#3A6020',
}

const stageColor = computed(() => props.column.stage.color ?? null)

const isBrightColor = computed(() =>
  stageColor.value ? BRIGHT_COLORS.has(stageColor.value.toUpperCase()) || BRIGHT_COLORS.has(stageColor.value) : false,
)

const isSoftColor = computed(() =>
  stageColor.value ? Object.keys(SOFT_TEXT_MAP).includes(stageColor.value) : false,
)

const isDark = computed(() => themeStore.theme === 'dark')

const headerStyle = computed(() => {
  const color = stageColor.value
  if (!color) return {}
  if (isDark.value && isSoftColor.value) {
    // Soft colours in dark mode: neutral surface bg + prominent left border of the bright sibling
    const borderColor = SOFT_DARK_BORDER_MAP[color] ?? color
    return {
      backgroundColor: 'var(--p-surface-700)',
      borderLeft: `4px solid ${borderColor}`,
    }
  }
  // Bright colours (both themes) and soft colours in light mode: full background fill
  return { backgroundColor: color }
})

const headerTextColor = computed(() => {
  const color = stageColor.value
  if (!color) return undefined
  if (isBrightColor.value) {
    // Exception: amber gets dark text
    if (color === '#EF9F27') return '#6B4A00'
    return '#ffffff'
  }
  if (isSoftColor.value) {
    // In dark mode soft colours use a neutral surface bg — text should be the default
    // surface-100 colour (let SCSS handle it via .kanban-col__name dark override)
    if (isDark.value) return undefined
    return SOFT_TEXT_MAP[color] ?? undefined
  }
  return undefined
})

const headerTextColorMuted = computed(() => {
  const color = headerTextColor.value
  if (!color) return undefined
  return color + 'b3' // ~70% opacity via hex
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

// When fx rate is unavailable, show native amounts without the ≈ approximation prefix
const formattedSum = computed(() => {
  if (!props.column.fx_rate_available) {
    // No conversion available — show amounts_by_currency inline if multi-currency,
    // or the plain native amount without ≈
    const keys = Object.keys(props.column.amounts_by_currency)
    if (keys.length === 1 && keys[0]) {
      const cur = keys[0]
      const amount = props.column.amounts_by_currency[cur]
      if (amount !== undefined) {
        return formatNativeCurrency(amount, cur)
      }
    }
    if (keys.length > 1) {
      // Multiple currencies, rate unavailable: show first + ellipsis
      const firstKey = keys[0]
      if (firstKey) {
        const amount = props.column.amounts_by_currency[firstKey]
        if (amount !== undefined) {
          return `${formatNativeCurrency(amount, firstKey)} …`
        }
      }
    }
    // Fall back to base amount without ≈
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
    background: var(--p-surface-900);
  }
}

// ─── Header ───────────────────────────────────────────────────────────────────

.kanban-col__header {
  padding: $space-3 $space-3 $space-2;
  border-bottom: 1px solid rgba(0, 0, 0, 0.08);
  flex-shrink: 0;
  background: $surface-50;

  :global(.app-dark) & {
    background: var(--p-surface-800);
    border-bottom-color: var(--p-surface-700);
  }
}

.kanban-col__title-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-bottom: $space-1;
}

.kanban-col__name {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  flex: 1;

  :global(.app-dark) & {
    color: var(--p-surface-100);
  }
}

.kanban-col__count {
  font-size: $font-size-xs;
  color: $surface-500;
  font-weight: $font-weight-medium;
  flex-shrink: 0;

  :global(.app-dark) & {
    color: var(--p-surface-300);
  }
}

.kanban-col__add-btn {
  flex-shrink: 0;
  opacity: 0.7;

  &:hover {
    opacity: 1;
  }
}

.kanban-col__sum-row {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.kanban-col__sum {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $primary-color;
  cursor: pointer;

  &:hover {
    text-decoration: underline;
  }

  :global(.app-dark) & {
    color: var(--p-primary-color);
  }
}

.kanban-col__multi-icon {
  font-size: 11px;
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

  :global(.app-dark) & {
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

  :global(.app-dark) & {
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

  :global(.app-dark) & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
