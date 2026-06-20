<template>
  <div class="entity-activities">
    <!--
      Layout (top → bottom):
        [Feed — scrollable, bottom-up: oldest at top, newest near composer]
        [OpenTasksList — compact rows for pending tasks]
        [EntityComposer — note/task creation]
    -->
    <div ref="scrollEl" class="entity-activities__feed-wrap">
      <div class="entity-activities__feed-inner">
        <!-- Loading skeleton -->
        <div v-if="loading && groups.length === 0" class="entity-activities__skeleton">
          <Skeleton height="60px" class="mb-2" />
          <Skeleton height="80px" class="mb-2" />
          <Skeleton height="60px" />
        </div>

        <!-- Empty state -->
        <div v-else-if="!loading && groups.length === 0" class="entity-activities__empty">
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

          <div v-for="group in groups" :key="group.date" class="entity-activities__group">
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

                <!-- Field change item -->
                <div v-else-if="item.fieldChanges" class="entity-activities__field-change">
                  <i class="pi pi-pencil entity-activities__fc-icon" />
                  <span class="entity-activities__fc-text">
                    {{ item.actor?.full_name ?? t('common.system') }}
                    {{ t('sales.deal.feed.changedField') }}:
                    <strong v-for="(fc, idx) in item.fieldChanges" :key="idx">{{ fc.field }}</strong>
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

const props = defineProps<{
  entityType: EntityFeedType
  entityId: number
}>()

const { t } = useI18n()

const scrollEl = ref<HTMLElement | null>(null)

const feed = useEntityFeed(
  () => props.entityType,
  () => props.entityId,
)

const groups = computed(() => feed.groups.value)
const loading = computed(() => feed.loading.value)

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
  font-size: 3rem;
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

.entity-activities__field-change {
  display: flex;
  align-items: center;
  gap: $space-2;
  font-size: $font-size-xs;
  color: $surface-500;
  padding: $space-2 $space-3;
}

.entity-activities__fc-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.entity-activities__fc-text {
  strong {
    font-weight: $font-weight-medium;
    color: $surface-600;
    margin-left: $space-1;
  }
}
</style>
