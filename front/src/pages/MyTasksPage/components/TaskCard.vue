<template>
  <div
    class="task-card"
    :class="{
      'task-card--overdue': bucket === 'overdue' && task.status !== 'done',
      'task-card--critical': task.priority === 'critical',
      'task-card--done': task.status === 'done',
      'task-card--selected': selected,
    }"
    @click="onCardClick"
  >
    <!-- Select-mode checkbox -->
    <div
      v-if="selectMode"
      class="task-card__checkbox"
      :class="{ 'task-card__checkbox--checked': selected }"
      @click.stop="emit('toggleSelect', task.id)"
    >
      <i v-if="selected" class="pi pi-check" />
    </div>

    <div class="task-card__body">
      <!-- Deal link -->
      <div v-if="task.deal" class="task-card__deal">
        <i class="pi pi-briefcase task-card__deal-icon" />
        <RouterLink
          :to="`/deals/${task.deal.id}`"
          class="task-card__deal-link"
          @click.stop
        >
          {{ task.deal.title }}
        </RouterLink>
      </div>

      <!-- Task title -->
      <p
        class="task-card__title"
        :class="{ 'task-card__title--done': task.status === 'done' }"
      >
        {{ task.title ?? '' }}
      </p>

      <!-- Kind tag + priority -->
      <div class="task-card__meta-row">
        <span class="task-card__kind-tag" :class="`task-card__kind-tag--${task.kind}`">
          <i :class="kindIconFn(task.kind)" class="task-card__kind-icon" />
          {{ kindLabelFn(task.kind) }}
        </span>
        <span v-if="showPriority" class="task-card__priority" :class="`task-card__priority--${task.priority}`">
          <i class="pi pi-flag-fill task-card__priority-icon" />
          {{ priorityLabel }}
        </span>
      </div>

      <!-- Assignee -->
      <div class="task-card__assignee">
        <span class="task-card__avatar" :title="assigneeFullName">
          {{ assigneeInitial }}
        </span>
        <span class="task-card__assignee-name">{{ assigneeShortName }}</span>
      </div>
    </div>

    <!-- Health strip -->
    <div class="task-card__health" :class="healthClass">
      <i :class="healthIcon" class="task-card__health-icon" />
      <span class="task-card__health-text">{{ healthText }}</span>
      <button
        v-if="task.status !== 'done' && task.status !== 'rejected'"
        type="button"
        class="task-card__complete-btn"
        :disabled="completing"
        @click.stop="onComplete"
      >
        <i v-if="completing" class="pi pi-spin pi-spinner" />
        <i v-else class="pi pi-check" />
        {{ t('tasks.health.complete') }}
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import { kindIcon as kindIconFn, formatDueDateOperational } from '@/utils/activity'
import type { MyBoardActivityDto, ActivityKind, MyBoardBucket } from '@/entities/activity'

const props = defineProps<{
  task: MyBoardActivityDto
  bucket: MyBoardBucket | string
  selectMode?: boolean
  selected?: boolean
}>()

const emit = defineEmits<{
  complete: [id: number]
  toggleSelect: [id: number]
}>()

const { t } = useI18n()
const completing = ref(false)

// ── Kind label ─────────────────────────────────────────────────────────────────
function kindLabelFn(kind: ActivityKind): string {
  const map: Record<ActivityKind, string> = {
    call: t('tasks.board.taskTypes.call'),
    meeting: t('tasks.board.taskTypes.meeting'),
    task: t('tasks.board.taskTypes.task'),
    note: t('tasks.board.taskTypes.note'),
    follow_up: t('tasks.board.taskTypes.follow_up'),
    presentation: 'КП',
  }
  return map[kind] ?? kind
}

// ── Priority ───────────────────────────────────────────────────────────────────
const showPriority = computed(() => props.task.priority === 'high' || props.task.priority === 'critical')

const priorityLabel = computed(() => {
  if (props.task.priority === 'high') return t('activity.priorities.high')
  if (props.task.priority === 'critical') return t('activity.priorities.critical')
  return ''
})

// ── Assignee ───────────────────────────────────────────────────────────────────
const assigneeFullName = computed(() => props.task.assigned_to?.full_name ?? '')

const assigneeInitial = computed(() => {
  const name = assigneeFullName.value
  if (!name) return '?'
  return name.charAt(0).toUpperCase()
})

const assigneeShortName = computed(() => {
  const name = assigneeFullName.value
  if (!name) return ''
  const parts = name.trim().split(' ')
  if (parts.length === 1) return parts[0] ?? ''
  return `${parts[0]} ${parts[1]?.charAt(0).toUpperCase() ?? ''}.`
})

// ── Health strip ───────────────────────────────────────────────────────────────
const healthClass = computed(() => {
  const status = props.task.status
  if (status === 'done') return 'task-card__health--done'
  if (props.bucket === 'overdue') return 'task-card__health--overdue'
  return 'task-card__health--normal'
})

const healthIcon = computed(() => {
  const status = props.task.status
  if (status === 'done') return 'pi pi-check-circle'
  return 'pi pi-clock'
})

const healthText = computed(() => {
  const status = props.task.status
  if (status === 'done') return t('tasks.health.done')
  if (props.bucket === 'overdue' && props.task.due_at) {
    const when = formatDueDateOperational(props.task.due_at, t)
    return t('tasks.health.overdue', { when })
  }
  if (props.task.due_at) return formatDueDateOperational(props.task.due_at, t)
  return ''
})

// ── Actions ────────────────────────────────────────────────────────────────────
function onCardClick() {
  if (props.selectMode) {
    emit('toggleSelect', props.task.id)
  }
}

async function onComplete() {
  completing.value = true
  try {
    emit('complete', props.task.id)
  } finally {
    completing.value = false
  }
}
</script>

<style lang="scss" scoped>
.task-card {
  position: relative;
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  box-shadow: $shadow-sm;
  overflow: hidden;
  cursor: pointer;
  transition: box-shadow var(--app-transition-fast), border-color var(--app-transition-fast);

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-200);
  }

  &:hover {
    box-shadow: $shadow-card-hover;
  }

  &--overdue {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: inset 4px 0 0 $color-danger; // inset signal bar — no shadow token for left-border effect
    border-color: $color-danger;

    .app-dark & {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      box-shadow: inset 4px 0 0 var(--p-red-500);
      border-color: var(--p-red-500);
    }
  }

  &--critical:not(&--overdue) {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: inset 4px 0 0 $color-warning-badge; // inset signal bar — no shadow token for left-border effect

    .app-dark & {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      box-shadow: inset 4px 0 0 var(--p-orange-500);
    }
  }

  &--done {
    opacity: 0.7;
  }

  &--selected {
    border-color: $primary-900;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: 0 0 0 1px $primary-900;

    .app-dark & {
      border-color: $primary-300;
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      box-shadow: 0 0 0 1px $primary-300;
    }
  }
}

// ── Select-mode checkbox ──────────────────────────────────────────────────────

.task-card__checkbox {
  position: absolute;
  top: $space-2;
  right: $space-2;
  width: 18px;
  height: 18px;
  border-radius: $radius-xs;
  border: 1px solid $surface-300;
  background: $surface-card;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  z-index: 1;
  transition: all var(--app-transition-fast);

  .app-dark & {
    border-color: var(--p-surface-500);
    background: var(--p-surface-200);
  }

  &--checked {
    background: $primary-900;
    border-color: $primary-900;
    color: $surface-0;

    .app-dark & {
      background: $primary-300;
      border-color: $primary-300;
      color: $surface-800;
    }

    .pi {
      font-size: $font-size-3xs;
    }
  }
}

// ── Body ──────────────────────────────────────────────────────────────────────

.task-card__body {
  padding: 11px $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

// ── Deal link ─────────────────────────────────────────────────────────────────

.task-card__deal {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.task-card__deal-icon {
  font-size: $font-size-3xs; // 10px — snap from literal
  color: $surface-400;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.task-card__deal-link {
  font-size: $font-size-xs;
  color: $surface-500;
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    color: $primary-color;
    text-decoration: underline;
  }
}

// ── Title ─────────────────────────────────────────────────────────────────────

.task-card__title {
  margin: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  line-height: 1.4;
  // Two-line clamp
  display: -webkit-box;
  // stylelint-disable-next-line value-no-vendor-prefix
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;

  .app-dark & {
    color: var(--p-surface-100);
  }

  &--done {
    text-decoration: line-through;
    color: $surface-400;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }
}

// ── Meta row (kind + priority) ─────────────────────────────────────────────────

.task-card__meta-row {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
}

.task-card__kind-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  border-radius: $radius-sm;
  padding: 2px $space-2;
  font-size: $font-size-2xs;
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
  }

  &--meeting {
    background: $task-tag-meeting-bg;
    color: $task-tag-meeting-text;
  }

  &--note {
    background: $task-tag-note-bg;
    color: $task-tag-note-text;
  }

  &--follow_up {
    background: $task-tag-follow-up-bg;
    color: $task-tag-follow-up-text;
  }

  &--presentation {
    background: $task-tag-follow-up-bg;
    color: $task-tag-follow-up-text;
  }
}

.task-card__kind-icon {
  font-size: $font-size-3xs; // 10px — snap
}

.task-card__priority {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;

  &--high {
    color: $color-warning-badge;

    .app-dark & {
      color: var(--p-orange-400);
    }
  }

  &--critical {
    color: $color-danger;

    .app-dark & {
      color: var(--p-red-400);
    }
  }
}

.task-card__priority-icon {
  font-size: $font-size-3xs; // snap from 9px — closest is 10px token
}

// ── Assignee ──────────────────────────────────────────────────────────────────

.task-card__assignee {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.task-card__avatar {
  width: 20px;
  height: 20px;
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

.task-card__assignee-name {
  font-size: $font-size-xs;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

// ── Health strip ──────────────────────────────────────────────────────────────

.task-card__health {
  display: flex;
  align-items: center;
  gap: $space-1;
  padding: 7px $space-3;
  border-top: 1px solid $surface-200;
  min-height: 30px;
  font-size: $font-size-2xs;

  .app-dark & {
    border-color: var(--p-surface-200);
  }

  &--done {
    background: $surface-50;
    color: $task-tag-meeting-text;

    .app-dark & {
      background: var(--p-surface-50);
      color: var(--p-green-400);
    }
  }

  &--overdue {
    background: $color-danger-bg;
    color: $color-danger-text;
    font-weight: $font-weight-medium;

    .app-dark & {
      background: var(--p-red-900);
      color: var(--p-red-300);
    }
  }

  &--normal {
    background: $surface-50;
    color: $surface-600;

    .app-dark & {
      background: var(--p-surface-50);
      color: var(--p-surface-300);
    }
  }
}

.task-card__health-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

.task-card__health-text {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.task-card__complete-btn {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  margin-left: auto;
  flex-shrink: 0;
  border: none;
  background: transparent;
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  color: $task-tag-meeting-text;
  cursor: pointer;
  padding: 2px $space-1;
  border-radius: $radius-sm;
  transition: all var(--app-transition-fast);

  .app-dark & {
    color: var(--p-green-400);
  }

  &:hover:not(:disabled) {
    background: $task-tag-meeting-bg;

    .app-dark & {
      background: var(--p-green-900);
    }
  }

  &:disabled {
    opacity: 0.5;
    cursor: default;
  }

  .pi {
    font-size: $font-size-xs;
  }
}
</style>
