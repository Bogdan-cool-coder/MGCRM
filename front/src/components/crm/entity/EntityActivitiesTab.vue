<template>
  <div class="entity-activities">
    <!--
      Layout (top → bottom, spec §5):
        [Filter chips — Все / События / Изменения]
        [Feed — scrollable, bottom-up: spacer + oldest at top + newest near composer]
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

    <!--
      Feed scroll area — spec §5: fixed ~68vh, bottom-up (margin-top:auto on spacer),
      hidden scrollbar. The feed-inner is a column-flex container:
        [spacer (flex:1)] → [groups oldest→newest] → [load-more at bottom of inner]
    -->
    <div ref="scrollEl" class="entity-activities__feed-wrap">
      <div class="entity-activities__feed-inner">
        <!-- Flex spacer — pushes content to bottom (bottom-up layout, DealCard §11) -->
        <div class="entity-activities__spacer" />

        <!-- Loading skeleton -->
        <div v-if="loading && groups.length === 0" class="entity-activities__skeleton">
          <Skeleton height="60px" class="mb-2" />
          <Skeleton height="80px" class="mb-2" />
          <Skeleton height="60px" />
        </div>

        <!-- Error state -->
        <div
          v-else-if="!loading && hasError && groups.length === 0"
          class="entity-activities__empty"
        >
          <i class="pi pi-exclamation-triangle entity-activities__empty-icon" />
          <p class="entity-activities__empty-title">{{ t('errors.server_error') }}</p>
          <Button
            icon="pi pi-refresh"
            :label="t('common.retry')"
            severity="secondary"
            outlined
            size="small"
            @click="feed.load()"
          />
        </div>

        <!-- Empty state -->
        <div v-else-if="!loading && filteredGroups.length === 0" class="entity-activities__empty">
          <i class="pi pi-clock entity-activities__empty-icon" />
          <p class="entity-activities__empty-title">{{ t('sales.deal.feed.empty.title') }}</p>
          <p class="entity-activities__empty-hint">{{ t('sales.deal.feed.empty.subtitle') }}</p>
        </div>

        <!-- Chronological groups -->
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
                <!--
                  Activity card (only completed activities appear here per DealCard §11).
                  Kind icon = colored circle tile. Card border tinted to type color.
                -->
                <div
                  v-if="item.activity"
                  class="entity-activities__activity-card"
                  :style="activityCardStyle(item.activity.kind)"
                >
                  <div class="entity-activities__activity-row">
                    <!-- Colored circle-tile with kind icon, spec §5 / DealCard §11 -->
                    <span
                      class="entity-activities__kind-tile"
                      :style="kindTileStyle(item.activity.kind)"
                    >
                      <i :class="['pi', resolvedKindIcon(item.activity.kind)]" />
                    </span>
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
                  <!--
                    Meta line: «дата · время · ответственный» — ONE line, spec DealCard §12.
                  -->
                  <div class="entity-activities__activity-meta">
                    <span class="entity-activities__activity-meta-line">
                      <template v-if="item.activity.due_at || item.actor">
                        <template v-if="item.activity.due_at">{{ formatDueDateLine(item.activity.due_at) }}</template>
                        <template v-if="item.activity.due_at && item.actor"> · </template>
                        <template v-if="item.actor">{{ item.actor.full_name }}</template>
                      </template>
                    </span>
                  </div>
                </div>

                <!--
                  Field change log row — ONE line format per DealCard §11:
                  «Автор · дата · время · действие · ~~старое~~ → новое»
                -->
                <div v-else-if="item.fieldChanges" class="entity-activities__field-change">
                  <div class="entity-activities__fc-circle">
                    <i class="pi pi-pencil" />
                  </div>
                  <span class="entity-activities__fc-text">
                    <strong>{{ item.actor?.full_name ?? t('common.system') }}</strong>
                    <template v-if="item.timestamp"> · {{ formatTimestamp(item.timestamp) }}</template>
                    {{ t('sales.deal.feed.changedField') }}:
                    <span v-for="(fc, idx) in item.fieldChanges" :key="idx" class="entity-activities__fc-field">
                      {{ fc.field }}
                      <template v-if="fc.old_value !== undefined && fc.new_value !== undefined">
                        <!-- old value struck-through, spec DealCard §11 -->
                        <s class="entity-activities__fc-old">{{ fc.old_value }}</s>
                        <span class="entity-activities__fc-arrow"> → </span>
                        <span class="entity-activities__fc-new">{{ fc.new_value }}</span>
                      </template>
                    </span>
                  </span>
                  <span v-if="item.timestamp" class="entity-activities__fc-time">
                    {{ formatTime(item.timestamp) }}
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
      @edit="onEditTask"
    />

    <!-- Edit task dialog (B4) — mounted here so it's always available -->
    <ActivityFormDialog
      v-model="editDialogVisible"
      :activity-id="editActivityId"
      @updated="onTaskUpdated"
    />

    <!-- Composer (note/task creation) -->
    <EntityComposer
      ref="composerRef"
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
import ActivityFormDialog from '@/components/ActivityFormDialog.vue'
import { useEntityFeed } from './composables/useEntityFeed'
import { kindIcon, kindColor, statusSeverity } from '@/utils/activity'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

export type EntityFeedType = 'company' | 'contact'
type FeedFilter = 'all' | 'events' | 'changes'

const props = defineProps<{
  entityType: EntityFeedType
  entityId: number
}>()

const { t } = useI18n()

const scrollEl = ref<HTMLElement | null>(null)
const feedFilter = ref<FeedFilter>('all')
const composerRef = ref<InstanceType<typeof EntityComposer> | null>(null)

// ─── Edit task dialog state (B4) ─────────────────────────────────────────────

const editDialogVisible = ref(false)
const editActivityId = ref<number | null>(null)

const feed = useEntityFeed(
  () => props.entityType,
  () => props.entityId,
)

const groups = computed(() => feed.groups.value)
const loading = computed(() => feed.loading.value)
const hasError = computed(() => feed.error.value !== null)

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

// ─── Kind icon resolution ─────────────────────────────────────────────────────

/** Returns the PI class name (without 'pi' prefix) for the given kind */
function resolvedKindIcon(kind: ActivityKind): string {
  // kindIcon returns full 'pi pi-xxx' string; extract the second class
  return kindIcon(kind).split(' ')[1] ?? 'pi-circle'
}

// ─── Colored kind tile styles (spec §5 / DealCard §11) ───────────────────────

/**
 * Circle tile background = light tint of type color.
 * Note/task = neutral (no tint, just surface-100).
 */
function kindTileStyle(kind: ActivityKind): Record<string, string> {
  const color = kindColor(kind)
  if (!color) {
    return {
      background: 'var(--p-surface-100)',
      color: 'var(--p-surface-500)',
    }
  }
  return {
    background: `color-mix(in srgb, ${color} 16%, var(--p-card-background))`,
    color: color,
  }
}

/**
 * Card border tinted to type color.
 * Note/task = neutral border.
 */
function activityCardStyle(kind: ActivityKind): Record<string, string> {
  const color = kindColor(kind)
  if (!color) return {}
  return {
    borderColor: `color-mix(in srgb, ${color} 45%, var(--p-surface-200))`,
  }
}

// ─── Date/time formatters ─────────────────────────────────────────────────────

function formatDueDateLine(dateStr: string): string {
  const d = new Date(dateStr)
  const date = d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  return `${date} · ${time}`
}

function formatTimestamp(ts: string | number | Date): string {
  const d = new Date(ts)
  const date = d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short' })
  const time = d.toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
  return `${date}, ${time}`
}

function formatTime(ts: string | number | Date): string {
  return new Date(ts).toLocaleTimeString('ru-RU', { hour: '2-digit', minute: '2-digit' })
}

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

function onEditTask(activity: ActivityDto) {
  editActivityId.value = activity.id
  editDialogVisible.value = true
}

function onTaskUpdated(activity: ActivityDto) {
  feed.updateActivityLocal(activity)
}

function formatGroupDate(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' })
}

onMounted(() => {
  void feed.load()
})

defineExpose({
  focusNote: () => composerRef.value?.focusNote(),
  focusTask: () => composerRef.value?.focusTask(),
})
</script>

<style lang="scss" scoped>
// ─── Root container ───────────────────────────────────────────────────────────

.entity-activities {
  display: flex;
  flex-direction: column;
  // spec §5: fixed ~68vh so filter chips + feed + composer fill the viewport
  height: 68vh;
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
// Hidden scrollbar — spec §5 / DealCard §0

.entity-activities__feed-wrap {
  flex: 1;
  overflow-y: auto;
  display: flex;
  flex-direction: column;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

.entity-activities__feed-inner {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-3 $space-4;
  min-height: 100%;
  // bottom-up: no justify-content:flex-end — use flex spacer instead (DealCard §11)
}

// Flex spacer: pushes content to bottom (bottom-up layout)
.entity-activities__spacer {
  flex: 1;
  min-height: 0;
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
}

.entity-activities__item {
  display: flex;
  flex-direction: column;
}

// ─── Activity card ────────────────────────────────────────────────────────────

.entity-activities__activity-card {
  background: $surface-card;
  border: 1px solid var(--p-surface-200); // base; overridden by inline style for typed cards
  border-radius: $radius-md;
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.entity-activities__activity-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

// Colored kind icon tile — 24×24 circle, spec §5 / DealCard §11
.entity-activities__kind-tile {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 24px; // spec: 24px circle tile
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 24px;
  border-radius: $radius-circle;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  // background and color set via :style (dynamic per kind type)

  i {
    font-size: $font-size-xs;
  }
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

// Meta line — ONE line «дата · время · ответственный», spec DealCard §12
.entity-activities__activity-meta {
  font-size: $font-size-xs;
  color: $surface-400;
}

.entity-activities__activity-meta-line {
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-500);
  }
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

// Old value — struck-through, spec DealCard §11
.entity-activities__fc-old {
  text-decoration: line-through;
  color: $surface-400;
}

.entity-activities__fc-arrow {
  color: $surface-500;
  margin: 0 1px;
}

.entity-activities__fc-new {
  color: $surface-700;
  font-weight: $font-weight-medium;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.entity-activities__fc-time {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
  white-space: nowrap;
}
</style>
