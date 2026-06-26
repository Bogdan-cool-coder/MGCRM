<template>
  <div class="my-tasks-page">
    <PageHeader
      :title="t('activity.myTasksPage.title')"
      icon="pi pi-check-square"
    >
      <template #actions>
        <!-- View switcher -->
        <div class="my-tasks-page__views">
          <Button
            icon="pi pi-th-large"
            :class="['my-tasks-page__view-btn', { 'my-tasks-page__view-btn--active': activeView === 'kanban' }]"
            :severity="activeView === 'kanban' ? 'primary' : 'secondary'"
            text
            :title="t('tasks.page.viewKanban')"
            @click="setView('kanban')"
          />
          <Button
            icon="pi pi-list"
            :class="['my-tasks-page__view-btn', { 'my-tasks-page__view-btn--active': activeView === 'list' }]"
            :severity="activeView === 'list' ? 'primary' : 'secondary'"
            text
            :title="t('tasks.page.viewList')"
            @click="setView('list')"
          />
        </div>

        <Button
          icon="pi pi-plus"
          :label="t('activity.myTasksPage.create')"
          severity="primary"
          @click="onCreateTask"
        />
      </template>
    </PageHeader>

    <!-- KANBAN VIEW -->
    <div v-if="activeView === 'kanban'" class="my-tasks-page__kanban-wrap">
      <TasksKanbanBoard
        @task-created="onKanbanTaskCreated"
        @task-completed="onKanbanTaskCompleted"
        @error="onKanbanError"
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

      <!-- Filters -->
      <MyTasksFilterPanel
        v-model="filters"
        class="my-tasks-page__filters"
        @reset="resetFilters"
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
          @page="onPage"
          @complete="onComplete"
          @reopen="onReopen"
          @edit="onEdit"
          @pin="onPin"
          @delete="onDelete"
          @create="onCreateTask"
          @patched="onActivityPatched"
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
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Button from 'primevue/button'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import MyTasksPresetTabs from './components/MyTasksPresetTabs.vue'
import MyTasksFilterPanel from './components/MyTasksFilterPanel.vue'
import MyTasksTable from './components/MyTasksTable.vue'
import TasksKanbanBoard from './components/TasksKanbanBoard.vue'
import ActivityFormDialog from '@/components/ActivityFormDialog.vue'
import { activityApi } from '@/api/activity'
import { useActivityStore } from '@/stores/activityStore'
import { useMyTasks } from './composables/useMyTasks'
import { getApiErrorMessage } from '@/utils/errors'
import type { ActivityDto } from '@/entities/activity'

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()
const activityStore = useActivityStore()

// ── View switcher (kanban default, persisted) ──────────────────────────────

const TASKS_VIEW_KEY = 'tasks_active_view'
type TasksPageView = 'kanban' | 'list'

const _savedView = localStorage.getItem(TASKS_VIEW_KEY) as TasksPageView | null
const activeView = ref<TasksPageView>(_savedView ?? 'kanban')

function setView(view: TasksPageView) {
  activeView.value = view
  localStorage.setItem(TASKS_VIEW_KEY, view)
  if (view === 'list') {
    void Promise.all([load(), refreshCounts()])
  }
}

// ── List composable ─────────────────────────────────────────────────────────

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

async function onComplete(activity: ActivityDto) {
  completingId.value = activity.id
  // Optimistic: remove from the OPEN list so the row moves to «Выполненные» tab (F3)
  removeLocal(activity.id)
  try {
    await activityApi.completeActivity(activity.id)
    toast.add({ severity: 'success', summary: t('activity.actions.completeSuccess'), life: 3000 })
    void refreshCounts()
  } catch (err) {
    // Rollback: re-add the original task
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
  // When reopening from the «Выполненные» tab, remove from list (F3)
  removeLocal(activity.id)
  try {
    await activityApi.reopenActivity(activity.id)
    toast.add({ severity: 'success', summary: t('activity.actions.reopenSuccess'), life: 3000 })
    void refreshCounts()
  } catch (err) {
    // Rollback
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
  toast.add({ severity: 'success', summary: t('activity.form.successCreate'), life: 3000 })
  void refreshCounts()
}

function onActivityUpdated(activity: ActivityDto) {
  updateLocal(activity)
  toast.add({ severity: 'success', summary: t('activity.form.successUpdate'), life: 3000 })
}

// Inline-edit patch from MyTasksTable (optimistic + rollback already handled in table;
// page composable just syncs its own items array).
function onActivityPatched(activity: ActivityDto) {
  updateLocal(activity)
}

// ── Kanban view handlers ────────────────────────────────────────────────────

function onKanbanTaskCreated(activity: ActivityDto) {
  toast.add({ severity: 'success', summary: t('activity.form.successCreate'), life: 3000 })
  addLocal(activity)
  void refreshCounts()
}

function onKanbanTaskCompleted() {
  toast.add({ severity: 'success', summary: t('tasks.board.card.completed'), life: 3000 })
  void refreshCounts()
}

function onKanbanError(message: string) {
  toast.add({ severity: 'error', summary: message, life: 4000 })
}

// ── Bootstrap ───────────────────────────────────────────────────────────────

onMounted(async () => {
  // Only load list data if list view is active at mount
  if (activeView.value === 'list') {
    await Promise.all([load(), refreshCounts()])
  } else {
    await refreshCounts()
  }
  // Update nav badge
  await activityStore.fetchMyOpenCount()
})
</script>

<style lang="scss" scoped>
.my-tasks-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  margin: calc(-1 * $space-4) calc(-1 * $space-6) 0;
}

.my-tasks-page__views {
  display: flex;
  align-items: center;
  gap: 2px;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  padding: 2px;
  margin-right: $space-2;

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}

.my-tasks-page__view-btn {
  &--active {
    background: var(--p-primary-50) !important;

    :global(.app-dark) & {
      background: rgba(23, 39, 71, 0.4) !important;
    }
  }
}

.my-tasks-page__kanban-wrap {
  flex: 1;
  overflow: hidden;
  padding: 0 $space-6 $space-4;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

.my-tasks-page__content {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  padding: $space-4 $space-6;
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

  :global(.app-dark) & {
    border-color: var(--p-surface-700);
  }
}
</style>
