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
      <Column header="" style="width: 40px">
        <template #body="{ data }">
          <Checkbox
            :model-value="data.status === 'done'"
            binary
            :disabled="completingId === data.id || reopeningId === data.id"
            @change="onCheckboxChange(data)"
          />
        </template>
      </Column>

      <!-- Kind -->
      <Column :header="t('activity.form.kind')" style="width: 50px">
        <template #body="{ data }">
          <i
            :class="kindIcon(data.kind)"
            class="activity-table__kind-icon"
            :title="t(`activity.kinds.${data.kind}`)"
          />
        </template>
      </Column>

      <!-- Title -->
      <Column :header="t('activity.form.title')" style="min-width: 200px">
        <template #body="{ data }">
          <span
            class="activity-table__title"
            :class="{ 'activity-table__title--done': data.status === 'done' }"
            role="button"
            tabindex="0"
            @click="$emit('edit', data)"
            @keydown.enter="$emit('edit', data)"
          >
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
        </template>
      </Column>

      <!-- Assignee -->
      <Column :header="t('activity.form.responsible')" style="width: 140px">
        <template #body="{ data }">
          {{ data.responsible?.full_name ?? '—' }}
        </template>
      </Column>

      <!-- Due date -->
      <Column :header="t('activity.form.dueAt')" style="width: 160px">
        <template #body="{ data }">
          <span
            class="activity-table__due"
            :class="{ 'activity-table__due--overdue': data.is_overdue && !data.is_closed }"
          >
            {{ data.due_at ? formatDueDate(data.due_at) : '—' }}
          </span>
          <Tag
            v-if="data.is_overdue && !data.is_closed"
            severity="danger"
            :value="t('activity.overdueBadge')"
            size="small"
            class="ms-1"
          />
        </template>
      </Column>

      <!-- Priority -->
      <Column :header="t('activity.form.priority')" style="width: 110px">
        <template #body="{ data }">
          <Tag
            :severity="prioritySeverity(data.priority)"
            :value="t(`activity.priorities.${data.priority}`)"
            size="small"
          />
        </template>
      </Column>

      <!-- Status -->
      <Column :header="t('activity.myTasksPage.filters.status')" style="width: 120px">
        <template #body="{ data }">
          <Tag
            :severity="statusSeverity(data.status)"
            :value="t(`activity.statuses.${data.status}`)"
            size="small"
          />
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
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import DataTable from 'primevue/datatable'
import Column from 'primevue/column'
import Checkbox from 'primevue/checkbox'
import Tag from 'primevue/tag'
import Skeleton from 'primevue/skeleton'
import Button from 'primevue/button'
import Menu from 'primevue/menu'
import { kindIcon, statusSeverity, prioritySeverity, formatDueDate } from '@/utils/activity'
import type { ActivityDto } from '@/entities/activity'
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
}>()

const { t } = useI18n()

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
    font-size: 3rem;
    color: $surface-400;

    &--success {
      color: var(--p-green-500);
    }
  }
}

.activity-table {
  &__kind-icon {
    font-size: $font-size-sm;
    color: $surface-500;
  }

  &__title {
    font-size: $font-size-sm;
    cursor: pointer;
    color: $surface-800;

    :global(.app-dark) & {
      color: var(--p-surface-100);
    }

    &:hover {
      color: $primary-color;
      text-decoration: underline;
    }

    &--done {
      color: $surface-400;
      text-decoration: line-through;

      :global(.app-dark) & {
        color: var(--p-surface-500);
      }

      &:hover {
        text-decoration: line-through;
      }
    }
  }

  &__due {
    font-size: $font-size-xs;

    &--overdue {
      color: var(--p-red-500);
      font-weight: $font-weight-medium;
    }
  }
}
</style>
