<template>
  <div class="deal-feed">
    <!-- ─── Sticky toolbar ───────────────────────────────────────────────────── -->
    <div class="deal-feed__toolbar">
      <div class="deal-feed__toolbar-left">
        <InputText
          v-model="localSearch"
          :placeholder="t('sales.deal.feed.searchPlaceholder')"
          size="small"
          class="deal-feed__search"
          @input="onSearchInput"
        />
        <Select
          v-model="localType"
          :options="feedTypeOptions"
          option-label="label"
          option-value="value"
          :placeholder="t('sales.deal.feed.filterType')"
          show-clear
          size="small"
          class="deal-feed__type-select"
          @change="onTypeChange"
        />
        <Button
          icon="pi pi-times"
          :label="t('sales.deal.feed.reset')"
          severity="secondary"
          text
          size="small"
          @click="onReset"
        />
      </div>
      <Button
        ref="viewBtnRef"
        icon="pi pi-ellipsis-v"
        severity="secondary"
        text
        size="small"
        @click="viewMenu?.toggle($event)"
      />
      <Menu
        ref="viewMenu"
        :model="viewMenuItems"
        popup
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
          <Button
            icon="pi pi-phone"
            :label="t('sales.deal.composer.call')"
            severity="secondary"
            outlined
            size="small"
            @click="emit('openComposerTab', 'call')"
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
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Select from 'primevue/select'
import Menu from 'primevue/menu'
import Skeleton from 'primevue/skeleton'
import DealFeedItem from './DealFeedItem.vue'
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
const router = useRouter()
const toast = useToast()

const viewMenu = ref<InstanceType<typeof Menu> | null>(null)
const completingId = ref<number | null>(null)
const reopeningId = ref<number | null>(null)

// Local search/filter state (mirrors composable but avoids prop-mutation lint error)
const localSearch = ref('')
const localType = ref<string>('')

function onSearchInput() {
  props.feed.setSearch(localSearch.value)
}

function onTypeChange() {
  props.feed.setFilterType(localType.value as Parameters<typeof props.feed.setFilterType>[0])
}

function onReset() {
  localSearch.value = ''
  localType.value = ''
  props.feed.resetFilter()
}

// ─── Type filter options ──────────────────────────────────────────────────────

const feedTypeOptions = computed(() => [
  { value: 'stage_change', label: t('sales.deal.feed.types.stage_change') },
  { value: 'field_change', label: t('sales.deal.feed.types.field_change') },
  { value: 'note', label: t('sales.deal.feed.types.note') },
  { value: 'task', label: t('sales.deal.feed.types.task') },
  { value: 'call', label: t('sales.deal.feed.types.call') },
  { value: 'meeting', label: t('sales.deal.feed.types.meeting') },
])

// ─── View menu items ──────────────────────────────────────────────────────────

const viewMenuItems = computed(() => [
  {
    label: t('sales.deal.feed.viewMenu.collapseAll'),
    icon: 'pi pi-arrows-v',
    command: () => props.feed.collapseAll(),
  },
  {
    label: t('sales.deal.feed.viewMenu.expandAll'),
    icon: 'pi pi-arrows-v',
    command: () => props.feed.expandAll(),
  },
  { separator: true },
  {
    label: t('sales.deal.feed.viewMenu.customizeFields'),
    icon: 'pi pi-cog',
    command: () => void router.push('/admin/custom-fields?scope=deal'),
  },
  {
    label: t('sales.deal.feed.viewMenu.copyLink'),
    icon: 'pi pi-link',
    command: () => copyLink(),
  },
  {
    label: t('sales.deal.feed.viewMenu.print'),
    icon: 'pi pi-print',
    disabled: true,
  },
])

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatGroupDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

function copyLink() {
  void navigator.clipboard.writeText(window.location.href)
  toast.add({
    severity: 'success',
    summary: t('sales.deal.feed.viewMenu.copyLink'),
    life: 2000,
  })
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

// ─── Toolbar ────────────────────────────────────────────────────────────────

.deal-feed__toolbar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
  padding: $space-3 $space-4;
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-card-background);
  flex-shrink: 0;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.deal-feed__toolbar-left {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex: 1;
  min-width: 0;
  flex-wrap: wrap;
}

.deal-feed__search {
  width: 160px;
  flex-shrink: 0;
}

.deal-feed__type-select {
  width: 140px;
  flex-shrink: 0;
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
