<template>
  <!--
    OpenTasksList — compact list of open (non-closed) tasks shown above the composer.
    AMO-style: each task = narrow row with kind icon + title + due date + "complete" action.
    Clicking "complete" expands inline TaskQuickForm mode="complete" in place.
  -->
  <div v-if="tasks.length > 0" class="open-tasks">
    <div class="open-tasks__header">
      <i class="pi pi-list-check open-tasks__header-icon" />
      <span class="open-tasks__header-label">
        {{ t('sales.deal.feed.openTasks.title', { n: tasks.length }) }}
      </span>
    </div>

    <div class="open-tasks__list">
      <div
        v-for="task in tasks"
        :key="task.id"
        class="open-tasks__row"
        :class="{
          'open-tasks__row--overdue': task.is_overdue && !task.is_closed,
          'open-tasks__row--expanded': expandedId === task.id,
        }"
      >
        <!-- Compact row (shown when not expanded) -->
        <div
          v-if="expandedId !== task.id"
          class="open-tasks__compact"
        >
          <!-- Kind icon -->
          <i :class="['pi', kindIcon(task.kind), 'open-tasks__kind-icon']" />

          <!-- Title -->
          <span class="open-tasks__title text-truncate">{{ task.title }}</span>

          <!-- Due date -->
          <span
            v-if="task.due_at"
            class="open-tasks__due"
            :class="{ 'open-tasks__due--overdue': task.is_overdue }"
          >
            <i class="pi pi-clock open-tasks__due-icon" />
            {{ formatDueDate(task.due_at) }}
          </span>

          <!-- Responsible -->
          <span v-if="task.responsible" class="open-tasks__responsible">
            {{ task.responsible.full_name }}
          </span>

          <!-- Actions: Complete button -->
          <button
            type="button"
            class="open-tasks__complete-btn"
            :title="t('activity.actions.complete')"
            @click="expandedId = task.id"
          >
            <i class="pi pi-check" />
            <span class="open-tasks__complete-label">{{ t('activity.actions.complete') }}</span>
          </button>
        </div>

        <!-- Expanded: inline TaskQuickForm (complete mode) -->
        <Transition name="tqf-slide">
          <TaskQuickForm
            v-if="expandedId === task.id"
            mode="complete"
            :activity="task"
            :closable="true"
            :target-type="targetType"
            :target-id="targetId"
            class="open-tasks__tqf"
            @completed="onCompleted(task.id, $event)"
            @delete="onDelete(task.id)"
            @cancel="expandedId = null"
          />
        </Transition>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useConfirm } from 'primevue/useconfirm'
import { useToast } from 'primevue/usetoast'
import TaskQuickForm from '@/components/tasks/TaskQuickForm.vue'
import { kindIcon, formatDueDate } from '@/utils/activity'
import type { ActivityDto, ActivityTargetType } from '@/entities/activity'

// ─── Props / emits ────────────────────────────────────────────────────────────

defineProps<{
  tasks: ActivityDto[]
  targetType: ActivityTargetType
  targetId: number
}>()

const emit = defineEmits<{
  completed: [activity: ActivityDto]
  deleted: [activityId: number]
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const confirm = useConfirm()
const toast = useToast()

const expandedId = ref<number | null>(null)

// ─── Handlers ─────────────────────────────────────────────────────────────────

function onCompleted(id: number, activity: ActivityDto) {
  expandedId.value = null
  emit('completed', activity)
  toast.add({ severity: 'success', summary: t('tasks.board.card.completed'), life: 2000 })
}

function onDelete(id: number) {
  confirm.require({
    header: t('activity.actions.deleteConfirmHeader'),
    message: t('activity.actions.deleteConfirmBody'),
    acceptLabel: t('activity.actions.deleteConfirmAccept'),
    rejectLabel: t('activity.actions.deleteConfirmReject'),
    acceptClass: 'p-button-danger',
    accept: () => {
      expandedId.value = null
      emit('deleted', id)
    },
  })
}
</script>

<style lang="scss" scoped>
.open-tasks {
  background: var(--p-card-background);
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

// ─── Header ──────────────────────────────────────────────────────────────────

.open-tasks__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-bottom: 1px solid var(--p-surface-100);
  background: var(--p-surface-50);

  .app-dark & {
    background: var(--p-surface-800);
    border-bottom-color: var(--p-surface-700);
  }
}

.open-tasks__header-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.open-tasks__header-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// ─── List ─────────────────────────────────────────────────────────────────────

.open-tasks__list {
  display: flex;
  flex-direction: column;
  max-height: 220px;
  overflow-y: auto;
}

// ─── Row ─────────────────────────────────────────────────────────────────────

.open-tasks__row {
  border-bottom: 1px solid var(--p-surface-100);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }

  &:last-child {
    border-bottom: none;
  }

  &--overdue {
    .open-tasks__compact {
      background: rgba(var(--p-red-500-rgb, 239, 68, 68), 0.04);
    }
  }
}

// ─── Compact row ─────────────────────────────────────────────────────────────

.open-tasks__compact {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  min-height: 36px;
  transition: background var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-800);
    }

    .open-tasks__complete-label {
      display: inline;
    }
  }
}

.open-tasks__kind-icon {
  font-size: 12px;
  color: $surface-400;
  flex-shrink: 0;
}

.open-tasks__title {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-700;
  min-width: 0;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.open-tasks__due {
  font-size: $font-size-xs;
  color: $surface-400;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  white-space: nowrap;
  flex-shrink: 0;

  &--overdue {
    color: var(--p-red-500);
    font-weight: $font-weight-medium;
  }
}

.open-tasks__due-icon {
  font-size: 10px;
}

.open-tasks__responsible {
  font-size: $font-size-xs;
  color: $surface-400;
  white-space: nowrap;
  flex-shrink: 0;
  max-width: 100px;
  overflow: hidden;
  text-overflow: ellipsis;
}

// ─── Complete button ──────────────────────────────────────────────────────────

.open-tasks__complete-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border: 1px solid var(--p-green-500);
  border-radius: $radius-sm;
  background: transparent;
  color: var(--p-green-500);
  font-size: 11px;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: all var(--app-transition-fast);

  .open-tasks__complete-label {
    display: none;
  }

  &:hover {
    background: var(--p-green-500);
    color: #fff;

    .open-tasks__complete-label {
      display: inline;
    }
  }

  .pi {
    font-size: 11px;
  }
}

// ─── Expanded TQF ────────────────────────────────────────────────────────────

.open-tasks__tqf {
  margin: $space-2 $space-4;
}

// ─── TQF slide transition ─────────────────────────────────────────────────────

.tqf-slide-enter-active,
.tqf-slide-leave-active {
  transition: all 0.18s ease;
  overflow: hidden;
}

.tqf-slide-enter-from,
.tqf-slide-leave-to {
  opacity: 0;
  max-height: 0;
}

.tqf-slide-enter-to,
.tqf-slide-leave-from {
  opacity: 1;
  max-height: 320px;
}
</style>
