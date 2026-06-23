<template>
  <div class="deal-feed">
    <!-- ─── Top bar: key-action chips (LEFT) + pi-search (RIGHT) ────────────── -->
    <div class="deal-feed__topbar">
      <!-- Key action pill chips — spec §7.1 (scroll-to only, do NOT create events) -->
      <div v-if="visibleChips.length > 0" class="deal-feed__topbar-chips">
        <button
          v-for="chip in visibleChips"
          :key="chip.type"
          type="button"
          v-tooltip.bottom="chip.tooltip"
          class="deal-feed__topbar-chip"
          @click="scrollToFeedItem(chip.type)"
        >
          <i :class="['pi', chip.icon]" class="deal-feed__topbar-chip-icon" />
          <span class="deal-feed__topbar-chip-label">{{ chip.label }}</span>
        </button>
      </div>
      <span class="deal-feed__topbar-spacer" />
      <Button
        icon="pi pi-search"
        severity="secondary"
        text
        size="small"
        v-tooltip.left="t('sales.deal.feed.searchTooltip')"
        @click="searchOverlayOpen = !searchOverlayOpen"
      />
      <FeedSearchOverlay
        :open="searchOverlayOpen"
        @search="onSearch"
        @filter="onFilter"
        @reset="onReset"
      />
    </div>

    <!-- ─── Feed content (scrolls bottom-up via margin-top:auto) ────────────── -->
    <div ref="scrollEl" class="deal-feed__content">
      <!-- Inner wrapper: margin-top:auto pushes content to bottom spec §11 -->
      <div class="deal-feed__inner">
        <!-- Loading skeleton -->
        <div v-if="feed.loading.value && feed.groups.value.length === 0" class="deal-feed__skeleton">
          <Skeleton height="60px" class="mb-2" />
          <Skeleton height="80px" class="mb-2" />
          <Skeleton height="60px" />
        </div>

        <!-- Empty state -->
        <div
          v-else-if="!feed.loading.value && feed.groups.value.length === 0"
          class="deal-feed__empty"
        >
          <i class="pi pi-clock deal-feed__empty-icon" />
          <p class="deal-feed__empty-title">{{ t('sales.deal.feed.empty.title') }}</p>
          <p class="deal-feed__empty-hint">{{ t('sales.deal.feed.empty.subtitle') }}</p>
          <div class="deal-feed__empty-cta">
            <Button
              icon="pi pi-check-square"
              :label="t('sales.deal.composer.task')"
              severity="secondary"
              outlined
              size="small"
              @click="emit('openComposerTab', 'task')"
            />
          </div>
        </div>

        <!-- Chronological feed (groups sorted oldest→newest, renders top→bottom) -->
        <template v-else>
          <!-- Load more (at top when bottom-up) -->
          <div v-if="feed.hasMore.value" class="deal-feed__load-more">
            <Button
              icon="pi pi-refresh"
              :label="t('sales.deal.feed.loadMore')"
              severity="secondary"
              outlined
              size="small"
              :loading="feed.loading.value"
              @click="feed.loadMore()"
            />
          </div>

          <div
            v-for="group in feed.groups.value"
            :key="group.date"
            class="deal-feed__group"
          >
            <!-- Date header — click collapses/expands; NO chevron icon per spec §7.2 -->
            <button
              type="button"
              class="deal-feed__date-header"
              @click="feed.toggleGroup(group.date)"
            >
              <span class="deal-feed__date-line" />
              <span class="deal-feed__date-label">{{ formatGroupDate(group.date) }}</span>
              <span class="deal-feed__date-line" />
            </button>

            <!-- Items -->
            <div v-if="!group.collapsed" class="deal-feed__list">
              <DealFeedItem
                v-for="item in group.items"
                :key="item.id"
                :item="item"
                :deal-id="dealId"
                :completing-id="completingId"
                :reopening-id="reopeningId"
                :is-highlighted="highlightedItemId === item.id"
                @reopen="onReopen"
                @remove="onRemove"
                @updated="onUpdated"
                @pin="onPin"
              />
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import type { KeyActionType, DealKeyAction } from '@/entities/sales'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import { Tooltip } from 'primevue'
import DealFeedItem from './DealFeedItem.vue'
import FeedSearchOverlay from './FeedSearchOverlay.vue'
import type { useDealFeed, FeedItem } from '../composables/useDealFeed'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

const vTooltip = Tooltip

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  dealId: number
  feed: ReturnType<typeof useDealFeed>
  /** Key actions from deal DTO for topbar chips */
  keyActions?: DealKeyAction[]
}>()

const emit = defineEmits<{
  openComposerTab: [tab: ActivityKind]
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()

const completingId = ref<number | null>(null)
const reopeningId = ref<number | null>(null)
const searchOverlayOpen = ref(false)
const scrollEl = ref<HTMLElement | null>(null)
const highlightedItemId = ref<string | null>(null)

// ─── Topbar key-action chips (spec §7.1) ─────────────────────────────────────
// Chips scroll-to feed item with 2s highlight; they do NOT create events.

interface ChipConfig {
  type: KeyActionType
  icon: string
  label: string
  tooltip: string
}

function formatChipDate(iso: string): string {
  const d = new Date(iso)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit' })
}

const visibleChips = computed((): ChipConfig[] => {
  const actions = props.keyActions ?? []
  const chips: ChipConfig[] = []

  for (const action of actions) {
    if (!action.date) continue

    const dateStr = formatChipDate(action.date)

    switch (action.type) {
      case 'last_presentation':
        chips.push({
          type: action.type,
          icon: 'pi-desktop',
          label: `${t('sales.deal.keyActions.presentation')}: ${dateStr}`,
          tooltip: t('sales.deal.keyActions.lastPresentationTooltip', { date: dateStr }),
        })
        break
      case 'kp_sent':
        chips.push({
          type: action.type,
          icon: 'pi-file-check',
          label: `${t('sales.deal.keyActions.kp')}: ${dateStr}`,
          tooltip: t('sales.deal.keyActions.kpSentTooltip', { date: dateStr }),
        })
        break
      case 'contract_sent':
        chips.push({
          type: action.type,
          icon: 'pi-file-edit',
          label: `${t('sales.deal.keyActions.contract')}: ${dateStr}`,
          tooltip: t('sales.deal.keyActions.contractSentTooltip', { date: dateStr }),
        })
        break
      case 'last_touch':
        chips.push({
          type: action.type,
          icon: 'pi-phone',
          label: `${t('sales.deal.keyActions.touch')}: ${dateStr}`,
          tooltip: t('sales.deal.keyActions.lastTouchTooltip', { date: dateStr }),
        })
        break
      case 'last_event':
        chips.push({
          type: action.type,
          icon: 'pi-calendar',
          label: `${t('sales.deal.keyActions.event')}: ${dateStr}`,
          tooltip: t('sales.deal.keyActions.lastEventTooltip', { date: dateStr }),
        })
        break
      default:
        break
    }
  }

  return chips
})

// ─── Scroll to bottom (called after new activity created) ────────────────────

function scrollToBottom() {
  nextTick(() => {
    const el = scrollEl.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

/**
 * Scroll the feed to the most-recent item that matches the given key-action type.
 * Highlights with border+ring for ~2s per spec §7.1.
 */
function scrollToFeedItem(actionType: KeyActionType) {
  const el = scrollEl.value
  if (!el) return

  // Sort groups newest-first for look-up
  const allGroups = [...props.feed.groups.value].reverse()

  type FeedItemPredicate = (item: FeedItem) => boolean
  const predicates: Record<KeyActionType, FeedItemPredicate> = {
    last_presentation: (item) =>
      item.activity?.kind === 'presentation'
        && item.activity?.status === 'done',
    max_stage: (item) => item.type === 'stage_change',
    kp_sent: (item) => item.type === 'field_change',
    contract_sent: (item) => item.type === 'field_change',
    last_touch: (item) =>
      (item.activity?.kind === 'call' || item.activity?.kind === 'follow_up')
        && item.activity?.status === 'done',
    last_event: (item) => {
      const kind = item.activity?.kind
      return (
        (kind === 'call' || kind === 'follow_up' || kind === 'meeting' || kind === 'presentation')
          && item.activity?.status === 'done'
      )
    },
  }

  const predicate = predicates[actionType]
  let targetId: string | null = null

  outerLoop:
  for (const group of allGroups) {
    for (const item of [...group.items].reverse()) {
      if (predicate(item)) {
        targetId = item.id
        break outerLoop
      }
    }
  }

  if (targetId) {
    highlightedItemId.value = null
    nextTick(() => {
      // spec §7.2/§11: scroll target is identified by data-ka="<type>" attribute.
      // querySelectorAll returns DOM order (oldest→newest); take the last match = most recent.
      const allMatches = el.querySelectorAll(`[data-ka="${actionType}"]`)
      const target = allMatches.length > 0
        ? (allMatches[allMatches.length - 1] as HTMLElement)
        : null
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' })
        highlightedItemId.value = targetId
        setTimeout(() => {
          if (highlightedItemId.value === targetId) highlightedItemId.value = null
        }, 2000)
      } else {
        el.scrollTop = el.scrollHeight
      }
    })
  } else {
    el.scrollTop = el.scrollHeight
  }
}

// Expose for parent
defineExpose({ scrollToBottom, scrollToFeedItem })

// ─── Search / filter handlers ─────────────────────────────────────────────────

function onSearch(query: string) {
  props.feed.setSearch(query)
}

function onFilter(type: string) {
  props.feed.setFilterType(type as Parameters<typeof props.feed.setFilterType>[0])
}

function onReset() {
  props.feed.resetFilter()
  searchOverlayOpen.value = false
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatGroupDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

// ─── Activity action handlers ─────────────────────────────────────────────────

async function onReopen(id: number) {
  reopeningId.value = id
  try {
    await props.feed.reopenActivity(id)
    toast.add({ severity: 'success', summary: t('activity.actions.reopen'), life: 2000 })
  } finally {
    reopeningId.value = null
  }
}

async function onRemove(id: number) {
  await props.feed.deleteActivity(id)
  toast.add({ severity: 'success', summary: t('common.deleted'), life: 2000 })
}

function onUpdated(activity: ActivityDto) {
  props.feed.updateActivityLocal(activity)
}

async function onPin(id: number, isPinned: boolean) {
  await props.feed.pinActivity(id, isPinned)
}
</script>

<style lang="scss" scoped>
.deal-feed {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

// ─── Top bar (spec §7.1) ─────────────────────────────────────────────────────

.deal-feed__topbar {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-card-background);
  flex-shrink: 0;
  position: relative;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

// Key-action chips (neutral, scroll-to only)
.deal-feed__topbar-chips {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
  overflow: hidden;
}

.deal-feed__topbar-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 3px 8px;
  border-radius: $radius-pill;
  background: var(--p-surface-100);
  border: 1px solid var(--p-surface-200);
  cursor: pointer;
  transition: background 0.12s, border-color 0.12s;
  color: $surface-600;
  white-space: nowrap;
  font-size: $font-size-xs;

  .app-dark & {
    background: var(--p-surface-700);
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);
  }

  &:hover {
    background: var(--p-surface-200);
    border-color: var(--p-surface-300);

    .app-dark & {
      background: var(--p-surface-600);
    }
  }
}

.deal-feed__topbar-chip-icon {
  font-size: $font-size-3xs;
  color: $surface-400;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.deal-feed__topbar-chip-label {
  font-weight: $font-weight-medium;
  line-height: 1;
}

.deal-feed__topbar-spacer {
  flex: 1;
}

// ─── Content (scrollable, items flow top→bottom oldest→newest) ───────────────

.deal-feed__content {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;

  // Hidden scrollbar — spec §0
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

// Inner wrapper: margin-top:auto pushes content to bottom spec §11
// (NOT justify-content:flex-end — that breaks margin-top:auto behavior)
.deal-feed__inner {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-4;
  min-height: 100%;
  margin-top: auto; // spec §11: снизу вверх
}

// ─── Loading skeleton ────────────────────────────────────────────────────────

.deal-feed__skeleton {
  display: flex;
  flex-direction: column;
}

// ─── Empty state ─────────────────────────────────────────────────────────────

.deal-feed__empty {
  flex: 1;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
  min-height: 200px;
}

.deal-feed__empty-icon {
  font-size: $font-size-icon-2xl;
  color: $surface-300;
}

.deal-feed__empty-title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.deal-feed__empty-hint {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}

.deal-feed__empty-cta {
  display: flex;
  gap: $space-2;
  flex-wrap: wrap;
  justify-content: center;
}

// ─── Groups ──────────────────────────────────────────────────────────────────

.deal-feed__group {
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

// Date divider — NO chevron (spec §7.2: «без иконки»)
.deal-feed__date-header {
  display: flex;
  align-items: center;
  gap: $space-2;
  background: none;
  border: none;
  padding: $space-1 0;
  cursor: pointer;
  width: 100%;
}

.deal-feed__date-line {
  flex: 1;
  height: 1px;
  background: var(--p-surface-200);

  .app-dark & {
    background: var(--p-surface-700);
  }
}

.deal-feed__date-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $surface-400;
  white-space: nowrap;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

// ─── List (vertical line) ────────────────────────────────────────────────────

.deal-feed__list {
  display: flex;
  flex-direction: column;
  position: relative;
  padding-left: $space-4;

  &::before {
    content: '';
    position: absolute;
    left: 13px;
    top: 8px;
    bottom: 8px;
    width: 2px;
    background: var(--p-surface-200);

    .app-dark & {
      background: var(--p-surface-700);
    }
  }
}

// ─── Load more (at top, since scroll is bottom-up) ───────────────────────────

.deal-feed__load-more {
  display: flex;
  justify-content: center;
  padding: $space-2 0;
}
</style>
