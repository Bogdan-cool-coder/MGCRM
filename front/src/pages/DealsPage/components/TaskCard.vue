<template>
  <div class="task-card">
    <!-- Deal link -->
    <div v-if="task.deal" class="task-card__deal">
      <i class="pi pi-briefcase task-card__deal-icon" />
      <RouterLink :to="`/deals/${task.deal.id}`" class="task-card__deal-link" @click.stop>
        {{ task.deal.title }}
      </RouterLink>
    </div>

    <!-- Type tag + action -->
    <div class="task-card__type-row">
      <span class="task-card__type-tag" :class="`task-tag--${task.kind}`">
        <i :class="kindIcon(task.kind)" class="task-card__type-icon" />
        {{ kindLabel(task.kind) }}
      </span>
      <span v-if="taskText" class="task-card__action-text">{{ taskText }}</span>
    </div>

    <!-- Assignee + due -->
    <div class="task-card__footer">
      <span class="task-card__assignee">
        {{ assigneeName }}
      </span>
      <span v-if="task.due_at" class="task-card__due" :class="{ 'task-card__due--overdue': isOverdue }">
        · {{ formatDue(task.due_at) }}
      </span>
      <div class="task-card__spacer" />
      <Button
        icon="pi pi-check"
        :label="t('tasks.board.card.complete')"
        text
        severity="success"
        size="small"
        class="task-card__complete-btn"
        :loading="completing"
        @click.stop="onComplete"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import type { MyBoardActivityDto } from '@/entities/activity'
import type { ActivityKind } from '@/entities/activity'

const props = defineProps<{
  task: MyBoardActivityDto
  isOverdue?: boolean
}>()

const emit = defineEmits<{
  complete: [id: number]
}>()

const { t } = useI18n()
const completing = ref(false)

function kindIcon(kind: ActivityKind): string {
  const map: Record<ActivityKind, string> = {
    call: 'pi pi-phone',
    meeting: 'pi pi-calendar',
    task: 'pi pi-check-square',
    note: 'pi pi-file-edit',
    follow_up: 'pi pi-reply',
  }
  return map[kind] ?? 'pi pi-check-square'
}

function kindLabel(kind: ActivityKind): string {
  return t(`tasks.board.taskTypes.${kind}`)
}

const taskText = computed(() => {
  const desc = props.task.description ?? ''
  if (!desc) return ''
  return desc.length > 60 ? desc.slice(0, 60) + '…' : desc
})

const assigneeName = computed(() => {
  const person = props.task.assigned_to
  if (!person) return ''
  const parts = person.full_name.trim().split(' ')
  if (parts.length === 1) return parts[0] ?? ''
  return `${parts[0]} ${parts[1]?.charAt(0).toUpperCase() ?? ''}.`
})

function formatDue(dueAt: string): string {
  const d = new Date(dueAt)
  const now = new Date()
  const todayStr = now.toDateString()
  const tomorrow = new Date(now)
  tomorrow.setDate(tomorrow.getDate() + 1)
  const hhmm = `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`

  if (d.toDateString() === todayStr) return t('tasks.board.card.today', { time: hhmm })
  if (d.toDateString() === tomorrow.toDateString()) return t('tasks.board.card.tomorrow', { time: hhmm })
  const day = String(d.getDate()).padStart(2, '0')
  const month = String(d.getMonth() + 1).padStart(2, '0')
  return `${day}.${month} ${hhmm}`
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
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-2;

  :global(.app-dark) & {
    background: var(--p-surface-900);
    border-color: var(--p-surface-700);
  }

  &:hover {
    box-shadow: var(--p-card-shadow);
  }
}

.task-card__deal {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.task-card__deal-icon {
  font-size: 12px;
  color: $surface-400;
  flex-shrink: 0;
}

.task-card__deal-link {
  font-size: 12px;
  color: $surface-500;
  text-decoration: none;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  &:hover {
    color: $primary-color;
    text-decoration: underline;
  }
}

.task-card__type-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.task-card__type-tag {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  border-radius: $radius-sm;
  padding: 2px 8px;
  font-size: 11px;
  font-weight: $font-weight-medium;
  flex-shrink: 0;
}

// Task type tag color classes
:global(.task-tag--meeting) {
  background: $task-tag-meeting-bg;
  color: $task-tag-meeting-text;
}
:global(.task-tag--call) {
  background: $task-tag-call-bg;
  color: $task-tag-call-text;
}
:global(.task-tag--task) {
  background: var(--p-surface-100);
  color: $surface-600;
}
:global(.task-tag--note) {
  background: $task-tag-note-bg;
  color: $task-tag-note-text;
}
:global(.task-tag--follow_up) {
  background: $task-tag-follow-up-bg;
  color: $task-tag-follow-up-text;
}

.task-card__type-icon {
  font-size: 10px;
}

.task-card__action-text {
  font-size: $font-size-xs;
  color: $surface-600;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  :global(.app-dark) & {
    color: var(--p-surface-300);
  }
}

.task-card__footer {
  display: flex;
  align-items: center;
  gap: 2px;
  font-size: $font-size-xs;
}

.task-card__assignee {
  color: $surface-500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100px;
}

.task-card__due {
  color: $surface-500;
  white-space: nowrap;
  flex-shrink: 0;

  &--overdue {
    color: $color-danger;
    font-weight: $font-weight-medium;
  }
}

.task-card__spacer {
  flex: 1;
}

.task-card__complete-btn {
  flex-shrink: 0;
}
</style>
