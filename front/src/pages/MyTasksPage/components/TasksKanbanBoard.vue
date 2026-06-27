<template>
  <div class="task-board">
    <!-- Loading skeleton -->
    <template v-if="taskBoard.loading.value">
      <div class="task-board__columns">
        <div v-for="col in 3" :key="col" class="task-board__col">
          <div class="task-board__col-header task-board__col-header--skeleton">
            <Skeleton width="80px" height="14px" />
          </div>
          <div class="task-board__col-body">
            <Skeleton v-for="s in 3" :key="s" height="100px" class="mb-2" />
          </div>
        </div>
      </div>
    </template>

    <!-- All done empty state -->
    <div v-else-if="taskBoard.allDone.value" class="task-board__empty">
      <i class="pi pi-check-circle task-board__empty-icon task-board__empty-icon--success" />
      <p class="task-board__empty-title">{{ t('tasks.board.empty.title') }}</p>
      <p class="task-board__empty-subtitle">{{ t('tasks.board.empty.subtitle') }}</p>
    </div>

    <!-- Board columns -->
    <div v-else class="task-board__columns">
      <div
        v-for="bucket in visibleBuckets"
        :key="bucket.key"
        class="task-board__col"
        :class="{ 'task-board__col--drop-target': dragOverBucket === bucket.key }"
        @dragover.prevent="onDragOver(bucket.key)"
        @dragleave="onDragLeave(bucket.key)"
        @drop.prevent="onDrop($event, bucket.key)"
      >
        <!-- Column header -->
        <div
          class="task-board__col-header"
          :style="{ '--bucket-color': BUCKET_COLORS[bucket.key] }"
        >
          <!-- Top row: count | name | spacer -->
          <div class="task-board__col-header-row">
            <span class="task-board__col-count">{{ bucket.tasks.length }}</span>
            <span class="task-board__col-name">{{ t(`tasks.board.columns.${bucket.key}`) }}</span>
            <span />
          </div>
          <!-- Meta line -->
          <p class="task-board__col-meta">{{ bucketMeta(bucket.key) }}</p>
        </div>

        <!-- Cards list -->
        <div class="task-board__col-body">
          <TaskCard
            v-for="task in bucket.tasks"
            :key="task.id"
            :task="task"
            :bucket="bucket.key"
            :select-mode="selectMode"
            :selected="(selectedIds ?? new Set()).has(task.id)"
            class="task-board__card"
            @complete="onComplete"
            @toggle-select="emit('toggleSelect', $event)"
            @dragstart="onCardDragStart($event, task.id, bucket.key)"
            @dragend="onCardDragEnd"
          />
          <div v-if="bucket.tasks.length === 0" class="task-board__col-empty">
            {{ t('tasks.board.columns.noTasks') }}
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Skeleton from 'primevue/skeleton'
import TaskCard from './TaskCard.vue'
import { useTaskBoard } from '../composables/useTaskBoard'
import type { TaskScope } from '../composables/useTaskBoard'
import { OPERATIONAL_TZ } from '@/utils/activity'
import type { MyBoardBucket } from '@/entities/activity'
import type { ActivityDto } from '@/entities/activity'

const props = defineProps<{
  scope: TaskScope
  selectMode?: boolean
  selectedIds?: Set<number>
}>()

const emit = defineEmits<{
  taskCompleted: []
  taskCreated: [activity: ActivityDto]
  error: [message: string]
  toggleSelect: [id: number]
  taskRescheduled: []
}>()

const { t } = useI18n()
const taskBoard = useTaskBoard()

// ── Bucket colors (spec §0) ────────────────────────────────────────────────────
const BUCKET_COLORS: Record<MyBoardBucket, string> = {
  overdue: '#FF5A44',
  today: '#EF9F27',
  tomorrow: '#378ADD',
  this_week: '#7F77DD',
  next_week: '#1D9E75',
}

// ── Scope → visible buckets ────────────────────────────────────────────────────
const ALL_BUCKETS: MyBoardBucket[] = ['overdue', 'today', 'tomorrow', 'this_week', 'next_week']

function bucketsForScope(scope: TaskScope): MyBoardBucket[] {
  if (scope === 'day') return ['overdue', 'today', 'tomorrow']
  if (scope === 'week') return ['overdue', 'today', 'tomorrow', 'this_week']
  return ALL_BUCKETS
}

// ── Computed board ─────────────────────────────────────────────────────────────
const visibleBuckets = computed(() => {
  const scopeBuckets = bucketsForScope(props.scope)
  return taskBoard.bucketsData.value
    .filter((b) => {
      // Always scope-filter
      if (!scopeBuckets.includes(b.key)) return false
      // Auto-hide overdue when no non-done tasks (spec §5)
      if (b.key === 'overdue') {
        return b.tasks.some((t) => t.status !== 'done')
      }
      return true
    })
})

// ── Meta line (spec §5.1) ──────────────────────────────────────────────────────
function bucketMeta(key: MyBoardBucket): string {
  if (key === 'overdue') return t('tasks.kanban.bucketMeta.overdue')

  const now = new Date()
  const locale = 'ru-RU'
  const opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', weekday: 'short', timeZone: OPERATIONAL_TZ }

  if (key === 'today') {
    return new Intl.DateTimeFormat(locale, opts).format(now)
  }
  if (key === 'tomorrow') {
    const d = new Date(now.getTime() + 86_400_000)
    return new Intl.DateTimeFormat(locale, opts).format(d)
  }
  if (key === 'this_week') {
    // "до {end of week}"
    const dayIdx = now.getDay() // 0=Sun
    const daysToSunday = dayIdx === 0 ? 0 : 7 - dayIdx
    const sun = new Date(now.getTime() + daysToSunday * 86_400_000)
    const d = new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', timeZone: OPERATIONAL_TZ }).format(sun)
    return `до ${d}`
  }
  if (key === 'next_week') {
    const dayIdx = now.getDay()
    const daysToNextMon = dayIdx === 0 ? 1 : 8 - dayIdx
    const nextMon = new Date(now.getTime() + daysToNextMon * 86_400_000)
    const nextSun = new Date(nextMon.getTime() + 6 * 86_400_000)
    const fmt = (d: Date) => new Intl.DateTimeFormat(locale, { day: 'numeric', month: 'long', timeZone: OPERATIONAL_TZ }).format(d)
    return `${fmt(nextMon)} – ${fmt(nextSun)}`
  }
  return ''
}

// ── Actions ────────────────────────────────────────────────────────────────────
async function onComplete(id: number) {
  try {
    await taskBoard.completeTask(id)
    emit('taskCompleted')
  } catch {
    emit('error', t('tasks.board.card.completed'))
  }
}

// ── Drag-and-drop ─────────────────────────────────────────────────────────────

const dragOverBucket = ref<MyBoardBucket | null>(null)
/** Tracks the in-flight drag payload — set on dragstart, cleared on dragend/drop */
const dragPayload = ref<{ taskId: number; sourceBucket: MyBoardBucket } | null>(null)

function onCardDragStart(event: DragEvent, taskId: number, sourceBucket: MyBoardBucket) {
  if (!event.dataTransfer) return
  event.dataTransfer.effectAllowed = 'move'
  // Store payload both in dataTransfer (cross-component) and in a local ref
  // so the drop handler can read it even in browsers where dataTransfer.getData
  // is restricted during dragover.
  event.dataTransfer.setData('text/plain', String(taskId))
  dragPayload.value = { taskId, sourceBucket }
}

function onCardDragEnd() {
  dragOverBucket.value = null
  dragPayload.value = null
}

function onDragOver(bucket: MyBoardBucket) {
  // «overdue» is not a valid drop target — you can't reschedule into the past
  if (bucket === 'overdue') return
  dragOverBucket.value = bucket
}

function onDragLeave(bucket: MyBoardBucket) {
  if (dragOverBucket.value === bucket) {
    dragOverBucket.value = null
  }
}

async function onDrop(event: DragEvent, targetBucket: MyBoardBucket) {
  dragOverBucket.value = null
  if (targetBucket === 'overdue') return

  const payload = dragPayload.value
  dragPayload.value = null

  if (!payload || payload.sourceBucket === targetBucket) return

  try {
    await taskBoard.rescheduleTask(payload.taskId, targetBucket)
    emit('taskRescheduled')
  } catch {
    emit('error', t('tasks.board.reschedule.error'))
  }
}

onMounted(() => { void taskBoard.load() })
</script>

<style lang="scss" scoped>
.task-board {
  display: flex;
  flex-direction: column;
  height: 100%;
}

// ── Empty / loading ───────────────────────────────────────────────────────────

.task-board__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-3;
  padding: $space-8;
  color: $surface-400;
  flex: 1;
  justify-content: center;
}

.task-board__empty-icon {
  font-size: $font-size-icon-2xl;

  &--success {
    color: var(--p-green-500);
  }
}

.task-board__empty-title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  margin: 0;
}

.task-board__empty-subtitle {
  font-size: $font-size-sm;
  color: $surface-400;
  margin: 0;
}

// ── Columns container ─────────────────────────────────────────────────────────

.task-board__columns {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
  padding: $space-4 $space-5;
  overflow-x: auto;
  overflow-y: hidden;
  flex: 1;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

// ── Single column ─────────────────────────────────────────────────────────────

.task-board__col {
  width: 284px;
  min-width: 284px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  align-self: flex-start;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  overflow: hidden;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }
}

// ── Column header ─────────────────────────────────────────────────────────────

.task-board__col-header {
  border-top: 3px solid var(--bucket-color, #{$surface-300});
  // color-mix tint: 13% of bucket color into card bg
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  background: color-mix(in srgb, var(--bucket-color, #{$surface-300}) 13%, var(--app-surface-card));
  border-bottom: 1px solid $surface-200;
  padding: 11px $space-3 9px;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: color-mix(in srgb, var(--bucket-color, #{$surface-300}) 13%, var(--p-surface-100));
  }
}

.task-board__col-header--skeleton {
  border-top: 3px solid $surface-200;
  background: $surface-50;
  border-bottom: 1px solid $surface-200;
  padding: 11px $space-3 9px;

  .app-dark & {
    background: var(--p-surface-200);
    border-color: var(--p-surface-300);
  }
}

.task-board__col-header-row {
  display: grid;
  grid-template-columns: 34px 1fr 34px;
  align-items: center;
}

.task-board__col-count {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 26px;
  height: 20px;
  padding: 0 $space-1;
  border: 1px solid $surface-200;
  border-radius: $radius-pill;
  background: $surface-card;
  font-size: $font-size-xs;
  font-weight: $font-weight-bold;
  color: $surface-600;
  justify-self: start;

  .app-dark & {
    background: var(--p-surface-50);
    border-color: var(--p-surface-200);
    color: var(--p-surface-300);
  }
}

.task-board__col-name {
  text-align: center;
  font-size: $font-size-sm;
  font-weight: $font-weight-bold;
  letter-spacing: 0.04em;
  text-transform: uppercase;
  color: $surface-800;

  .app-dark & {
    color: var(--p-surface-100);
  }
}

.task-board__col-meta {
  margin: 6px 0 0;
  font-size: $font-size-xs;
  color: $surface-400;
  text-align: center;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// ── Column body ───────────────────────────────────────────────────────────────

.task-board__col-body {
  padding: $space-2;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  min-height: 60px;
  overflow-y: auto;
  max-height: calc(100vh - 280px);
  scrollbar-width: thin;
  scrollbar-color: $surface-200 transparent;
}

.task-board__col-empty {
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: $font-size-xs;
  color: $surface-400;
  min-height: 60px;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.task-board__card {
  flex: 0 0 auto; // prevent flex-shrink crushing cards when column overflows; col scrolls instead
  cursor: grab;

  &:active {
    cursor: grabbing;
  }
}

// ── Drag-and-drop drop-target highlight ────────────────────────────────────────

.task-board__col--drop-target {
  outline: 2px dashed $primary-900;
  outline-offset: -2px;
  border-radius: $radius-lg;

  .app-dark & {
    outline-color: $primary-300;
  }

  .task-board__col-body {
    background: $primary-50;

    .app-dark & {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(23, 39, 71, 0.18);
    }
  }
}
</style>
