<template>
  <div class="tasks-table-wrap">
    <!-- Skeleton loading -->
    <div v-if="loading && items.length === 0" class="tasks-table-wrap__skeleton">
      <Skeleton v-for="i in 6" :key="i" height="40px" class="mb-2" />
    </div>

    <!-- Empty: overdue all done -->
    <div
      v-else-if="!loading && items.length === 0 && preset === 'overdue'"
      class="tasks-table-wrap__empty"
    >
      <i class="pi pi-check-circle tasks-table-wrap__empty-icon tasks-table-wrap__empty-icon--success" />
      <p>{{ t('activity.myTasksPage.emptyOverdue') }}</p>
    </div>

    <!-- Empty state -->
    <div v-else-if="!loading && items.length === 0" class="tasks-table-wrap__empty">
      <i class="pi pi-check-square tasks-table-wrap__empty-icon" />
      <p>{{ t('activity.myTasksPage.empty') }}</p>
      <Button
        v-if="preset !== 'overdue'"
        icon="pi pi-plus"
        :label="t('activity.myTasksPage.create')"
        severity="secondary"
        outlined
        size="small"
        @click="$emit('create')"
      />
    </div>

    <!-- DataTable -->
    <DataTable
      v-else
      :value="items"
      lazy
      paginator
      :rows="perPage"
      :total-records="total"
      :rows-per-page-options="[25, 50, 100]"
      paginator-template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown"
      row-hover
      class="activity-table"
      @page="(e: DataTablePageEvent) => $emit('page', e)"
    >
      <!-- Checkbox complete -->
      <Column header="" style="width: 40px; flex-shrink: 0">
        <template #body="{ data }">
          <Checkbox
            :model-value="data.status === 'done'"
            binary
            :disabled="patchingId === data.id || completingId === data.id || reopeningId === data.id"
            @change="onCheckboxChange(data)"
          />
        </template>
      </Column>

      <!-- Дата исполнения (inline DatePicker) -->
      <Column :header="t('tasks.list.col.dueAt')" style="width: 160px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'due_at'"
            class="tasks-cell tasks-cell--clickable"
            :class="{ 'tasks-cell--overdue': data.is_overdue && !data.is_closed }"
            @click="startEdit(data, 'due_at')"
          >
            <span v-if="data.due_at">{{ formatDueDate(data.due_at) }}</span>
            <span v-else class="tasks-cell__placeholder">{{ t('tasks.list.placeholder.date') }}</span>
            <i class="pi pi-pencil tasks-cell__edit-icon" />
            <Tag
              v-if="data.is_overdue && !data.is_closed"
              severity="danger"
              :value="t('activity.overdueBadge')"
              size="small"
              class="ms-1"
            />
          </div>
          <div v-else class="tasks-cell--editing">
            <DatePicker
              v-model="editDueAt"
              date-format="dd.mm.yy"
              :show-time="false"
              show-button-bar
              :placeholder="t('tasks.list.placeholder.date')"
              class="tasks-cell__input"
              auto-focus
              @date-select="commitDueAt(data)"
              @keydown.escape="cancelEdit"
            />
            <Button
              icon="pi pi-check"
              size="small"
              :loading="patchingId === data.id"
              class="tasks-cell__btn-save"
              @click="commitDueAt(data)"
            />
            <Button
              icon="pi pi-times"
              size="small"
              severity="secondary"
              text
              @click="cancelEdit"
            />
          </div>
        </template>
      </Column>

      <!-- Ответственный (inline Select) -->
      <Column :header="t('tasks.list.col.responsible')" style="width: 160px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'responsible'"
            class="tasks-cell tasks-cell--clickable"
            @click="startEditResponsible(data)"
          >
            <span v-if="data.responsible">{{ data.responsible.full_name }}</span>
            <span v-else class="tasks-cell__placeholder">{{ t('tasks.list.placeholder.responsible') }}</span>
            <i class="pi pi-pencil tasks-cell__edit-icon" />
          </div>
          <div v-else class="tasks-cell--editing">
            <Select
              v-model="editResponsibleId"
              :options="users"
              option-label="full_name"
              option-value="id"
              :loading="usersLoading"
              show-clear
              filter
              :placeholder="t('tasks.list.placeholder.responsible')"
              class="tasks-cell__input"
            />
            <Button
              icon="pi pi-check"
              size="small"
              :loading="patchingId === data.id"
              class="tasks-cell__btn-save"
              @click="commitResponsible(data)"
            />
            <Button
              icon="pi pi-times"
              size="small"
              severity="secondary"
              text
              @click="cancelEdit"
            />
          </div>
        </template>
      </Column>

      <!-- Компания / Сделка (read-only links) -->
      <Column :header="t('tasks.list.col.dealCompany')" style="min-width: 160px">
        <template #body="{ data }">
          <div class="tasks-cell tasks-cell--readonly">
            <template v-if="data.deal">
              <RouterLink
                v-if="data.deal.company"
                :to="{ name: 'CompanyDetail', params: { id: data.deal.company.id } }"
                class="tasks-cell__link"
              >
                {{ data.deal.company.name }}
              </RouterLink>
              <span v-if="data.deal.company && data.deal.title" class="tasks-cell__sep">/</span>
              <RouterLink
                :to="{ name: 'DealDetail', params: { id: data.deal.id } }"
                class="tasks-cell__link"
              >
                {{ data.deal.title }}
              </RouterLink>
            </template>
            <span v-else class="tasks-cell__placeholder">—</span>
          </div>
        </template>
      </Column>

      <!-- Тип задачи (inline Select) -->
      <Column :header="t('tasks.list.col.kind')" style="width: 130px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'kind'"
            class="tasks-cell tasks-cell--clickable"
            @click="startEdit(data, 'kind')"
          >
            <i :class="kindIcon(data.kind)" class="tasks-cell__kind-icon" />
            <span>{{ t(`activity.kinds.${data.kind}`) }}</span>
            <i class="pi pi-pencil tasks-cell__edit-icon" />
          </div>
          <div v-else class="tasks-cell--editing">
            <Select
              v-model="editKind"
              :options="kindOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('tasks.list.placeholder.kind')"
              class="tasks-cell__input"
            />
            <Button
              icon="pi pi-check"
              size="small"
              :loading="patchingId === data.id"
              class="tasks-cell__btn-save"
              @click="commitKind(data)"
            />
            <Button
              icon="pi pi-times"
              size="small"
              severity="secondary"
              text
              @click="cancelEdit"
            />
          </div>
        </template>
      </Column>

      <!-- Статус сделки (read-only, from deal context) -->
      <Column :header="t('tasks.list.col.dealStage')" style="width: 150px">
        <template #body="{ data }">
          <div class="tasks-cell tasks-cell--readonly">
            <template v-if="data.deal?.stage">
              <span
                class="tasks-cell__stage-dot"
                :style="{ background: data.deal.stage.color ?? '#94a3b8' }"
              />
              <span class="tasks-cell__stage-name">{{ data.deal.stage.name }}</span>
              <i
                v-if="data.deal.stage.is_won"
                class="pi pi-check-circle tasks-cell__stage-badge tasks-cell__stage-badge--won"
                :title="t('tasks.list.stageWon')"
              />
              <i
                v-else-if="data.deal.stage.is_lost"
                class="pi pi-times-circle tasks-cell__stage-badge tasks-cell__stage-badge--lost"
                :title="t('tasks.list.stageLost')"
              />
            </template>
            <span v-else class="tasks-cell__placeholder">—</span>
          </div>
        </template>
      </Column>

      <!-- Статус задачи (inline select/toggle via /status endpoint) -->
      <Column :header="t('tasks.list.col.status')" style="width: 130px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'status'"
            class="tasks-cell tasks-cell--clickable"
            @click="startEdit(data, 'status')"
          >
            <Tag
              :severity="statusSeverity(data.status)"
              :value="t(`activity.statuses.${data.status}`)"
              size="small"
              :pt="{ root: { class: `task-status-tag--${data.status}` } }"
            />
            <i class="pi pi-pencil tasks-cell__edit-icon" />
          </div>
          <div v-else class="tasks-cell--editing">
            <Select
              v-model="editStatus"
              :options="statusOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('tasks.list.placeholder.status')"
              class="tasks-cell__input"
            />
            <Button
              icon="pi pi-check"
              size="small"
              :loading="patchingId === data.id"
              class="tasks-cell__btn-save"
              @click="commitStatus(data)"
            />
            <Button
              icon="pi pi-times"
              size="small"
              severity="secondary"
              text
              @click="cancelEdit"
            />
          </div>
        </template>
      </Column>

      <!-- Текст задачи (inline InputText) -->
      <Column :header="t('tasks.list.col.title')" style="min-width: 200px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'title'"
            class="tasks-cell tasks-cell--clickable"
            :class="{ 'tasks-cell--done': data.status === 'done' }"
            @click="startEdit(data, 'title')"
          >
            <span class="tasks-cell__title">
              <s v-if="data.status === 'done'">{{ data.title }}</s>
              <template v-else>{{ data.title }}</template>
            </span>
            <Tag
              v-if="data.is_pinned"
              icon="pi pi-bookmark-fill"
              severity="secondary"
              size="small"
              class="ms-1"
            />
            <i class="pi pi-pencil tasks-cell__edit-icon" />
          </div>
          <div v-else class="tasks-cell--editing">
            <InputText
              v-model="editTitle"
              :placeholder="t('tasks.list.placeholder.title')"
              class="tasks-cell__input"
              @keydown="onTitleKeydown($event, data)"
            />
            <Button
              icon="pi pi-check"
              size="small"
              :loading="patchingId === data.id"
              class="tasks-cell__btn-save"
              @click="commitTitle(data)"
            />
            <Button
              icon="pi pi-times"
              size="small"
              severity="secondary"
              text
              @click="cancelEdit"
            />
          </div>
        </template>
      </Column>

      <!-- Actions -->
      <Column style="width: 50px">
        <template #body="{ data }">
          <Button
            icon="pi pi-ellipsis-v"
            text
            size="small"
            severity="secondary"
            @click="toggleMenu($event, data)"
          />
        </template>
      </Column>
    </DataTable>

    <!-- Context menu -->
    <Menu ref="menuRef" :model="menuItems" popup />
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { RouterLink } from 'vue-router'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import DatePicker from 'primevue/datepicker'
import { kindIcon, statusSeverity, formatDueDate } from '@/utils/activity'
import { activityApi } from '@/api/activity'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import { getApiErrorMessage } from '@/utils/errors'
import type { ActivityDto, ActivityKind, ActivityStatus } from '@/entities/activity'
import type { TaskPreset } from '../composables/useMyTasks'

// PrimeVue DataTable page event type
interface DataTablePageEvent {
  page: number
  rows: number
  first: number
  pageCount: number
}

defineProps<{
  items: ActivityDto[]
  total: number
  loading: boolean
  perPage: number
  preset: TaskPreset
  completingId?: number | null
  reopeningId?: number | null
}>()

const emit = defineEmits<{
  'page': [event: DataTablePageEvent]
  'complete': [activity: ActivityDto]
  'reopen': [activity: ActivityDto]
  'edit': [activity: ActivityDto]
  'pin': [activity: ActivityDto]
  'delete': [activity: ActivityDto]
  'create': []
  'patched': [activity: ActivityDto]
}>()

const { t } = useI18n()
const toast = useToast()
const { users, loading: usersLoading, load: loadUsers } = useUsersCache()

// ── Inline edit state ─────────────────────────────────────────────────────────

interface EditingCell {
  id: number
  field: 'due_at' | 'responsible' | 'kind' | 'status' | 'title'
}

const editingCell = ref<EditingCell | null>(null)
const patchingId = ref<number | null>(null)

// Per-field local values while editing
const editDueAt = ref<Date | null>(null)
const editResponsibleId = ref<number | null>(null)
const editKind = ref<ActivityKind | null>(null)
const editStatus = ref<ActivityStatus | null>(null)
const editTitle = ref<string>('')

function startEdit(activity: ActivityDto, field: EditingCell['field']) {
  if (patchingId.value !== null) return
  editingCell.value = { id: activity.id, field }
  if (field === 'due_at') {
    editDueAt.value = activity.due_at ? new Date(activity.due_at) : null
  } else if (field === 'kind') {
    editKind.value = activity.kind
  } else if (field === 'status') {
    editStatus.value = activity.status
  } else if (field === 'title') {
    editTitle.value = activity.title
  }
}

function startEditResponsible(activity: ActivityDto) {
  if (patchingId.value !== null) return
  editingCell.value = { id: activity.id, field: 'responsible' }
  editResponsibleId.value = activity.responsible?.id ?? null
  void loadUsers()
}

function cancelEdit() {
  editingCell.value = null
}

// ── Patch helpers ─────────────────────────────────────────────────────────────

async function patchActivity(
  activity: ActivityDto,
  optimistic: ActivityDto,
  payload: Parameters<typeof activityApi.updateActivity>[1],
) {
  patchingId.value = activity.id
  emit('patched', optimistic)
  try {
    const updated = await activityApi.updateActivity(activity.id, payload)
    emit('patched', updated)
    toast.add({ severity: 'success', summary: t('tasks.list.patchSuccess'), life: 2000 })
  } catch (err) {
    emit('patched', activity) // rollback
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    patchingId.value = null
    cancelEdit()
  }
}

async function patchStatus(
  activity: ActivityDto,
  newStatus: ActivityStatus,
) {
  patchingId.value = activity.id
  const optimistic: ActivityDto = {
    ...activity,
    status: newStatus,
    is_closed: newStatus === 'done' || newStatus === 'rejected',
  }
  emit('patched', optimistic)
  try {
    const updated = await activityApi.changeStatus(activity.id, newStatus)
    emit('patched', updated)
    toast.add({ severity: 'success', summary: t('tasks.list.patchSuccess'), life: 2000 })
  } catch (err) {
    emit('patched', activity) // rollback
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    patchingId.value = null
    cancelEdit()
  }
}

// ── Commit per field ──────────────────────────────────────────────────────────

async function commitDueAt(activity: ActivityDto) {
  const isoDate: string | null = editDueAt.value
    ? (editDueAt.value.toISOString().split('T')[0] ?? null)
    : null
  await patchActivity(
    activity,
    { ...activity, due_at: isoDate },
    { due_at: isoDate },
  )
}

async function commitResponsible(activity: ActivityDto) {
  const userId = editResponsibleId.value
  const user = userId !== null ? users.value.find((u) => u.id === userId) ?? null : null
  const optimisticResponsible = user
    ? { id: user.id, full_name: user.full_name, avatar_path: user.avatar_path ?? null }
    : null
  await patchActivity(
    activity,
    { ...activity, responsible: optimisticResponsible },
    { responsible_id: userId },
  )
}

async function commitKind(activity: ActivityDto) {
  if (!editKind.value) return cancelEdit()
  await patchActivity(
    activity,
    { ...activity, kind: editKind.value },
    { kind: editKind.value },
  )
}

async function commitStatus(activity: ActivityDto) {
  if (!editStatus.value) return cancelEdit()
  await patchStatus(activity, editStatus.value)
}

async function commitTitle(activity: ActivityDto) {
  const title = editTitle.value.trim()
  if (!title) return cancelEdit()
  await patchActivity(
    activity,
    { ...activity, title },
    { title },
  )
}

function onTitleKeydown(e: KeyboardEvent, activity: ActivityDto) {
  if (e.key === 'Enter') {
    e.preventDefault()
    void commitTitle(activity)
  } else if (e.key === 'Escape') {
    cancelEdit()
  }
}

// ── Options ───────────────────────────────────────────────────────────────────

const kindOptions = computed<Array<{ label: string; value: ActivityKind }>>(() => [
  { label: t('activity.kinds.call'), value: 'call' },
  { label: t('activity.kinds.meeting'), value: 'meeting' },
  { label: t('activity.kinds.task'), value: 'task' },
  { label: t('activity.kinds.note'), value: 'note' },
  { label: t('activity.kinds.follow_up'), value: 'follow_up' },
])

const statusOptions = computed<Array<{ label: string; value: ActivityStatus }>>(() => [
  { label: t('activity.statuses.new'), value: 'new' },
  { label: t('activity.statuses.in_progress'), value: 'in_progress' },
  { label: t('activity.statuses.done'), value: 'done' },
  { label: t('activity.statuses.rejected'), value: 'rejected' },
])

// ── Context menu ──────────────────────────────────────────────────────────────

const menuRef = ref<InstanceType<typeof Menu> | null>(null)
const menuActivity = ref<ActivityDto | null>(null)

const menuItems = computed(() => {
  const a = menuActivity.value
  if (!a) return []
  const items = [
    {
      label: t('activity.actions.edit'),
      icon: 'pi pi-pencil',
      command: () => emit('edit', a),
    },
  ]
  if (a.status === 'done') {
    items.push({
      label: t('activity.actions.reopen'),
      icon: 'pi pi-refresh',
      command: () => emit('reopen', a),
    })
  }
  items.push(
    {
      label: a.is_pinned ? t('activity.actions.unpin') : t('activity.actions.pin'),
      icon: a.is_pinned ? 'pi pi-bookmark' : 'pi pi-bookmark-fill',
      command: () => emit('pin', a),
    },
    {
      label: t('activity.actions.delete'),
      icon: 'pi pi-trash',
      command: () => emit('delete', a),
    },
  )
  return items
})

function toggleMenu(event: MouseEvent, activity: ActivityDto) {
  menuActivity.value = activity
  menuRef.value?.toggle(event)
}

function onCheckboxChange(activity: ActivityDto) {
  if (activity.status === 'done') {
    emit('reopen', activity)
  } else {
    emit('complete', activity)
  }
}

// ── Bootstrap ─────────────────────────────────────────────────────────────────

onMounted(() => {
  void loadUsers()
})
</script>

<style lang="scss" scoped>
.tasks-table-wrap {
  &__skeleton {
    display: flex;
    flex-direction: column;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-3;
    padding: $space-8;
    text-align: center;
    color: $surface-500;
    font-size: $font-size-sm;

    p {
      margin: 0;
    }
  }

  &__empty-icon {
    font-size: $font-size-icon-lg;
    color: $surface-400;

    &--success {
      color: var(--p-green-500);
    }
  }
}

// ── Table cells ───────────────────────────────────────────────────────────────

.tasks-cell {
  display: flex;
  align-items: center;
  gap: $space-1;
  min-height: 32px;
  font-size: $font-size-sm;

  &--clickable {
    padding: $space-1 $space-1;
    border-radius: $radius-sm;
    border: 1px solid transparent;
    cursor: pointer;
    transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast);

    &:hover {
      border-color: $surface-300;
      background-color: $surface-50;

      :global(.app-dark) & {
        border-color: var(--p-surface-600);
        background-color: var(--p-surface-800);
      }

      .tasks-cell__edit-icon {
        opacity: 1;
      }
    }
  }

  &--editing {
    display: flex;
    align-items: center;
    gap: $space-1;
    flex-wrap: nowrap;
  }

  &--readonly {
    cursor: default;
    padding: $space-1;
  }

  &--overdue {
    color: var(--p-red-600);
    font-weight: $font-weight-medium;
  }

  &--done {
    .tasks-cell__title {
      color: $surface-400;
    }
  }

  &__edit-icon {
    font-size: $font-size-xs;
    color: $surface-400;
    opacity: 0;
    flex-shrink: 0;
    transition: opacity var(--app-transition-fast);
  }

  &__placeholder {
    color: $surface-400;
    font-style: italic;
  }

  &__input {
    min-width: 120px;
    max-width: 200px;
    font-size: $font-size-sm;
  }

  &__btn-save {
    flex-shrink: 0;
  }

  &__kind-icon {
    font-size: $font-size-sm;
    color: $surface-500;
  }

  &__title {
    font-size: $font-size-sm;
    word-break: break-word;
  }

  // Deal context links
  &__link {
    color: $primary-color;
    text-decoration: none;
    font-size: $font-size-sm;

    &:hover {
      text-decoration: underline;
    }
  }

  &__sep {
    color: $surface-400;
    margin: 0 2px;
    font-size: $font-size-xs;
  }

  // Stage dot + name
  &__stage-dot {
    width: 8px;
    height: 8px;
    border-radius: $radius-circle;
    flex-shrink: 0;
  }


  &__stage-name {
    font-size: $font-size-sm;
    color: $surface-700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    :global(.app-dark) & {
      color: var(--p-surface-200);
    }
  }

  &__stage-badge {
    font-size: $font-size-xs;
    flex-shrink: 0;

    &--won {
      color: var(--p-green-500);
    }

    &--lost {
      color: var(--p-red-500);
    }
  }
}

// ── Status tag dark overrides ─────────────────────────────────────────────────
// PrimeVue Tag severity=info in dark can have low contrast — boost it per-status
:global(.app-dark) .task-status-tag--new {
  background: var(--p-blue-900);
  color: var(--p-blue-200);
  border-color: transparent;
}

:global(.app-dark) .task-status-tag--in_progress {
  background: var(--p-yellow-900);
  color: var(--p-yellow-200);
  border-color: transparent;
}
</style>
