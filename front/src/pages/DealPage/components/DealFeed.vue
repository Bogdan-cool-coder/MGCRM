<template>
  <div class="deal-feed">
    <!-- ─── Top bar: search icon ───────────────────────────────────────────────── -->
    <div class="deal-feed__topbar">
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

    <!-- ─── Feed content (scrolls bottom-up) ─────────────────────────────────── -->
    <div ref="scrollEl" class="deal-feed__content">
      <!-- Inner wrapper reversed so oldest is at top, newest near composer -->
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
            <!-- Date header -->
            <button
              type="button"
              class="deal-feed__date-header"
              @click="feed.toggleGroup(group.date)"
            >
              <span class="deal-feed__date-line" />
              <span class="deal-feed__date-label">{{ formatGroupDate(group.date) }}</span>
              <span class="deal-feed__date-line" />
              <i
                class="pi deal-feed__date-toggle"
                :class="group.collapsed ? 'pi-chevron-down' : 'pi-chevron-up'"
              />
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
import { ref, nextTick, watch } from 'vue'
import type { KeyActionType } from '@/entities/sales'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import DealFeedItem from './DealFeedItem.vue'
import FeedSearchOverlay from './FeedSearchOverlay.vue'
import type { useDealFeed, FeedItem } from '../composables/useDealFeed'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  dealId: number
  feed: ReturnType<typeof useDealFeed>
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

// ─── Auto-scroll to bottom whenever groups change (new events) ────────────────

function scrollToBottom() {
  nextTick(() => {
    const el = scrollEl.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

watch(
  () => props.feed.groups.value,
  () => scrollToBottom(),
  { deep: false },
)

/**
 * Scroll the feed to the most-recent item that matches the given key-action type.
 *
 * Mapping:
 *   last_presentation → activity kind === 'presentation' (completed)
 *   max_stage         → first stage_change item (highest sort_order is best effort)
 *   kp_sent / contract_sent → first field_change or stage_change (fallback: bottom)
 *   last_touch        → activity kind in ['call', 'follow_up'] (completed)
 *   last_event        → activity kind in ['call', 'follow_up', 'meeting', 'presentation'] (completed)
 *
 * Strategy: walk all groups newest-first and find the first matching item.
 * Then scroll to it via data-feed-id attribute and flash the highlight class.
 */
function scrollToFeedItem(actionType: KeyActionType) {
  const el = scrollEl.value
  if (!el) return

  // Sort groups newest-first for look-up
  const allGroups = [...props.feed.groups.value].reverse()

  // Predicate for each action type
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

  // Highlight + scroll
  if (targetId) {
    highlightedItemId.value = null
    nextTick(() => {
      const target = el.querySelector(`[data-feed-id="${targetId}"]`) as HTMLElement | null
      if (target) {
        target.scrollIntoView({ behavior: 'smooth', block: 'center' })
        highlightedItemId.value = targetId
        // Clear highlight after animation (1.6s + 200ms buffer)
        setTimeout(() => {
          if (highlightedItemId.value === targetId) highlightedItemId.value = null
        }, 1800)
      } else {
        // Item not found (maybe in collapsed group or not loaded) — scroll to bottom
        el.scrollTop = el.scrollHeight
      }
    })
  } else {
    // No matching item — scroll to bottom where newest events are
    el.scrollTop = el.scrollHeight
  }
}

// Expose for parent to trigger (e.g. after initial load)
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

// ─── Top bar ────────────────────────────────────────────────────────────────

.deal-feed__topbar {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-card-background);
  flex-shrink: 0;
  position: relative;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
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
}

// Inner wrapper: fills from top, items flow naturally top-down
// Auto-scroll keeps newest at bottom near composer
.deal-feed__inner {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-4;
  min-height: 100%;
  justify-content: flex-end; // push content to bottom when list is short
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
  font-size: 3rem;
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

.deal-feed__date-toggle {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
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
