<template>
  <div class="deal-feed">
    <!-- ─── Top bar: just a search icon ──────────────────────────────────────── -->
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

    <!-- ─── Feed content ────────────────────────────────────────────────────── -->
    <div class="deal-feed__content">
      <!-- Loading skeleton -->
      <div v-if="feed.loading.value && feed.groups.value.length === 0" class="deal-feed__skeleton">
        <Skeleton height="80px" class="mb-2" />
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

      <!-- Chronological feed -->
      <template v-else>
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
              @complete="onComplete"
              @reopen="onReopen"
              @remove="onRemove"
              @updated="onUpdated"
              @pin="onPin"
            />
          </div>
        </div>

        <!-- Load more -->
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
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import DealFeedItem from './DealFeedItem.vue'
import FeedSearchOverlay from './FeedSearchOverlay.vue'
import type { useDealFeed } from '../composables/useDealFeed'
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

async function onComplete(id: number) {
  completingId.value = id
  try {
    await props.feed.completeActivity(id)
    toast.add({ severity: 'success', summary: t('activity.actions.complete'), life: 2000 })
  } finally {
    completingId.value = null
  }
}

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

// ─── Content ────────────────────────────────────────────────────────────────

.deal-feed__content {
  flex: 1;
  overflow-y: auto;
  padding: $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-2;
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

// ─── Load more ───────────────────────────────────────────────────────────────

.deal-feed__load-more {
  display: flex;
  justify-content: center;
  padding: $space-2 0 $space-4;
}
</style>
