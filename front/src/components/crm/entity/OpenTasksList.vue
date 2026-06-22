<template>
  <!--
    OpenTasksList — compact list of open (non-closed) tasks shown above the composer.
    DealCard §11: type chip + date + responsible in meta row (NOT grey icon left of title).
    Click on card = expand; collapse only via clickOutside; 3-step delete.
  -->
  <div v-if="tasks.length > 0" class="open-tasks" v-click-outside="collapseAll">
    <button
      type="button"
      class="open-tasks__header"
      @click="headerCollapsed = !headerCollapsed"
    >
      <i class="pi pi-list-check open-tasks__header-icon" />
      <span class="open-tasks__header-label">
        {{ t('sales.deal.feed.openTasks.title', { n: tasks.length }) }}
      </span>
      <i class="pi open-tasks__header-chevron" :class="headerCollapsed ? 'pi-chevron-down' : 'pi-chevron-up'" />
    </button>

    <div v-if="!headerCollapsed" class="open-tasks__list">
      <div
        v-for="task in tasks"
        :key="task.id"
        class="open-tasks__row"
        :class="{
          'open-tasks__row--overdue': task.is_overdue && !task.is_closed,
          'open-tasks__row--expanded': expandedId === task.id,
        }"
      >
        <!-- Compact row (collapsed state) — click anywhere on card = expand -->
        <div
          v-if="expandedId !== task.id"
          class="open-tasks__compact"
          role="button"
          tabindex="0"
          @click="expandTask(task.id)"
          @keydown.enter="expandTask(task.id)"
          @keydown.space.prevent="expandTask(task.id)"
        >
          <!-- Title (main content) -->
          <span class="open-tasks__title text-truncate">{{ task.title }}</span>

          <!-- Meta row: type chip · due date · responsible (DealCard §11 order) -->
          <div class="open-tasks__meta">
            <!-- Type chip with kind color -->
            <span
              class="open-tasks__type-chip"
              :style="typeChipStyle(task.kind)"
            >
              <i :class="['pi', resolvedKindIcon(task.kind)]" />
              {{ kindLabel(task.kind) }}
            </span>
            <!-- Due date (clickable — calendar) -->
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
          </div>

          <!-- Right corner: Edit + Complete + 3-step Delete buttons (always visible) -->
          <div class="open-tasks__actions" @click.stop>
            <!-- Edit button (B4) -->
            <button
              type="button"
              class="open-tasks__edit-btn"
              :title="t('common.edit')"
              @click="emit('edit', task)"
            >
              <i class="pi pi-pencil" />
            </button>
            <button
              type="button"
              class="open-tasks__complete-btn"
              :title="t('activity.actions.complete')"
              @click="expandTask(task.id)"
            >
              <i class="pi pi-check" />
              <span class="open-tasks__complete-label">{{ t('activity.actions.complete') }}</span>
            </button>
            <!-- 3-step delete, spec DealCard §11 -->
            <button
              type="button"
              class="open-tasks__delete-btn"
              :class="{
                'open-tasks__delete-btn--warn': (deleteClickCounts[task.id] ?? 0) >= 1,
                'open-tasks__delete-btn--danger': (deleteClickCounts[task.id] ?? 0) >= 2,
              }"
              :title="deleteTooltip(task.id)"
              @click="handleDeleteClick(task)"
            >
              <i class="pi pi-times" />
            </button>
          </div>
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
import { ref, reactive } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import TaskQuickForm from '@/components/tasks/TaskQuickForm.vue'
import { kindIcon, kindColor, formatDueDate } from '@/utils/activity'
import type { ActivityDto, ActivityKind, ActivityTargetType } from '@/entities/activity'

// ─── Click-outside directive ─────────────────────────────────────────────────

const vClickOutside = {
  mounted(el: HTMLElement, binding: { value: () => void }) {
    el.__clickOutsideHandler = (event: MouseEvent) => {
      if (!el.contains(event.target as Node)) {
        binding.value()
      }
    }
    document.addEventListener('click', el.__clickOutsideHandler)
  },
  unmounted(el: HTMLElement) {
    if (el.__clickOutsideHandler) {
      document.removeEventListener('click', el.__clickOutsideHandler)
      delete el.__clickOutsideHandler
    }
  },
}

declare module 'vue' {
  interface ComponentCustomProperties {
    vClickOutside: typeof vClickOutside
  }
}

// ─── Extend HTMLElement for handler storage ──────────────────────────────────

declare global {
  interface HTMLElement {
    __clickOutsideHandler?: (event: MouseEvent) => void
  }
}

// ─── Props / emits ────────────────────────────────────────────────────────────

defineProps<{
  tasks: ActivityDto[]
  targetType: ActivityTargetType
  targetId: number
}>()

const emit = defineEmits<{
  completed: [activity: ActivityDto]
  deleted: [activityId: number]
  edit: [activity: ActivityDto]
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()

const expandedId = ref<number | null>(null)
const headerCollapsed = ref(false)
// 3-step delete counter per task id
const deleteClickCounts = reactive<Record<number, number>>({})

// ─── Kind helpers ─────────────────────────────────────────────────────────────

function resolvedKindIcon(kind: ActivityKind): string {
  return kindIcon(kind).split(' ')[1] ?? 'pi-circle'
}

function kindLabel(kind: ActivityKind): string {
  const keyMap: Partial<Record<ActivityKind, string>> = {
    call: 'activity.kinds.call',
    meeting: 'activity.kinds.meeting',
    task: 'activity.kinds.task',
    note: 'activity.kinds.note',
    follow_up: 'activity.kinds.follow_up',
    presentation: 'activity.kinds.presentation',
  }
  return t(keyMap[kind] ?? 'activity.kinds.task')
}

// Type chip style — background tint of kind color (DealCard §11)
function typeChipStyle(kind: ActivityKind): Record<string, string> {
  const color = kindColor(kind)
  if (!color) {
    return {
      background: 'var(--p-surface-100)',
      color: 'var(--p-surface-500)',
    }
  }
  return {
    background: `color-mix(in srgb, ${color} 14%, var(--p-surface-50))`,
    color: color,
  }
}

// ─── Expand / collapse ────────────────────────────────────────────────────────

function expandTask(id: number) {
  expandedId.value = id
}

function collapseAll() {
  expandedId.value = null
}

// ─── 3-step delete (DealCard §11) ─────────────────────────────────────────────

function deleteTooltip(taskId: number): string {
  const clicks = deleteClickCounts[taskId] ?? 0
  if (clicks === 0) return t('activity.actions.delete')
  if (clicks === 1) return t('activity.actions.deleteConfirmStep2', 'Ещё раз для подтверждения')
  return t('activity.actions.deleteConfirmStep3', 'Последнее нажатие — удалить?')
}

function handleDeleteClick(task: ActivityDto) {
  const current = deleteClickCounts[task.id] ?? 0
  if (current < 2) {
    deleteClickCounts[task.id] = current + 1
    // Reset after 3 seconds if not clicked again
    setTimeout(() => {
      if ((deleteClickCounts[task.id] ?? 0) <= current + 1) {
        deleteClickCounts[task.id] = 0
      }
    }, 3000)
  } else {
    // 3rd click — confirm delete
    deleteClickCounts[task.id] = 0
    onDelete(task.id)
  }
}

// ─── Handlers ─────────────────────────────────────────────────────────────────

function onCompleted(id: number, activity: ActivityDto) {
  expandedId.value = null
  emit('completed', activity)
  toast.add({ severity: 'success', summary: t('tasks.board.card.completed'), life: 2000 })
}

function onDelete(id: number) {
  expandedId.value = null
  emit('deleted', id)
}
</script>

<style lang="scss" scoped>
.open-tasks {
  background: var(--p-card-background);
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;
}

// ─── Header ──────────────────────────────────────────────────────────────────

.open-tasks__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-bottom: 1px solid var(--p-surface-100);
  background: var(--p-surface-50);
  cursor: pointer;
  border-top: none;
  border-left: none;
  border-right: none;
  width: 100%;
  text-align: left;

  .app-dark & {
    background: var(--p-surface-100); // dark: #444547 (card bg)
    border-bottom-color: var(--p-surface-700);
  }
}

.open-tasks__header-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.open-tasks__header-label {
  flex: 1;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;

  .app-dark & {
    color: var(--p-surface-400);
  }
}

.open-tasks__header-chevron {
  font-size: $font-size-xs;
  color: $surface-400;
}

// ─── List — hidden scrollbar ──────────────────────────────────────────────────

.open-tasks__list {
  display: flex;
  flex-direction: column;
  max-height: 220px;
  overflow-y: auto;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
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
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(239, 68, 68, 0.04); // subtle red tint for overdue
    }
  }
}

// ─── Compact row ─────────────────────────────────────────────────────────────

.open-tasks__compact {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  padding: $space-2 $space-4;
  cursor: pointer;
  transition: background var(--app-transition-fast);
  position: relative;

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-100); // dark: subtle hover
    }
  }
}

.open-tasks__title {
  font-size: $font-size-sm;
  color: $surface-700;
  font-weight: $font-weight-medium;
  padding-right: 120px; // reserve space for action buttons

  .app-dark & {
    color: var(--p-surface-200);
  }
}

// Meta row: type chip · date · responsible
.open-tasks__meta {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

// Type chip — colored pill (DealCard §11: type shown as chip, not icon left of title)
.open-tasks__type-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2px 6px; // compact chip
  border-radius: $radius-sm;
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  // background and color set via :style (dynamic per kind)

  i {
    font-size: $font-size-3xs;
  }
}

.open-tasks__due {
  font-size: $font-size-xs;
  color: $surface-400;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  white-space: nowrap;

  &--overdue {
    color: var(--p-red-500);
    font-weight: $font-weight-medium;
  }
}

.open-tasks__due-icon {
  font-size: $font-size-3xs;
}

.open-tasks__responsible {
  font-size: $font-size-xs;
  color: $surface-400;
  white-space: nowrap;
  max-width: 120px;
  overflow: hidden;
  text-overflow: ellipsis;
}

// ─── Actions (top-right corner, always visible) ───────────────────────────────

.open-tasks__actions {
  position: absolute;
  top: $space-2;
  right: $space-4;
  display: flex;
  align-items: center;
  gap: $space-1;
}

// ─── Edit button (B4) ────────────────────────────────────────────────────────

.open-tasks__edit-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 20px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 20px;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  background: transparent;
  color: $surface-400;
  font-size: $font-size-3xs;
  cursor: pointer;
  flex-shrink: 0;
  transition: all var(--app-transition-fast);

  &:hover {
    border-color: var(--p-primary-color);
    color: var(--p-primary-color);
  }

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-400);

    &:hover {
      border-color: var(--p-primary-300);
      color: var(--p-primary-300);
    }
  }
}

// ─── Complete button ──────────────────────────────────────────────────────────

.open-tasks__complete-btn {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2px 8px;
  border: 1px solid var(--p-green-500);
  border-radius: $radius-sm;
  background: transparent;
  color: var(--p-green-500);
  font-size: $font-size-2xs;
  cursor: pointer;
  white-space: nowrap;
  flex-shrink: 0;
  transition: all var(--app-transition-fast);

  .open-tasks__complete-label {
    display: none;
  }

  &:hover {
    background: var(--p-green-500);
    color: $sidebar-text-active;

    .open-tasks__complete-label {
      display: inline;
    }
  }

  .pi {
    font-size: $font-size-2xs;
  }
}

// ─── 3-step delete button (DealCard §11) ─────────────────────────────────────

.open-tasks__delete-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 20px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 20px;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  background: transparent;
  color: $surface-400;
  font-size: $font-size-3xs;
  cursor: pointer;
  flex-shrink: 0;
  transition: all var(--app-transition-fast);

  &:hover {
    border-color: var(--p-surface-400);
    color: $surface-600;
  }

  &--warn {
    // 1st click: slightly larger + orange
    transform: scale(1.15);
    border-color: var(--p-orange-400);
    color: var(--p-orange-500);
  }

  &--danger {
    // 2nd click: red — final confirmation
    transform: scale(1.25);
    border-color: var(--p-red-500);
    color: var(--p-red-500);
    background: var(--p-red-50);
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
