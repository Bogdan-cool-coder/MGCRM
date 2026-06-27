<template>
  <div class="my-tasks-page">
    <!-- TopBar (single row) -->
    <TasksTopBar
      v-model:view="activeView"
      v-model:scope="boardScope"
      :filter-active="filterOpen || hasActiveFilters"
      :filter-count="activeFilterCount"
      :total-count="totalCount"
      :overdue-count="counts?.overdue ?? 0"
      @toggle-filter="filterOpen = !filterOpen"
      @toggle-quick-create="quickOpen = !quickOpen"
      @enter-select-mode="enterSelectMode"
    />

    <!-- QuickCreate panel -->
    <Transition name="tasks-panel-slide">
      <TasksQuickCreate
        v-if="quickOpen"
        @created="onActivityCreated"
        @cancel="quickOpen = false"
      />
    </Transition>

    <!-- FilterPanel — list view only (kanban has no server-side filter support) -->
    <Transition name="tasks-panel-slide">
      <MyTasksFilterPanel
        v-if="filterOpen && activeView === 'list'"
        v-model="filters"
        @reset="onResetFilters"
        @close="filterOpen = false"
      />
    </Transition>

    <!-- BulkBar -->
    <Transition name="tasks-panel-slide">
      <TasksBulkBar
        v-if="selectMode"
        :selected-count="selectedIds.size"
        :total-visible="totalVisibleCount"
        @cancel="exitSelectMode"
        @pin="onBulkPin"
        @reopen="onBulkReopen"
        @delete="onBulkDelete"
        @select-all="selectAll"
        @clear-selection="clearSelection"
      />
    </Transition>

    <!-- KANBAN VIEW -->
    <div v-if="activeView === 'kanban'" class="my-tasks-page__kanban-wrap">
      <TasksKanbanBoard
        :scope="boardScope"
        :select-mode="selectMode"
        :selected-ids="selectedIds"
        :loading="taskBoard.loading.value"
        :all-done="taskBoard.allDone.value"
        :buckets-data="taskBoard.bucketsData.value"
        @task-created="onKanbanTaskCreated"
        @task-completed="onKanbanTaskCompleted"
        @task-rescheduled="onKanbanTaskRescheduled"
        @error="onKanbanError"
        @toggle-select="toggleSelectItem"
        @complete="onKanbanComplete"
        @reschedule="onKanbanReschedule"
      />
    </div>

    <!-- LIST VIEW -->
    <div v-else class="my-tasks-page__content">
      <!-- Preset tabs -->
      <MyTasksPresetTabs
        v-model="activePreset"
        :counts="counts"
        class="my-tasks-page__tabs"
      />

      <!-- Table -->
      <div class="my-tasks-page__table">
        <MyTasksTable
          :items="items"
          :total="total"
          :loading="loading"
          :per-page="perPage"
          :preset="activePreset"
          :completing-id="completingId"
          :reopening-id="reopeningId"
          :select-mode="selectMode"
          :selected-ids="selectedIds"
          :total-visible="items.length"
          @page="onPage"
          @complete="onComplete"
          @reopen="onReopen"
          @edit="onEdit"
          @pin="onPin"
          @delete="onDelete"
          @create="onCreateTask"
          @patched="onActivityPatched"
          @toggle-select="toggleSelectItem"
          @select-all="selectAllList"
          @clear-selection="clearSelection"
        />
      </div>
    </div>

    <!-- Activity form dialog -->
    <ActivityFormDialog
      v-model="formDialogOpen"
      :activity-id="editingActivityId"
      :default-kind="'task'"
      @created="onActivityCreated"
      @updated="onActivityUpdated"
    />

    <Toast position="top-right" />
    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import TasksTopBar from './components/TasksTopBar.vue'
import TasksQuickCreate from './components/TasksQuickCreate.vue'
import TasksBulkBar from './components/TasksBulkBar.vue'
import MyTasksPresetTabs from './components/MyTasksPresetTabs.vue'
import MyTasksFilterPanel from './components/MyTasksFilterPanel.vue'
import MyTasksTable from './components/MyTasksTable.vue'
import TasksKanbanBoard from './components/TasksKanbanBoard.vue'
import ActivityFormDialog from '@/components/ActivityFormDialog.vue'
import { activityApi } from '@/api/activity'
import { useActivityStore } from '@/stores/activityStore'
import { useMyTasks } from './composables/useMyTasks'
import { useTaskBoard } from './composables/useTaskBoard'
import { getApiErrorMessage } from '@/utils/errors'
import type { ActivityDto } from '@/entities/activity'
import type { MyBoardBucket } from '@/entities/activity'
import type { TaskScope } from './composables/useTaskBoard'

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()
const activityStore = useActivityStore()

// ── View ─────────────────────────────────────────────────────────────────────

const TASKS_VIEW_KEY = 'tasks_active_view'
type TasksPageView = 'kanban' | 'list'

const _savedView = localStorage.getItem(TASKS_VIEW_KEY) as TasksPageView | null
const activeView = ref<TasksPageView>(_savedView ?? 'kanban')

// Persist view and trigger reload when switching views
watch(activeView, (view) => {
  localStorage.setItem(TASKS_VIEW_KEY, view)
  if (view === 'list') {
    void Promise.all([load(), refreshCounts()])
  } else {
    // Switching to kanban: ensure board is loaded (may be stale or unloaded)
    void taskBoard.load()
  }
})

// ── Scope (kanban only) ───────────────────────────────────────────────────────
const boardScope = ref<TaskScope>('month')

// ── UI state ──────────────────────────────────────────────────────────────────
const quickOpen = ref(false)
const filterOpen = ref(false)
const selectMode = ref(false)
const selectedIds = ref(new Set<number>())

function enterSelectMode() {
  selectMode.value = true
}

function exitSelectMode() {
  selectMode.value = false
  selectedIds.value = new Set()
}

function toggleSelectItem(id: number) {
  const next = new Set(selectedIds.value)
  if (next.has(id)) {
    next.delete(id)
  } else {
    next.add(id)
  }
  selectedIds.value = next
}

function selectAll() {
  if (activeView.value === 'kanban') {
    // Select all tasks visible in the current scope across all kanban buckets
    const scopeBuckets = bucketsForScope(boardScope.value)
    const ids: number[] = []
    for (const bucket of taskBoard.bucketsData.value) {
      if (!scopeBuckets.includes(bucket.key)) continue
      for (const task of bucket.tasks) {
        ids.push(task.id)
      }
    }
    selectedIds.value = new Set(ids)
  } else {
    selectedIds.value = new Set(items.value.map((a) => a.id))
  }
}

function selectAllList() {
  selectedIds.value = new Set(items.value.map((a) => a.id))
}

function clearSelection() {
  selectedIds.value = new Set()
}

// Helper: mirrors TasksKanbanBoard.bucketsForScope (kept here for selectAll)
const ALL_BOARD_BUCKETS: MyBoardBucket[] = ['overdue', 'today', 'tomorrow', 'this_week', 'next_week']
function bucketsForScope(scope: TaskScope): MyBoardBucket[] {
  if (scope === 'day') return ['overdue', 'today', 'tomorrow']
  if (scope === 'week') return ['overdue', 'today', 'tomorrow', 'this_week']
  return ALL_BOARD_BUCKETS
}

// ── Filters ───────────────────────────────────────────────────────────────────
const {
  activePreset,
  filters,
  perPage,
  items,
  total,
  loading,
  counts,
  load,
  onPage,
  resetFilters,
  refreshCounts,
  removeLocal,
  updateLocal,
  addLocal,
} = useMyTasks()

// ── Task board (lifted here so select-all / bulk / BulkBar count work in kanban)
const taskBoard = useTaskBoard()

const hasActiveFilters = computed(() => {
  const f = filters.value
  return !!(f.kind || f.status || f.priority || f.responsible_id || f.due_from || f.due_to || f.q)
})

const activeFilterCount = computed(() => {
  const f = filters.value
  return [f.kind, f.status, f.priority, f.responsible_id, f.due_from, f.due_to, f.q]
    .filter(Boolean).length
})

const totalCount = computed(() => counts.value?.my_tasks ?? 0)

const totalVisibleCount = computed(() => {
  if (activeView.value === 'list') return items.value.length
  // Kanban: count all tasks in scope-visible buckets
  const scopeBuckets = bucketsForScope(boardScope.value)
  return taskBoard.bucketsData.value
    .filter((b) => scopeBuckets.includes(b.key))
    .reduce((sum, b) => sum + b.tasks.length, 0)
})

function onResetFilters() {
  resetFilters()
  filterOpen.value = false
}

// ── Form dialog ───────────────────────────────────────────────────────────────
const formDialogOpen = ref(false)
const editingActivityId = ref<number | null>(null)
const completingId = ref<number | null>(null)
const reopeningId = ref<number | null>(null)

function onCreateTask() {
  editingActivityId.value = null
  formDialogOpen.value = true
}

function onEdit(activity: ActivityDto) {
  editingActivityId.value = activity.id
  formDialogOpen.value = true
}

// ── Single-item mutations ─────────────────────────────────────────────────────

async function onComplete(activity: ActivityDto) {
  completingId.value = activity.id
  removeLocal(activity.id)
  try {
    await activityApi.completeActivity(activity.id)
    toast.add({ severity: 'success', summary: t('activity.actions.completeSuccess'), life: 3000 })
    void refreshCounts()
  } catch (err) {
    addLocal(activity)
    const status = (err as { response?: { status?: number } })?.response?.status
    const msg =
      status === 403
        ? t('activity.actions.noPermissionComplete')
        : getApiErrorMessage(err, t('errors.server_error'))
    toast.add({ severity: 'error', summary: msg, life: 4000 })
  } finally {
    completingId.value = null
  }
}

async function onReopen(activity: ActivityDto) {
  reopeningId.value = activity.id
  removeLocal(activity.id)
  try {
    await activityApi.reopenActivity(activity.id)
    toast.add({ severity: 'success', summary: t('activity.actions.reopenSuccess'), life: 3000 })
    void refreshCounts()
  } catch (err) {
    addLocal(activity)
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    reopeningId.value = null
  }
}

async function onPin(activity: ActivityDto) {
  const newPinned = !activity.is_pinned
  updateLocal({ ...activity, is_pinned: newPinned })
  try {
    const updated = await activityApi.updateActivity(activity.id, { is_pinned: newPinned })
    updateLocal(updated)
    toast.add({
      severity: 'success',
      summary: newPinned ? t('activity.actions.pinSuccess') : t('activity.actions.unpinSuccess'),
      life: 2000,
    })
  } catch (err) {
    updateLocal(activity)
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 3000,
    })
  }
}

function onDelete(activity: ActivityDto) {
  confirm.require({
    header: t('activity.actions.deleteConfirmHeader'),
    message: t('activity.actions.deleteConfirmBody'),
    acceptLabel: t('activity.actions.deleteConfirmAccept'),
    rejectLabel: t('activity.actions.deleteConfirmReject'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      try {
        await activityApi.deleteActivity(activity.id)
        removeLocal(activity.id)
        toast.add({ severity: 'success', summary: t('activity.actions.deleteSuccess'), life: 3000 })
        void refreshCounts()
      } catch (err) {
        toast.add({
          severity: 'error',
          summary: getApiErrorMessage(err, t('errors.server_error')),
          life: 3000,
        })
      }
    },
  })
}

function onActivityCreated(activity: ActivityDto) {
  addLocal(activity)
  quickOpen.value = false
  toast.add({ severity: 'success', summary: t('activity.form.successCreate'), life: 3000 })
  void refreshCounts()
}

function onActivityUpdated(activity: ActivityDto) {
  updateLocal(activity)
  toast.add({ severity: 'success', summary: t('activity.form.successUpdate'), life: 3000 })
}

// Presets that only show OPEN tasks — closing a task here should remove it from the list
const OPEN_PRESETS = ['my_tasks', 'today', 'overdue', 'this_week'] as const

function onActivityPatched(activity: ActivityDto) {
  const isClosed = activity.status === 'done' || activity.status === 'rejected'
  const onOpenPreset = (OPEN_PRESETS as readonly string[]).includes(activePreset.value)
  if (isClosed && onOpenPreset) {
    // Task was closed while viewing an open-only list — remove the row (mirror onComplete)
    removeLocal(activity.id)
    void refreshCounts()
  } else {
    updateLocal(activity)
  }
}

// ── Bulk actions ──────────────────────────────────────────────────────────────

async function onBulkPin() {
  const ids = [...selectedIds.value]
  if (!ids.length) return
  const isKanban = activeView.value === 'kanban'
  let succeeded = 0
  for (const id of ids) {
    try {
      const updated = await activityApi.updateActivity(id, { is_pinned: true })
      if (isKanban) {
        // Merge only is_pinned into the existing board task (preserves MyBoardActivityDto shape)
        taskBoard.patchLocalById(id, { is_pinned: true })
      } else {
        updateLocal(updated)
      }
      succeeded++
    } catch {
      // continue
    }
  }
  if (succeeded > 0) {
    toast.add({ severity: 'success', summary: t('activity.actions.pinSuccess'), life: 2000 })
  }
  clearSelection()
}

async function onBulkReopen() {
  const ids = [...selectedIds.value]
  if (!ids.length) return
  const isKanban = activeView.value === 'kanban'
  let succeeded = 0
  for (const id of ids) {
    try {
      await activityApi.reopenActivity(id)
      if (isKanban) {
        // Reopened tasks stay in the board (they were likely "done" tasks still in a bucket)
        // Just reload the board to get fresh server state
      } else {
        removeLocal(id)
      }
      succeeded++
    } catch {
      // continue
    }
  }
  if (succeeded > 0) {
    if (isKanban) {
      void taskBoard.load()
    }
    toast.add({ severity: 'success', summary: t('tasks.bulk.reopenSuccess'), life: 2000 })
    void refreshCounts()
  }
  clearSelection()
}

function onBulkDelete() {
  const ids = [...selectedIds.value]
  if (!ids.length) return
  const isKanban = activeView.value === 'kanban'
  confirm.require({
    header: t('tasks.bulk.confirmDelete'),
    message: t('tasks.bulk.confirmDeleteBody'),
    acceptLabel: t('tasks.bulk.confirmYes'),
    rejectLabel: t('tasks.bulk.confirmNo'),
    acceptClass: 'p-button-danger',
    accept: async () => {
      let succeeded = 0
      for (const id of ids) {
        try {
          await activityApi.deleteActivity(id)
          if (isKanban) {
            taskBoard.removeLocalById(id)
          } else {
            removeLocal(id)
          }
          succeeded++
        } catch {
          // continue
        }
      }
      if (succeeded > 0) {
        toast.add({ severity: 'success', summary: t('tasks.bulk.deleteSuccess'), life: 2000 })
        void refreshCounts()
      }
      clearSelection()
      exitSelectMode()
    },
  })
}

// ── Kanban view handlers ──────────────────────────────────────────────────────

function onKanbanTaskCreated(activity: ActivityDto) {
  toast.add({ severity: 'success', summary: t('activity.form.successCreate'), life: 3000 })
  addLocal(activity)
  void refreshCounts()
}

function onKanbanTaskCompleted() {
  toast.add({ severity: 'success', summary: t('tasks.board.card.completed'), life: 3000 })
  void refreshCounts()
}

function onKanbanTaskRescheduled() {
  toast.add({ severity: 'success', summary: t('tasks.board.reschedule.success'), life: 2500 })
  void refreshCounts()
}

function onKanbanError(message: string) {
  toast.add({ severity: 'error', summary: message, life: 4000 })
}

/**
 * Board card "complete" — lifted from TasksKanbanBoard so the page owns all
 * board mutations (required for correct bulk/select-all state).
 */
async function onKanbanComplete(id: number) {
  try {
    await taskBoard.completeTask(id)
    onKanbanTaskCompleted()
  } catch {
    onKanbanError(t('tasks.board.card.completed'))
  }
}

/**
 * Board drag-and-drop reschedule — lifted from TasksKanbanBoard.
 */
async function onKanbanReschedule(taskId: number, targetBucket: MyBoardBucket) {
  try {
    await taskBoard.rescheduleTask(taskId, targetBucket)
    onKanbanTaskRescheduled()
  } catch {
    onKanbanError(t('tasks.board.reschedule.error'))
  }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

onMounted(async () => {
  if (activeView.value === 'list') {
    await Promise.all([load(), refreshCounts()])
  } else {
    // Kanban: load board data + counts; list data on demand (when switching to list)
    await Promise.all([taskBoard.load(), refreshCounts()])
  }
  await activityStore.fetchMyOpenCount()
})
</script>

<style lang="scss" scoped>
.my-tasks-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  // Pull page out of shell padding to fill edge-to-edge
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.my-tasks-page__kanban-wrap {
  flex: 1;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.my-tasks-page__content {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  padding: $space-4 $space-5;
  display: flex;
  flex-direction: column;
  gap: $space-4;
}

.my-tasks-page__table {
  background: $surface-card;
  border-radius: $radius-lg;
  border: 1px solid $surface-200;
  box-shadow: $shadow-card;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

// ── Slide transitions for QuickCreate / FilterPanel / BulkBar ─────────────────
.tasks-panel-slide-enter-active,
.tasks-panel-slide-leave-active {
  transition: all 0.18s ease;
  overflow: hidden;
}

.tasks-panel-slide-enter-from,
.tasks-panel-slide-leave-to {
  opacity: 0;
  max-height: 0;
}

.tasks-panel-slide-enter-to,
.tasks-panel-slide-leave-from {
  opacity: 1;
  max-height: 300px;
}
</style>
