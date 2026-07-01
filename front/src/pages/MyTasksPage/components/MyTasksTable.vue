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
        v-if="preset !== 'overdue' && preset !== 'completed'"
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
      :row-class="(data: ActivityDto) => ({
        'activity-table__row--selected': selectMode && selectedIds?.has(data.id),
        'activity-table__row--clickable': !selectMode && !editingCell,
      })"
      @page="(e: DataTablePageEvent) => $emit('page', e)"
      @row-click="onRowClick"
    >
      <!-- Select-mode checkbox column (visible only in selectMode) -->
      <Column v-if="selectMode" header="" style="width: 44px; flex-shrink: 0">
        <template #header>
          <div
            class="tasks-cell__select-all"
            :class="{
              'tasks-cell__select-all--checked': allSelected,
              'tasks-cell__select-all--indeterminate': someSelected,
            }"
            @click="onSelectAllClick"
          >
            <i v-if="allSelected" class="pi pi-check" />
            <span v-else-if="someSelected" class="tasks-cell__select-minus" />
          </div>
        </template>
        <template #body="{ data }">
          <div
            class="tasks-cell__row-select"
            :class="{ 'tasks-cell__row-select--checked': selectedIds?.has(data.id) }"
            @click.stop="$emit('toggleSelect', data.id)"
          >
            <i v-if="selectedIds?.has(data.id)" class="pi pi-check" />
          </div>
        </template>
      </Column>

      <!-- 1. Срок (inline DatePicker) -->
      <Column sortable :header="t('tasks.list.col.dueAt')" style="width: 160px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'due_at'"
            class="tasks-cell tasks-cell--clickable"
            :class="{ 'tasks-cell--overdue': data.is_overdue && !data.is_closed }"
            @click="startEdit(data, 'due_at')"
          >
            <div class="tasks-cell__due-wrap">
              <span v-if="data.due_at" class="tasks-cell__due-time" :class="{ 'tasks-cell__due-time--overdue': data.is_overdue && !data.is_closed }">
                {{ formatDueDate(data.due_at) }}
              </span>
              <span v-else class="tasks-cell__placeholder">{{ t('tasks.list.placeholder.date') }}</span>
              <Tag
                v-if="data.is_overdue && !data.is_closed"
                severity="danger"
                :value="t('activity.overdueBadge')"
                size="small"
              />
            </div>
            <i class="pi pi-pencil tasks-cell__edit-icon" />
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

      <!-- 2. Сделка / компания (read-only links) -->
      <Column sortable :header="t('tasks.list.col.dealCompany')" style="min-width: 160px">
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

      <!-- 3. Этап сделки (dot + name, read-only) -->
      <Column sortable :header="t('tasks.list.col.dealStage')" style="width: 150px">
        <template #body="{ data }">
          <div class="tasks-cell tasks-cell--readonly">
            <template v-if="data.deal?.stage">
              <span
                class="tasks-cell__stage-dot"
                :style="{ background: data.deal.stage.color ?? 'var(--p-surface-400)' }"
              />
              <span class="tasks-cell__stage-name">{{ data.deal.stage.name }}</span>
            </template>
            <span v-else class="tasks-cell__placeholder">—</span>
          </div>
        </template>
      </Column>

      <!-- 4. Тип задачи (inline Select) -->
      <Column sortable :header="t('tasks.list.col.kind')" style="width: 130px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'kind'"
            class="tasks-cell tasks-cell--clickable"
            @click="startEdit(data, 'kind')"
          >
            <span class="tasks-cell__kind-tag" :class="`tasks-cell__kind-tag--${data.kind}`">
              <i :class="kindIcon(data.kind)" class="tasks-cell__kind-icon" />
              {{ t(`activity.kinds.${data.kind}`) }}
            </span>
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

      <!-- 5. Текст задачи (inline InputText) -->
      <Column sortable :header="t('tasks.list.col.title')" style="min-width: 200px">
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
            <i v-if="data.is_pinned" class="pi pi-bookmark-fill tasks-cell__pin-icon" />
            <i v-if="data.priority === 'critical'" class="pi pi-flag-fill tasks-cell__critical-icon" />
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

      <!-- 6. Статус задачи — transition-gated dropdown (B32: must stay) -->
      <Column sortable :header="t('tasks.list.col.status')" style="width: 130px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'status'"
            class="tasks-cell tasks-cell--clickable"
            @click="startEdit(data, 'status')"
          >
            <span class="tasks-cell__status-pill" :class="`tasks-cell__status-pill--${data.status}`">
              {{ t(`activity.statuses.${data.status}`) }}
            </span>
            <i class="pi pi-pencil tasks-cell__edit-icon" />
          </div>
          <div v-else class="tasks-cell--editing">
            <Select
              v-model="editStatus"
              :options="statusOptionsFor(editingRowCurrentStatus ?? 'new')"
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

      <!-- 7. Ответственный (inline Select) -->
      <Column sortable :header="t('tasks.list.col.responsible')" style="width: 170px">
        <template #body="{ data }">
          <div
            v-if="editingCell?.id !== data.id || editingCell?.field !== 'responsible'"
            class="tasks-cell tasks-cell--clickable"
            @click="startEditResponsible(data)"
          >
            <span v-if="data.responsible" class="tasks-cell__responsible">
              <span class="tasks-cell__avatar">{{ (data.responsible.full_name?.charAt(0) ?? '?').toUpperCase() }}</span>
              {{ data.responsible.full_name ?? '—' }}
            </span>
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

      <!-- Actions column (⋮ context menu) -->
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
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import Select from 'primevue/select'
import InputText from 'primevue/inputtext'
import DatePicker from 'primevue/datepicker'
import { kindIcon, formatDueDate, localDateString } from '@/utils/activity'
import { activityApi, type ReschedulePreset } from '@/api/activity'
import { useUsersCache } from '@/composables/crm/useUsersCache'
import { getApiErrorMessage } from '@/utils/errors'
import type { ActivityDto, ActivityKind, ActivityStatus } from '@/entities/activity'
import { ACTIVITY_STATUS_TRANSITIONS } from '@/entities/activity'
import type { TaskPreset } from '../composables/useMyTasks'

// PrimeVue DataTable page event type
interface DataTablePageEvent {
  page: number
  rows: number
  first: number
  pageCount: number
}

const props = defineProps<{
  items: ActivityDto[]
  total: number
  loading: boolean
  perPage: number
  preset: TaskPreset
  completingId?: number | null
  reopeningId?: number | null
  selectMode?: boolean
  selectedIds?: Set<number>
  totalVisible?: number
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
  'toggleSelect': [id: number]
  'selectAll': []
  'clearSelection': []
  /** Open the task expanded dialog for a specific task */
  'openTask': [activity: ActivityDto]
}>()

const { t } = useI18n()
const toast = useToast()
const { users, loading: usersLoading, load: loadUsers } = useUsersCache()

// ── Select-mode ────────────────────────────────────────────────────────────────
const allSelected = computed(
  () => (props.totalVisible ?? 0) > 0 && (props.selectedIds?.size ?? 0) === (props.totalVisible ?? 0),
)
const someSelected = computed(
  () => (props.selectedIds?.size ?? 0) > 0 && (props.selectedIds?.size ?? 0) < (props.totalVisible ?? 0),
)

function onSelectAllClick() {
  if (allSelected.value) {
    emit('clearSelection')
  } else {
    emit('selectAll')
  }
}

// ── Inline edit state ─────────────────────────────────────────────────────────────

interface EditingCell {
  id: number
  field: 'due_at' | 'responsible' | 'kind' | 'status' | 'title'
}

const editingCell = ref<EditingCell | null>(null)
const patchingId = ref<number | null>(null)

const editDueAt = ref<Date | null>(null)
const editResponsibleId = ref<number | null>(null)
const editKind = ref<ActivityKind | null>(null)
const editStatus = ref<ActivityStatus | null>(null)
const editTitle = ref<string>('')
const editingRowCurrentStatus = ref<ActivityStatus | null>(null)

function startEdit(activity: ActivityDto, field: EditingCell['field']) {
  if (patchingId.value !== null) return
  editingCell.value = { id: activity.id, field }
  if (field === 'due_at') {
    editDueAt.value = activity.due_at ? new Date(activity.due_at) : null
  } else if (field === 'kind') {
    editKind.value = activity.kind
  } else if (field === 'status') {
    editStatus.value = activity.status
    editingRowCurrentStatus.value = activity.status
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
  editingRowCurrentStatus.value = null
}

// ── Patch helpers ─────────────────────────────────────────────────────────────────

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

async function patchStatus(activity: ActivityDto, newStatus: ActivityStatus) {
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

// ── Commit per field ──────────────────────────────────────────────────────────────

async function commitDueAt(activity: ActivityDto) {
  const isoDate: string | null = editDueAt.value ? localDateString(editDueAt.value) : null
  await patchActivity(activity, { ...activity, due_at: isoDate }, { due_at: isoDate })
}

async function rescheduleQuick(activity: ActivityDto, preset: ReschedulePreset) {
  if (patchingId.value !== null) return
  patchingId.value = activity.id
  try {
    const updated = await activityApi.rescheduleActivity(activity.id, { preset })
    emit('patched', updated)
    toast.add({ severity: 'success', summary: t('activity.reschedule.success'), life: 2000 })
  } catch (err) {
    emit('patched', activity)
    toast.add({
      severity: 'error',
      summary: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    patchingId.value = null
  }
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
  await patchActivity(activity, { ...activity, kind: editKind.value }, { kind: editKind.value })
}

async function commitStatus(activity: ActivityDto) {
  if (!editStatus.value) return cancelEdit()
  await patchStatus(activity, editStatus.value)
}

async function commitTitle(activity: ActivityDto) {
  const title = editTitle.value.trim()
  if (!title) return cancelEdit()
  await patchActivity(activity, { ...activity, title }, { title })
}

function onTitleKeydown(e: KeyboardEvent, activity: ActivityDto) {
  if (e.key === 'Enter') {
    e.preventDefault()
    void commitTitle(activity)
  } else if (e.key === 'Escape') {
    cancelEdit()
  }
}

// ── Options ───────────────────────────────────────────────────────────────────────

const kindOptions = computed<Array<{ label: string; value: ActivityKind }>>(() => [
  { label: t('activity.kinds.call'), value: 'call' },
  { label: t('activity.kinds.meeting'), value: 'meeting' },
  { label: t('activity.kinds.task'), value: 'task' },
  { label: t('activity.kinds.note'), value: 'note' },
  { label: t('activity.kinds.follow_up'), value: 'follow_up' },
])

/**
 * Returns allowed status transitions mirroring ActivityStatus::allowedTransitions().
 * Always includes the current status itself (idempotent no-op).
 */
function statusOptionsFor(current: ActivityStatus): Array<{ label: string; value: ActivityStatus }> {
  const targets: ActivityStatus[] = [current, ...ACTIVITY_STATUS_TRANSITIONS[current]]
  return targets.map((s) => ({ label: t(`activity.statuses.${s}`), value: s }))
}

// ── Row click → open task dialog ──────────────────────────────────────────────────

function onRowClick(event: { data: ActivityDto }) {
  // Don't open dialog if we're in selectMode or editing a cell
  if (props.selectMode || editingCell.value) return
  emit('openTask', event.data)
}

// ── Context menu ──────────────────────────────────────────────────────────────────

const menuRef = ref<InstanceType<typeof Menu> | null>(null)
const menuActivity = ref<ActivityDto | null>(null)

const menuItems = computed(() => {
  const a = menuActivity.value
  if (!a) return []
  const items = [
    {
      label: t('tasks.window.title'),
      icon: 'pi pi-expand',
      command: () => emit('openTask', a),
    },
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
  if (a.kind !== 'note' && a.status !== 'done') {
    items.push(
      {
        label: t('activity.reschedule.plus1d'),
        icon: 'pi pi-angle-right',
        command: () => void rescheduleQuick(a, '+1d'),
      },
      {
        label: t('activity.reschedule.plus1w'),
        icon: 'pi pi-angle-double-right',
        command: () => void rescheduleQuick(a, '+1w'),
      },
      {
        label: t('activity.reschedule.pickDate'),
        icon: 'pi pi-calendar',
        command: () => startEdit(a, 'due_at'),
      },
    )
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

// ── Bootstrap ─────────────────────────────────────────────────────────────────────

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

// ── Select-mode header checkbox ───────────────────────────────────────────────

.tasks-cell__select-all {
  width: 17px;
  height: 17px;
  border-radius: $radius-xs;
  border: 1px solid $surface-400;
  background: $surface-card;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-500);
    background: var(--p-surface-700);
  }

  &--checked {
    background: $primary-900;
    border-color: $primary-900;
    color: $surface-0;

    .pi {
      font-size: $font-size-3xs;
    }
  }

  &--indeterminate {
    border-color: $primary-900;
  }
}

.tasks-cell__select-minus {
  width: 9px;
  height: 2px;
  background: $primary-900;
  border-radius: $radius-2xs; // 2px — snap from 1px
}

.tasks-cell__row-select {
  width: 17px;
  height: 17px;
  border-radius: $radius-xs;
  border: 1px solid $surface-300;
  background: $surface-card;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-500);
    background: var(--p-surface-700);
  }

  &--checked {
    background: $primary-900;
    border-color: $primary-900;
    color: $surface-0;

    .pi {
      font-size: $font-size-3xs;
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
    padding: $space-1;
    border-radius: $radius-sm;
    border: 1px solid transparent;
    cursor: pointer;
    transition: border-color var(--app-transition-fast), background-color var(--app-transition-fast);

    &:hover {
      border-color: $surface-300;
      background-color: $surface-50;

      .app-dark & {
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

  &__title {
    font-size: $font-size-sm;
    word-break: break-word;
  }

  &__pin-icon {
    font-size: $font-size-xs;
    color: $primary-900;
    flex-shrink: 0;
  }

  &__critical-icon {
    font-size: $font-size-xs;
    color: $color-danger;
    flex-shrink: 0;
  }

  // Deal context links
  &__link {
    color: $primary-color;
    text-decoration: none;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;

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
    width: 7px;
    height: 7px;
    border-radius: $radius-circle;
    flex-shrink: 0;
  }

  &__stage-name {
    font-size: $font-size-sm;
    color: $surface-700;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }

  // Kind tag in list view
  &__kind-tag {
    display: inline-flex;
    align-items: center;
    gap: 3px;
    border-radius: $radius-sm;
    padding: 2px 9px;
    font-size: $font-size-xs;
    font-weight: $font-weight-medium;

    &--task {
      background: $surface-100;
      color: $surface-600;

      .app-dark & {
        background: var(--p-surface-200);
        color: var(--p-surface-400);
      }
    }

    &--call {
      background: $task-tag-call-bg;
      color: $task-tag-call-text;

      .app-dark & {
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        background: color-mix(in srgb, #2A6FDB 18%, #444547);
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        color: color-mix(in srgb, white 55%, #2A6FDB);
      }
    }

    &--meeting {
      background: $task-tag-meeting-bg;
      color: $task-tag-meeting-text;

      .app-dark & {
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        background: color-mix(in srgb, #1F8A5B 18%, #444547);
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        color: color-mix(in srgb, white 55%, #1F8A5B);
      }
    }

    &--note {
      background: $task-tag-note-bg;
      color: $task-tag-note-text;

      .app-dark & {
        background: var(--p-surface-200);
        color: var(--p-surface-400);
      }
    }

    &--follow_up {
      background: $task-tag-follow-up-bg;
      color: $task-tag-follow-up-text;

      .app-dark & {
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        background: color-mix(in srgb, #E8A317 18%, #444547);
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        color: color-mix(in srgb, white 55%, #E8A317);
      }
    }

    &--presentation {
      background: $task-tag-follow-up-bg;
      color: $task-tag-follow-up-text;

      .app-dark & {
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        background: color-mix(in srgb, #E8A317 18%, #444547);
        // stylelint-disable-next-line scale-unlimited/declaration-strict-value
        color: color-mix(in srgb, white 55%, #E8A317);
      }
    }
  }

  &__kind-icon {
    font-size: $font-size-xs;
  }

  // Due date cell
  &__due-wrap {
    display: flex;
    flex-direction: column;
    gap: 2px;
  }

  &__due-time {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;

    &--overdue {
      color: var(--p-red-600);
    }
  }

  // Status pill
  &__status-pill {
    display: inline-flex;
    align-items: center;
    border-radius: $radius-sm;
    padding: 3px $space-2;
    font-size: $font-size-xs;
    font-weight: $font-weight-semibold;
    white-space: nowrap;

    &--new {
      background: var(--p-blue-100);
      color: var(--p-blue-700);

      .app-dark & {
        background: var(--p-blue-900);
        color: var(--p-blue-200);
      }
    }

    &--in_progress {
      background: $color-warning-bg;
      color: $color-warning-text;

      .app-dark & {
        background: var(--p-yellow-900);
        color: var(--p-yellow-200);
      }
    }

    &--done {
      background: $task-tag-meeting-bg;
      color: $task-tag-meeting-text;

      .app-dark & {
        background: var(--p-green-900);
        color: var(--p-green-200);
      }
    }

    &--rejected {
      background: $surface-100;
      color: $surface-500;

      .app-dark & {
        background: var(--p-surface-700);
        color: var(--p-surface-400);
      }
    }
  }

  // Responsible
  &__responsible {
    display: flex;
    align-items: center;
    gap: $space-1;
    font-size: $font-size-sm;
  }

  &__avatar {
    width: 22px;
    height: 22px;
    border-radius: $radius-circle;
    background: $primary-900;
    color: $surface-0;
    font-size: $font-size-3xs;
    font-weight: $font-weight-bold;
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
  }
}

// ── Selected row ──────────────────────────────────────────────────────────────
:deep(.activity-table__row--selected td) {
  background: $primary-100;

  .app-dark & {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(23, 39, 71, 0.2);
  }
}
</style>
