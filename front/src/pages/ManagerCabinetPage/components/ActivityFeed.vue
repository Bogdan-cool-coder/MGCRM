<template>
  <!--
    Activity feed v2 (ManagerCabinet-v2 §2.11): pill type-filters + «Первичная»
    badge + resolved target name + numeric pagination. Replaces the old
    DataTable + ToggleButton + Paginator.
  -->
  <section class="activity-feed">
    <!-- Header: title + pill filters -->
    <header class="activity-feed__head">
      <h2 class="activity-feed__title">{{ t('managerCabinet.feed.title') }}</h2>
      <div class="activity-feed__pills">
        <button
          v-for="k in kindOptions"
          :key="k.value"
          type="button"
          class="activity-feed__pill"
          :class="{ 'activity-feed__pill--active': feedKind === k.value }"
          @click="emit('update:feedKind', k.value)"
        >
          <i v-if="k.icon" :class="['pi', k.icon, 'activity-feed__pill-icon']" aria-hidden="true" />
          {{ t(k.labelKey) }}
        </button>
      </div>
    </header>

    <!-- Loading -->
    <div v-if="feedLoading && feed.length === 0" class="activity-feed__rows">
      <div v-for="n in 6" :key="n" class="activity-feed__skeleton-row">
        <Skeleton shape="circle" size="16px" />
        <Skeleton width="60%" height="14px" />
        <Skeleton width="72px" height="12px" class="ms-auto" />
      </div>
    </div>

    <!-- Empty -->
    <div v-else-if="feed.length === 0" class="activity-feed__empty">
      <i class="pi pi-inbox activity-feed__empty-icon" aria-hidden="true" />
      <span class="activity-feed__empty-text">{{ t('managerCabinet.feed.noActivity') }}</span>
      <button
        v-if="feedKind !== 'all'"
        type="button"
        class="activity-feed__reset"
        @click="emit('reset-filters')"
      >
        {{ t('managerCabinet.feed.resetFilters') }}
      </button>
    </div>

    <!-- Rows -->
    <template v-else>
      <div class="activity-feed__rows">
        <div v-for="row in feed" :key="row.id" class="activity-feed__row">
          <i
            :class="['pi', kindIcon(row.kind), 'activity-feed__row-icon']"
            :style="{ color: kindColor(row.kind) }"
            aria-hidden="true"
          />
          <span class="activity-feed__row-title">{{ row.title }}</span>
          <span
            v-if="isFirstTime(row)"
            class="activity-feed__first-time"
          >{{ t('managerCabinet.feed.firstTime') }}</span>
          <router-link
            v-if="targetRoute(row)"
            :to="targetRoute(row)!"
            class="activity-feed__target activity-feed__target--link"
            :title="targetLabel(row)"
          >{{ targetLabel(row) }}</router-link>
          <span
            v-else-if="row.target_type && row.target_id != null"
            class="activity-feed__target"
            :title="targetLabel(row)"
          >{{ targetLabel(row) }}</span>
          <span v-else class="activity-feed__target activity-feed__target--none">&mdash;</span>
          <span class="activity-feed__date">{{ formatDate(row.due_at ?? row.created_at) }}</span>
        </div>
      </div>

      <!-- Numeric pagination -->
      <footer class="activity-feed__pager">
        <span class="activity-feed__count">
          {{ t('managerCabinet.feed.showing', { shown: feed.length, total: totalRecords }) }}
        </span>
        <span class="activity-feed__pages">
          <button
            type="button"
            class="activity-feed__page-btn activity-feed__page-btn--arrow"
            :disabled="currentPage <= 1"
            :aria-label="t('managerCabinet.feed.prevPage')"
            @click="goto(currentPage - 1)"
          >
            <i class="pi pi-chevron-left" aria-hidden="true" />
          </button>
          <button
            v-for="p in pageNumbers"
            :key="p"
            type="button"
            class="activity-feed__page-btn"
            :class="{ 'activity-feed__page-btn--active': p === currentPage }"
            @click="goto(p)"
          >{{ p }}</button>
          <button
            type="button"
            class="activity-feed__page-btn activity-feed__page-btn--arrow"
            :disabled="currentPage >= lastPage"
            :aria-label="t('managerCabinet.feed.nextPage')"
            @click="goto(currentPage + 1)"
          >
            <i class="pi pi-chevron-right" aria-hidden="true" />
          </button>
        </span>
      </footer>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { RouteLocationRaw } from 'vue-router'
import Skeleton from 'primevue/skeleton'
import type { ActivityFeedItem, ActivityFeedMeta } from '@/entities/managerCabinet'

type FeedKind = 'all' | 'call' | 'meeting' | 'task' | 'note'

// Show at most the first 5 page numbers (mockup — no ellipsis, ОВ-4).
const MAX_PAGE_BUTTONS = 5

const props = defineProps<{
  feed: ActivityFeedItem[]
  feedLoading: boolean
  feedMeta: ActivityFeedMeta | null
  feedKind: FeedKind
}>()

const emit = defineEmits<{
  'update:feedKind': [FeedKind]
  'update:feedPage': [number]
  'reset-filters': []
}>()

const { t, locale } = useI18n()

const kindOptions: { value: FeedKind; labelKey: string; icon: string | null }[] = [
  { value: 'all', labelKey: 'managerCabinet.feed.filterAll', icon: null },
  { value: 'call', labelKey: 'managerCabinet.feed.filterCall', icon: 'pi-phone' },
  { value: 'meeting', labelKey: 'managerCabinet.feed.filterMeeting', icon: 'pi-users' },
  { value: 'task', labelKey: 'managerCabinet.feed.filterTask', icon: 'pi-check-square' },
  { value: 'note', labelKey: 'managerCabinet.feed.filterNote', icon: 'pi-pencil' },
]

const kindIcon = (kind: ActivityFeedItem['kind']): string => {
  const map: Record<ActivityFeedItem['kind'], string> = {
    call: 'pi-phone',
    meeting: 'pi-users',
    task: 'pi-check-square',
    note: 'pi-pencil',
  }
  return map[kind] ?? 'pi-circle'
}

const kindColor = (kind: ActivityFeedItem['kind']): string => {
  const map: Record<ActivityFeedItem['kind'], string> = {
    call: 'var(--p-blue-500)',
    meeting: 'var(--p-primary-color)',
    task: 'var(--p-orange-500)',
    note: 'var(--p-surface-500)',
  }
  return map[kind] ?? ''
}

const isFirstTime = (row: ActivityFeedItem): boolean =>
  row.is_first_time_meeting || row.ftm_counted

const targetLabel = (row: ActivityFeedItem): string => {
  // Prefer the resolved object name (ГЭП-1); fall back to «{label} #{id}».
  if (row.target_name) return row.target_name
  if (!row.target_type || row.target_id == null) return '—'
  const map: Record<string, string> = {
    deal: t('managerCabinet.feed.targetDeal'),
    contact: t('managerCabinet.feed.targetContact'),
    company: t('managerCabinet.feed.targetCompany'),
  }
  const label = map[row.target_type] ?? row.target_type
  return `${label} #${row.target_id}`
}

const targetRoute = (row: ActivityFeedItem): RouteLocationRaw | null => {
  if (!row.target_type || row.target_id == null) return null
  const map: Record<string, string> = {
    deal: 'DealDetail',
    contact: 'ContactDetail',
    company: 'CompanyDetail',
  }
  const name = map[row.target_type]
  if (!name) return null
  return { name, params: { id: row.target_id } }
}

const formatDate = (dateStr: string | null): string => {
  if (!dateStr) return '—'
  try {
    const d = new Date(dateStr)
    return d.toLocaleString(locale.value, {
      day: 'numeric',
      month: 'short',
      hour: '2-digit',
      minute: '2-digit',
    })
  } catch {
    return dateStr
  }
}

// ─── Pagination ──────────────────────────────────────────────────────────────
const currentPage = computed<number>(() => props.feedMeta?.current_page ?? 1)
const lastPage = computed<number>(() => props.feedMeta?.last_page ?? 1)
const totalRecords = computed<number>(() => props.feedMeta?.total ?? props.feed.length)

const pageNumbers = computed<number[]>(() =>
  Array.from({ length: Math.min(lastPage.value, MAX_PAGE_BUTTONS) }, (_, i) => i + 1),
)

const goto = (page: number): void => {
  if (page < 1 || page > lastPage.value || page === currentPage.value) return
  emit('update:feedPage', page)
}
</script>

<style lang="scss" scoped>
.activity-feed {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  padding: $space-3 $space-5;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

.activity-feed__head {
  display: flex;
  align-items: center;
  gap: $space-3;
  flex-wrap: wrap;
  margin-bottom: $space-3;
}

.activity-feed__title {
  margin: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-900;
}

.activity-feed__pills {
  display: inline-flex;
  gap: 2px;
  flex-wrap: wrap;
}

.activity-feed__pill {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  height: 28px;
  padding: 0 $space-3;
  border: none;
  border-radius: $radius-pill;
  background: transparent;
  color: $surface-600;
  font-family: $font-family-sans;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-100);
  }

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.activity-feed__pill--active {
  background: var(--p-primary-100);
  color: var(--p-primary-color);

  &:hover {
    background: var(--p-primary-100);
  }

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-100);
  }
}

.activity-feed__pill-icon {
  font-size: $font-size-2xs;
}

// ── Rows ─────────────────────────────────────────────────────────────────────
.activity-feed__rows {
  display: flex;
  flex-direction: column;
}

.activity-feed__row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 0;
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.activity-feed__row-icon {
  width: 16px;
  flex-shrink: 0;
  font-size: $font-size-sm;
}

.activity-feed__row-title {
  flex: 1;
  min-width: 0;
  font-size: $font-size-sm;
  color: $surface-900;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.activity-feed__first-time {
  flex-shrink: 0;
  font-size: $font-size-3xs;
  font-weight: $font-weight-bold;
  color: $status-success-text;
  background: $status-success-bg;
  border-radius: $radius-xs;
  padding: 1px $space-2;
}

.activity-feed__target {
  flex-shrink: 0;
  max-width: 220px;
  font-size: $font-size-xs;
  color: $surface-600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.activity-feed__target--link {
  color: var(--p-primary-color);
  text-decoration: none;

  &:hover {
    text-decoration: underline;
  }

  .app-dark & {
    color: var(--p-primary-color);
  }
}

.activity-feed__target--none {
  color: $surface-400;
}

.activity-feed__date {
  flex-shrink: 0;
  // min-width (not fixed): a wider locale date («Jul 5, 02:23 PM») grows the box
  // instead of spilling into the card padding (audit L1). Row title/target flex.
  min-width: 96px;
  text-align: right;
  font-size: $font-size-xs;
  color: $surface-600;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

// ── Skeleton rows ────────────────────────────────────────────────────────────
.activity-feed__skeleton-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 0;
  border-top: 1px solid $surface-200;

  &:first-child {
    border-top: none;
  }

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

// ── Empty ────────────────────────────────────────────────────────────────────
.activity-feed__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
}

.activity-feed__empty-icon {
  font-size: $font-size-icon-lg;
  color: $surface-400;
}

.activity-feed__empty-text {
  font-size: $font-size-sm;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.activity-feed__reset {
  border: none;
  background: transparent;
  color: var(--p-primary-color);
  font-family: $font-family-sans;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  cursor: pointer;
}

// ── Pager ────────────────────────────────────────────────────────────────────
.activity-feed__pager {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-3;
  margin-top: $space-3;
  flex-wrap: wrap;
}

.activity-feed__count {
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }
}

.activity-feed__pages {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
}

.activity-feed__page-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  width: 30px;
  height: 30px;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-md;
  background: transparent;
  color: $surface-700;
  font-family: $font-family-sans;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  cursor: pointer;
  transition: background var(--app-transition-fast);

  &:hover:not(:disabled):not(.activity-feed__page-btn--active) {
    background: var(--p-surface-100);
  }

  &:disabled {
    opacity: 0.4;
    cursor: default;
  }

  .app-dark & {
    color: var(--p-surface-700);
  }
}

.activity-feed__page-btn--arrow {
  font-size: $font-size-xs;
}

.activity-feed__page-btn--active {
  background: var(--p-primary-color);
  border-color: var(--p-primary-color);
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  color: #fff; // brand invariant: white number on the navy accent chip
  cursor: default;

  .app-dark & {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: #fff;
  }
}

// ── Mobile: date drops under the title ───────────────────────────────────────
@media (max-width: 575px) {
  .activity-feed__row {
    flex-wrap: wrap;
  }

  .activity-feed__date {
    width: auto;
    flex-basis: 100%;
    text-align: left;
    padding-left: calc(16px + $space-3);
  }
}
</style>
