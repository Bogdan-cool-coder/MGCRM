<template>
  <div class="entity-activities">
    <!--
      Layout (top → bottom):
        [Filter chips — Все / События / Изменения]
        [Feed — scrollable, bottom-up: oldest at top, newest near composer]
        [OpenTasksList — compact rows for pending tasks]
        [EntityComposer — note/task creation]
    -->

    <!-- Filter chips -->
    <div class="entity-activities__filter-chips">
      <button
        type="button"
        class="entity-activities__chip"
        :class="{ 'entity-activities__chip--active': feedFilter === 'all' }"
        @click="feedFilter = 'all'"
      >
        {{ t('crm.entity.feed.filterAll') }}
      </button>
      <button
        type="button"
        class="entity-activities__chip"
        :class="{ 'entity-activities__chip--active': feedFilter === 'events' }"
        @click="feedFilter = 'events'"
      >
        {{ t('crm.entity.feed.filterEvents') }}
      </button>
      <button
        type="button"
        class="entity-activities__chip"
        :class="{ 'entity-activities__chip--active': feedFilter === 'changes' }"
        @click="feedFilter = 'changes'"
      >
        {{ t('crm.entity.feed.filterChanges') }}
      </button>
    </div>

    <div ref="scrollEl" class="entity-activities__feed-wrap">
      <div class="entity-activities__feed-inner">
        <!-- Loading skeleton -->
        <div v-if="loading && groups.length === 0" class="entity-activities__skeleton">
          <Skeleton height="60px" class="mb-2" />
          <Skeleton height="80px" class="mb-2" />
          <Skeleton height="60px" />
        </div>

        <!-- Empty state -->
        <div v-else-if="!loading && filteredGroups.length === 0" class="entity-activities__empty">
          <i class="pi pi-clock entity-activities__empty-icon" />
          <p class="entity-activities__empty-title">{{ t('sales.deal.feed.empty.title') }}</p>
          <p class="entity-activities__empty-hint">{{ t('sales.deal.feed.empty.subtitle') }}</p>
        </div>

        <!-- Chronological groups (oldest→newest, scrolled to bottom) -->
        <template v-else>
          <!-- Load more (at top for bottom-up layout) -->
          <div v-if="feed.hasMore.value" class="entity-activities__load-more">
            <Button
              icon="pi pi-refresh"
              :label="t('sales.deal.feed.loadMore')"
              severity="secondary"
              outlined
              size="small"
              :loading="loading"
              @click="feed.loadMore()"
            />
          </div>

          <div v-for="group in filteredGroups" :key="group.date" class="entity-activities__group">
            <button
              type="button"
              class="entity-activities__date-header"
              @click="feed.toggleGroup(group.date)"
            >
              <span class="entity-activities__date-line" />
              <span class="entity-activities__date-label">{{ formatGroupDate(group.date) }}</span>
              <span class="entity-activities__date-line" />
              <i
                class="pi entity-activities__date-toggle"
                :class="group.collapsed ? 'pi-chevron-down' : 'pi-chevron-up'"
              />
            </button>

            <div v-if="!group.collapsed" class="entity-activities__list">
              <div
                v-for="item in group.items"
                :key="item.id"
                class="entity-activities__item"
              >
                <!-- Activity item (only completed activities appear here) -->
                <div v-if="item.activity" class="entity-activities__activity-card">
                  <div class="entity-activities__activity-row">
                    <i :class="['pi', kindIcon(item.activity.kind), 'entity-activities__kind-icon']" />
                    <span
                      class="entity-activities__activity-title"
                      :class="{ 'entity-activities__activity-title--done': item.activity.is_closed }"
                    >
                      {{ item.activity.title }}
                    </span>
                    <Tag
                      :severity="statusSeverity(item.activity.status)"
                      :value="t(`activity.statuses.${item.activity.status}`)"
                      size="small"
                    />
                  </div>
                  <!-- Note body -->
                  <p
                    v-if="item.activity.kind === 'note' && item.activity.body"
                    class="entity-activities__note-body"
                  >
                    {{ item.activity.body }}
                  </p>
                  <div class="entity-activities__activity-meta">
                    <span v-if="item.activity.due_at" class="entity-activities__due">
                      {{ formatDueDate(item.activity.due_at) }}
                    </span>
                    <span v-if="item.actor" class="entity-activities__actor">
                      {{ item.actor.full_name }}
                    </span>
                  </div>
                </div>

                <!-- Field change item — лог-строка §5 -->
                <div v-else-if="item.fieldChanges" class="entity-activities__field-change">
                  <div class="entity-activities__fc-circle">
                    <i class="pi pi-pencil" />
                  </div>
                  <span class="entity-activities__fc-text">
                    <strong>{{ item.actor?.full_name ?? t('common.system') }}</strong>
                    {{ t('sales.deal.feed.changedField') }}:
                    <span v-for="(fc, idx) in item.fieldChanges" :key="idx" class="entity-activities__fc-field">
                      {{ fc.field }}
                      <template v-if="fc.old_value !== undefined && fc.new_value !== undefined">
                        <span class="entity-activities__fc-arrow">{{ fc.old_value }} → {{ fc.new_value }}</span>
                      </template>
                    </span>
                  </span>
                </div>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- Open tasks (pending, non-closed) — compact list above composer -->
    <OpenTasksList
      :tasks="feed.openTasks.value"
      :target-type="entityType"
      :target-id="entityId"
      @completed="onTaskCompleted"
      @deleted="onTaskDeleted"
    />

    <!-- Composer (note/task creation) -->
    <EntityComposer
      :entity-type="entityType"
      :entity-id="entityId"
      @created="onActivityCreated"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Tag from 'primevue/tag'
import EntityComposer from './EntityComposer.vue'
import OpenTasksList from './OpenTasksList.vue'
import { useEntityFeed } from './composables/useEntityFeed'
import { kindIcon, statusSeverity, formatDueDate } from '@/utils/activity'
import type { ActivityDto } from '@/entities/activity'

export type EntityFeedType = 'company' | 'contact'
type FeedFilter = 'all' | 'events' | 'changes'

const props = defineProps<{
  entityType: EntityFeedType
  entityId: number
}>()

const { t } = useI18n()

const scrollEl = ref<HTMLElement | null>(null)
const feedFilter = ref<FeedFilter>('all')

const feed = useEntityFeed(
  () => props.entityType,
  () => props.entityId,
)

const groups = computed(() => feed.groups.value)
const loading = computed(() => feed.loading.value)

// Client-side filter: events = activity only, changes = fieldChanges only
const filteredGroups = computed(() => {
  if (feedFilter.value === 'all') return groups.value
  return groups.value
    .map((g) => ({
      ...g,
      items: g.items.filter((item) => {
        if (feedFilter.value === 'events') return !!item.activity
        if (feedFilter.value === 'changes') return !!item.fieldChanges
        return true
      }),
    }))
    .filter((g) => g.items.length > 0)
})

// ─── Auto-scroll to bottom ────────────────────────────────────────────────────

function scrollToBottom() {
  nextTick(() => {
    const el = scrollEl.value
    if (el) el.scrollTop = el.scrollHeight
  })
}

watch(
  () => feed.groups.value,
  () => scrollToBottom(),
  { deep: false },
)

// ─── Handlers ─────────────────────────────────────────────────────────────────

function onActivityCreated(activity: ActivityDto) {
  feed.prependLocal(activity)
  scrollToBottom()
}

function onTaskCompleted(activity: ActivityDto) {
  feed.updateActivityLocal(activity)
}

function onTaskDeleted(activityId: number) {
  feed.removeActivityLocal(activityId)
}

function formatGroupDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

onMounted(() => {
  void feed.load()
})
</script>

<style lang="scss" scoped>
.entity-activities {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 400px;
  overflow: hidden;
}

// ─── Filter chips ─────────────────────────────────────────────────────────────

.entity-activities__filter-chips {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-bottom: 1px solid var(--p-surface-200);
  flex-shrink: 0;
  flex-wrap: wrap;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.entity-activities__chip {
  display: inline-flex;
  align-items: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 4px 12px; // spec: 4px 12px — chip padding
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  border-radius: 999px; // pill
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  cursor: pointer;
  border: 1px solid transparent;
  background: transparent;
  color: $surface-600;
  transition: background var(--app-transition-fast), color var(--app-transition-fast);

  &:hover:not(.entity-activities__chip--active) {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.entity-activities__chip--active {
  background: $primary-100;
  color: $primary-900;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-200);
  }
}

// ─── Feed scrollable area ─────────────────────────────────────────────────────

.entity-activities__feed-wrap {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
}

.entity-activities__feed-inner {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-3 $space-4;
  min-height: 100%;
  justify-content: flex-end; // push groups to bottom when short
}

// ─── Skeleton ────────────────────────────────────────────────────────────────

.entity-activities__skeleton {
  display: flex;
  flex-direction: column;
}

// ─── Empty state ──────────────────────────────────────────────────────────────

.entity-activities__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  text-align: center;
}

.entity-activities__empty-icon {
  font-size: $font-size-icon-2xl;
  color: $surface-300;
}

.entity-activities__empty-title {
  font-size: $font-size-base;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
}

.entity-activities__empty-hint {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}

// ─── Load more ────────────────────────────────────────────────────────────────

.entity-activities__load-more {
  display: flex;
  justify-content: center;
  padding: $space-2 0;
}

// ─── Groups ───────────────────────────────────────────────────────────────────

.entity-activities__group {
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.entity-activities__date-header {
  display: flex;
  align-items: center;
  gap: $space-2;
  background: none;
  border: none;
  padding: $space-1 0;
  cursor: pointer;
  width: 100%;
}

.entity-activities__date-line {
  flex: 1;
  height: 1px;
  background: var(--p-surface-200);

  .app-dark & {
    background: var(--p-surface-700);
  }
}

.entity-activities__date-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: $surface-400;
  white-space: nowrap;
  flex-shrink: 0;
}

.entity-activities__date-toggle {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.entity-activities__list {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding-left: $space-3;
  border-left: 2px solid var(--p-surface-200);

  .app-dark & {
    border-left-color: var(--p-surface-700);
  }
}

.entity-activities__item {
  display: flex;
  flex-direction: column;
}

.entity-activities__activity-card {
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-1;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.entity-activities__activity-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.entity-activities__kind-icon {
  font-size: $font-size-sm;
  color: $surface-400;
  flex-shrink: 0;
}

.entity-activities__activity-title {
  flex: 1;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-800;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &--done {
    text-decoration: line-through;
    color: $surface-400;
  }
}

.entity-activities__note-body {
  font-size: $font-size-sm;
  color: $surface-600;
  margin: 0;
  white-space: pre-wrap;
  word-break: break-word;

  .app-dark & {
    color: var(--p-surface-300);
  }
}

.entity-activities__activity-meta {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  font-size: $font-size-xs;
}

.entity-activities__due {
  color: $surface-400;
}

.entity-activities__actor {
  color: $surface-500;
}

// ─── Field change (лог-строка §5) ────────────────────────────────────────────

.entity-activities__field-change {
  display: flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-xs;
  color: $surface-500;
  padding: $space-1 $space-2;
}

.entity-activities__fc-circle {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 22px; // spec: 22px circle
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 22px;
  border-radius: $radius-circle;
  background: var(--p-surface-100);
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-200);
  }

  i {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    font-size: 10px; // spec: 10px icon inside circle
    color: var(--p-primary-color);
  }
}

.entity-activities__fc-text {
  flex: 1;
  font-size: $font-size-xs;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-300);
  }

  strong {
    font-weight: $font-weight-medium;
  }
}

.entity-activities__fc-field {
  font-weight: $font-weight-medium;
  color: $surface-700;
  margin-left: $space-1;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.entity-activities__fc-arrow {
  color: $surface-500;
  margin-left: $space-1;

  .app-dark & {
    color: var(--p-surface-400);
  }
}
</style>
