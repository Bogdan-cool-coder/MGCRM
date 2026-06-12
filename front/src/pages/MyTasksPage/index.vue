<template>
  <div class="my-tasks-page">
    <PageHeader
      :title="t('activity.myTasksPage.title')"
      icon="pi pi-check-square"
    >
      <template #actions>
        <Button
          icon="pi pi-plus"
          :label="t('activity.myTasksPage.create')"
          severity="primary"
          @click="onCreateTask"
        />
      </template>
    </PageHeader>

    <div class="my-tasks-page__content">
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
  // Optimistic
  updateLocal({ ...activity, status: 'done', is_closed: true })
  try {
    const updated = await activityApi.completeActivity(activity.id)
    updateLocal(updated)
    toast.add({ severity: 'success', summary: t('activity.actions.completeSuccess'), life: 3000 })
    void refreshCounts()
  } catch (err) {
    // Rollback
    updateLocal(activity)
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
  updateLocal({ ...activity, status: 'in_progress', is_closed: false })
  try {
    const updated = await activityApi.reopenActivity(activity.id)
    updateLocal(updated)
    toast.add({ severity: 'success', summary: t('activity.actions.reopenSuccess'), life: 3000 })
    void refreshCounts()
  } catch (err) {
    updateLocal(activity)
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

onMounted(async () => {
  await Promise.all([load(), refreshCounts()])
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

.my-tasks-page__content {
  flex: 1;
  overflow-y: auto;
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
