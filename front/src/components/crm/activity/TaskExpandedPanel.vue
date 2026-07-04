<template>
  <!--
    TaskExpandedPanel — единый компонент разворота задачи.
    mode="inline"  → рендерит .open-tasks__expanded-wrap (встраивается в OpenTasksList).
    mode="dialog"  → рендерит полный Dialog 540px (канбан / список задач).
    Логика complete/delete/validate — здесь; OpenTasksList/TaskCard — только оболочки.
  -->

  <!-- ── INLINE MODE ────────────────────────────────────────────────────────── -->
  <div
    v-if="mode === 'inline'"
    class="open-tasks__expanded-wrap"
    @click.stop
  >
    <div class="open-tasks__expanded-header">
      <p class="open-tasks__expanded-title">{{ task.title }}</p>
      <div class="open-tasks__expanded-actions">
        <button
          type="button"
          class="open-tasks__complete-btn"
          :disabled="completing"
          @click.stop="onCompleteSubmit"
        >
          <i class="pi pi-check" />
          <span class="open-tasks__complete-label">{{ t('activity.actions.complete') }}</span>
        </button>
        <button
          type="button"
          class="open-tasks__delete-btn"
          :class="{
            'open-tasks__delete-btn--warn': deleteCount === 1,
            'open-tasks__delete-btn--danger': deleteCount >= 2,
          }"
          :title="deleteTooltip"
          @click="handleDeleteClick"
        >
          <i class="pi pi-times" />
        </button>
        <button
          type="button"
          class="open-tasks__collapse-btn"
          :title="t('common.close')"
          @click.stop="emit('close')"
        >
          <i class="pi pi-times" />
        </button>
      </div>
    </div>

    <div class="open-tasks__meta open-tasks__meta--expanded">
      <span class="open-tasks__type-chip open-tasks__type-chip--static" :style="typeChipStyle">
        <i :class="['pi', resolvedKindIcon]" />
        {{ kindLabel }}
      </span>
      <span
        class="open-tasks__due"
        :class="{ 'open-tasks__due--overdue': taskIsOverdue }"
      >
        <i class="pi pi-clock open-tasks__meta-icon" />
        {{ dueAtFormatted }}
      </span>
      <span v-if="responsibleName" class="open-tasks__responsible open-tasks__responsible--static">
        <i class="pi pi-user open-tasks__meta-icon" />
        {{ responsibleName }}
      </span>
    </div>

    <div class="open-tasks__result-wrap">
      <textarea
        :ref="(el) => { resultInputEl = el as HTMLTextAreaElement | null }"
        v-model="resultDraft"
        class="open-tasks__result-input"
        :class="{ 'open-tasks__result-input--required': resultRequired }"
        :placeholder="t('tasks.window.fields.resultPlaceholder')"
        rows="2"
        @click.stop
        @input="onResultInput"
      />
      <p v-if="resultRequired" class="open-tasks__result-error">
        {{ t('tasks.window.fields.resultRequired') }}
      </p>
    </div>
  </div>

  <!-- ── DIALOG MODE ────────────────────────────────────────────────────────── -->
  <!--
    Dialog lives in the HOST (TasksKanbanBoard / MyTasksPage) — this component is
    only its content + close button. Visibility is driven by the host via v-if.
  -->
  <div v-else class="task-window">
    <!-- Header -->
    <div class="task-window__header">
      <div class="task-window__header-left">
        <span class="task-window__header-tile" :style="headerTileStyle">
          <i :class="['pi', resolvedKindIcon]" />
        </span>
        <div class="task-window__header-text">
          <h3 class="task-window__title">{{ task.title }}</h3>
          <div class="task-window__header-meta">
            <span class="task-window__type-chip" :style="typeChipStyle">
              <i :class="['pi', resolvedKindIcon]" />
              {{ kindLabel }}
            </span>
            <span
              class="task-window__due-chip"
              :class="{ 'task-window__due-chip--overdue': taskIsOverdue }"
            >
              <i class="pi pi-clock" />
              {{ dueAtFormatted }}
            </span>
          </div>
        </div>
      </div>
      <button type="button" class="task-window__close-btn" @click="emit('close')">
        <i class="pi pi-times" />
      </button>
    </div>

    <!-- Related entity -->
    <div v-if="relatedEntity" class="task-window__related">
      <div class="task-window__related-inner">
        <i :class="['pi', relatedEntity.icon, 'task-window__related-icon']" />
        <span class="task-window__related-label">{{ relatedEntity.label }}</span>
        <RouterLink
          v-if="relatedEntity.to"
          :to="relatedEntity.to"
          class="task-window__related-link"
          @click="emit('close')"
        >
          <i class="pi pi-external-link task-window__related-ext" />
        </RouterLink>
      </div>
    </div>

    <!-- Fields grid -->
    <div class="task-window__fields">
      <div class="task-window__field">
        <span class="task-window__field-label">{{ t('tasks.window.fields.responsible') }}</span>
        <span class="task-window__field-value">
          <span v-if="responsibleName" class="task-window__avatar-chip">
            <EntityAvatar :name="responsibleName" :pixel-size="20" />
            {{ responsibleShortName }}
          </span>
          <span v-else class="task-window__field-empty">—</span>
        </span>
      </div>
      <div class="task-window__field">
        <span class="task-window__field-label">{{ t('tasks.window.fields.kind') }}</span>
        <span class="task-window__field-value">
          <span class="task-window__type-chip" :style="typeChipStyle">
            <i :class="['pi', resolvedKindIcon]" />
            {{ kindLabel }}
          </span>
        </span>
      </div>
      <div class="task-window__field">
        <span class="task-window__field-label">{{ t('tasks.window.fields.dueAt') }}</span>
        <span
          class="task-window__due-chip task-window__field-value"
          :class="{ 'task-window__due-chip--overdue': taskIsOverdue }"
        >
          <i class="pi pi-clock" />
          {{ dueAtFormatted }}
        </span>
      </div>
      <div class="task-window__field">
        <span class="task-window__field-label">{{ t('tasks.window.fields.status') }}</span>
        <span class="task-window__field-value">
          <Tag :severity="statusSeverity" :value="t(`activity.statuses.${task.status}`)" />
        </span>
      </div>
    </div>

    <!-- Task description (read-only) -->
    <div v-if="taskBody" class="task-window__section">
      <span class="task-window__section-label">{{ t('tasks.window.fields.description') }}</span>
      <p class="task-window__description">{{ taskBody }}</p>
    </div>

    <!-- Result textarea (hidden when task is already done) -->
    <div v-if="task.status !== 'done'" class="task-window__section">
      <span class="task-window__section-label">
        {{ t('tasks.window.fields.result') }}
        <span class="task-window__required-star">*</span>
      </span>
      <Textarea
        ref="resultTextareaRef"
        v-model="resultDraft"
        class="w-full"
        :rows="3"
        auto-resize
        :placeholder="t('tasks.window.fields.resultPlaceholder')"
        :class="{ 'p-invalid': resultRequired }"
        @input="onResultInput"
      />
      <small v-if="resultRequired" class="p-error">
        {{ t('tasks.window.fields.resultRequired') }}
      </small>
    </div>

    <!-- Footer -->
    <div class="task-window__footer">
      <Button
        icon="pi pi-trash"
        :label="t('tasks.window.actions.delete')"
        severity="danger"
        text
        class="task-window__delete-btn"
        :class="{
          'task-window__delete-btn--warn': deleteCount === 1,
          'task-window__delete-btn--danger': deleteCount >= 2,
        }"
        :title="deleteTooltip"
        @click="handleDeleteClick"
      />
      <div class="task-window__footer-right">
        <Button
          :label="t('tasks.window.actions.cancel')"
          severity="secondary"
          text
          @click="emit('close')"
        />
        <Button
          v-if="task.status !== 'done'"
          icon="pi pi-check"
          :label="t('tasks.window.actions.complete')"
          severity="success"
          :loading="completing"
          :disabled="completing"
          @click="onCompleteSubmit"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Tag from 'primevue/tag'
import Textarea from 'primevue/textarea'
import Button from 'primevue/button'
import { RouterLink } from 'vue-router'
import { kindIcon, todayInOperationalTz, dateInOperationalTz } from '@/utils/activity'
import { activityApi } from '@/api/activity'
import type { ActivityDto, ActivityKind, ActivityStatus, MyBoardActivityDto } from '@/entities/activity'
import { taskKindChipStyle } from '@/shared/taskKindColors'
import { useThemeStore } from '@/stores/theme'
import EntityAvatar from '@/components/crm/entity/EntityAvatar.vue'

// ─── Props / emits ─────────────────────────────────────────────────────────────

const props = defineProps<{
  task: ActivityDto | MyBoardActivityDto
  mode: 'dialog' | 'inline'
  /** Pass true when opened via the "Выполнить" button — auto-focus the textarea */
  focusResult?: boolean
}>()

const emit = defineEmits<{
  completed: [activity: ActivityDto]
  deleted: [id: number]
  updated: [activity: ActivityDto]
  close: []
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()
const themeStore = useThemeStore()
const isDark = computed(() => themeStore.theme === 'dark')

// result draft
const resultDraft = ref('')
const resultRequired = ref(false)
const completing = ref(false)
// inline mode uses a native <textarea> (direct DOM ref);
// dialog mode uses PrimeVue <Textarea> (component ref, focus via $el).
const resultInputEl = ref<HTMLTextAreaElement | null>(null)
const resultTextareaRef = ref<{ $el: HTMLTextAreaElement } | null>(null)

/** Focus the result field regardless of mode (native vs PrimeVue Textarea). */
function focusResultInput() {
  resultInputEl.value?.focus()
  resultTextareaRef.value?.$el?.focus()
}

// 3-step delete
const deleteCount = ref(0)
let deleteTimer: ReturnType<typeof setTimeout> | null = null

// ─── Focus helpers ─────────────────────────────────────────────────────────────

// When focusResult prop is set (e.g. complete-btn click) — auto-focus textarea
watch(
  () => props.focusResult,
  (v) => {
    if (v) {
      nextTick(() => {
        resultRequired.value = true
        focusResultInput()
      })
    }
  },
  { immediate: true },
)

// Expose a method so parent (e.g. TasksKanbanBoard) can focus the textarea
function focusResultField() {
  nextTick(() => {
    resultRequired.value = true
    focusResultInput()
  })
}
defineExpose({ focusResultField })

// ─── Kind helpers ──────────────────────────────────────────────────────────────

function resolveKindIcon(kind: ActivityKind | null | undefined): string {
  if (!kind) return 'pi-circle'
  return kindIcon(kind).split(' ')[1] ?? 'pi-circle'
}

const resolvedKindIcon = computed(() => resolveKindIcon(props.task.kind))

const typeChipStyle = computed((): Record<string, string> => taskKindChipStyle(props.task.kind, isDark.value))

// Header tile (dialog mode): tinted square with the kind icon — reuses the same
// branded tint/foreground as the type chip so the window matches the kanban card.
const headerTileStyle = computed((): Record<string, string> => {
  const chip = taskKindChipStyle(props.task.kind, isDark.value)
  return { background: chip.background ?? '', color: chip.color ?? '' }
})

const kindLabel = computed((): string => {
  const kind = props.task.kind
  const map: Partial<Record<ActivityKind, string>> = {
    call: t('activity.kinds.call'),
    meeting: t('activity.kinds.meeting'),
    task: t('activity.kinds.task'),
    note: t('activity.kinds.note'),
    follow_up: t('activity.kinds.follow_up'),
    presentation: t('activity.kinds.presentation'),
  }
  return map[kind] ?? t('activity.kinds.task')
})

// ─── Due date / overdue ────────────────────────────────────────────────────────

const taskIsOverdue = computed((): boolean => {
  const task = props.task
  if (task.is_closed) return false
  if (!task.due_at) return false
  const todayStr = todayInOperationalTz()
  const dueStr = dateInOperationalTz(new Date(task.due_at))
  return dueStr < todayStr
})

const dueAtFormatted = computed((): string => {
  const due = props.task.due_at
  if (!due) return t('activity.fields.dueAt')
  const d = new Date(due)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
})

// ─── Responsible ───────────────────────────────────────────────────────────────

const responsibleName = computed((): string => {
  const task = props.task
  // ActivityDto has `responsible`, MyBoardActivityDto has `assigned_to` or `responsible`
  const r = (task as ActivityDto).responsible ?? (task as MyBoardActivityDto).assigned_to ?? null
  return r?.full_name ?? ''
})

const responsibleShortName = computed((): string => {
  const n = responsibleName.value
  if (!n) return ''
  const parts = n.trim().split(' ')
  if (parts.length === 1) return parts[0] ?? ''
  return `${parts[0]} ${parts[1]?.charAt(0).toUpperCase() ?? ''}.`
})

// ─── Status Tag severity ───────────────────────────────────────────────────────

const statusSeverity = computed((): 'info' | 'warn' | 'success' | 'secondary' => {
  const s = props.task.status as ActivityStatus
  if (s === 'new') return 'info'
  if (s === 'in_progress') return 'warn'
  if (s === 'done') return 'success'
  return 'secondary'
})

// ─── Related entity (dialog mode) ─────────────────────────────────────────────

const relatedEntity = computed((): { icon: string; label: string; to: string } | null => {
  const task = props.task
  // Deal takes priority
  const deal = (task as MyBoardActivityDto).deal ?? (task as ActivityDto).deal ?? null
  if (deal) {
    return { icon: 'pi-briefcase', label: deal.title, to: `/deals/${deal.id}` }
  }
  // target (contact / company)
  const target = (task as MyBoardActivityDto).target ?? (task as ActivityDto).target ?? null
  if (target) {
    if (target.type === 'contact') {
      return { icon: 'pi-user', label: target.label, to: `/contacts/${target.id}` }
    }
    if (target.type === 'company') {
      return { icon: 'pi-building', label: target.label, to: `/companies/${target.id}` }
    }
  }
  return null
})

// ─── Task body (description) ──────────────────────────────────────────────────

const taskBody = computed((): string => {
  const task = props.task
  return (task as ActivityDto).body
    ?? (task as MyBoardActivityDto).description
    ?? (task as MyBoardActivityDto).body
    ?? ''
})

// ─── Result input ──────────────────────────────────────────────────────────────

function onResultInput() {
  if (resultRequired.value && resultDraft.value.trim()) {
    resultRequired.value = false
  }
}

// ─── Complete (with gate) ──────────────────────────────────────────────────────

async function onCompleteSubmit() {
  const text = resultDraft.value.trim()
  if (!text) {
    resultRequired.value = true
    await nextTick()
    focusResultInput()
    return
  }
  if (completing.value) return
  completing.value = true
  try {
    const updated = await activityApi.completeActivity(props.task.id, text)
    resultDraft.value = ''
    resultRequired.value = false
    emit('completed', updated)
    toast.add({ severity: 'success', summary: t('tasks.board.card.completed'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  } finally {
    completing.value = false
  }
}

// ─── 3-step delete ─────────────────────────────────────────────────────────────

const deleteTooltip = computed((): string => {
  if (deleteCount.value === 0) return t('tasks.window.actions.delete')
  if (deleteCount.value === 1) return t('activity.actions.deleteConfirmStep2', 'Ещё раз для подтверждения')
  return t('activity.actions.deleteConfirmStep3', 'Последнее нажатие — удалить?')
})

function handleDeleteClick() {
  if (deleteCount.value < 2) {
    deleteCount.value += 1
    if (deleteTimer) clearTimeout(deleteTimer)
    deleteTimer = setTimeout(() => { deleteCount.value = 0 }, 3000)
  } else {
    if (deleteTimer) clearTimeout(deleteTimer)
    deleteCount.value = 0
    void doDelete()
  }
}

async function doDelete() {
  try {
    await activityApi.deleteActivity(props.task.id)
    emit('deleted', props.task.id)
    emit('close')
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}
</script>

<style lang="scss" scoped>
// ─── INLINE MODE — reuses open-tasks__ classes from OpenTasksList.vue ──────────
// These classes are expected by OpenTasksList consumers. We redeclare only what
// the panel itself needs when scoped; the host OpenTasksList.vue provides the
// outer .open-tasks context.

.open-tasks__expanded-wrap {
  display: flex;
  flex-direction: column;
  gap: $space-2;
  padding: $space-2 $space-3;
  background: var(--p-surface-50);
  border-radius: inherit;

  .app-dark & {
    background: var(--p-surface-100);
  }
}

.open-tasks__expanded-header {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
}

.open-tasks__expanded-title {
  flex: 1;
  margin: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  line-height: $line-height-normal;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.open-tasks__expanded-actions {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-shrink: 0;
}

.open-tasks__collapse-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 20px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 20px;
  border: none;
  border-radius: $radius-sm;
  background: transparent;
  color: $surface-400;
  font-size: $font-size-3xs;
  cursor: pointer;
  transition: all var(--app-transition-fast);

  &:hover {
    color: $surface-600;
    background: var(--p-surface-100);

    .app-dark & {
      color: var(--p-surface-700);
      background: var(--p-surface-200);
    }
  }
}

.open-tasks__meta {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  margin-top: 2px;

  &--expanded {
    padding: $space-1 $space-3;
  }
}

.open-tasks__type-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2px 6px;
  border-radius: $radius-sm;
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;

  &--static {
    cursor: default;
    pointer-events: none;
  }

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

  .app-dark & {
    color: var(--p-surface-400);
  }

  &--overdue {
    color: var(--p-red-500);
    font-weight: $font-weight-medium;

    .app-dark & {
      color: var(--p-red-400);
    }
  }

  &--static {
    cursor: default;
    pointer-events: none;
  }
}

.open-tasks__responsible {
  font-size: $font-size-xs;
  color: $surface-400;
  display: inline-flex;
  align-items: center;
  gap: 3px;

  .app-dark & {
    color: var(--p-surface-400);
  }

  &--static {
    cursor: default;
    pointer-events: none;
  }
}

.open-tasks__meta-icon {
  font-size: $font-size-3xs;
}

.open-tasks__result-wrap {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.open-tasks__result-input {
  width: 100%;
  font-size: $font-size-sm;
  color: $surface-700;
  font-family: inherit;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-sm;
  padding: $space-2 $space-3;
  background: var(--p-surface-0);
  resize: none;
  outline: none;
  line-height: $line-height-normal;
  transition: border-color var(--app-transition-fast);

  .app-dark & {
    background: var(--p-surface-100);
    color: var(--p-surface-800);
    border-color: var(--p-surface-300);
  }

  &:focus {
    border-color: var(--p-primary-color);
  }

  &--required {
    border-color: var(--p-red-500);

    &:focus {
      border-color: var(--p-red-500);
    }
  }
}

.open-tasks__result-error {
  font-size: $font-size-xs;
  color: var(--p-red-500);
  margin: 0;
}

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

  &:hover:not(:disabled) {
    background: var(--p-green-500);
    color: $surface-0;
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .open-tasks__complete-label {
    display: inline;
  }

  .pi {
    font-size: $font-size-2xs;
  }
}

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

  .app-dark & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-400);
  }

  &:hover {
    border-color: var(--p-surface-400);
    color: $surface-600;
  }

  &--warn {
    transform: scale(1.15);
    border-color: var(--p-orange-400);
    color: var(--p-orange-500);
  }

  &--danger {
    transform: scale(1.25);
    border-color: var(--p-red-500);
    color: var(--p-red-500);
    background: var(--p-red-50);
  }
}

// ─── DIALOG MODE ─────────────────────────────────────────────────────────────

.task-window {
  display: flex;
  flex-direction: column;
  gap: $space-4;
  padding: $space-5;
  min-height: 0;
}

.task-window__header {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
}

.task-window__header-left {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
  flex: 1;
  min-width: 0;
}

.task-window__header-tile {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 36px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 36px;
  border-radius: $radius-md;
  flex-shrink: 0;
  // background + color come from headerTileStyle (branded tint via taskKindChipStyle)

  i {
    font-size: $font-size-base;
  }
}

.task-window__header-text {
  flex: 1;
  min-width: 0;
}

.task-window__title {
  margin: 0 0 $space-1;
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: $surface-800;
  line-height: $line-height-tight;
  // max 2 lines
  display: -webkit-box;
  // stylelint-disable-next-line value-no-vendor-prefix
  -webkit-box-orient: vertical;
  -webkit-line-clamp: 2;
  overflow: hidden;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.task-window__header-meta {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
}

.task-window__close-btn {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 28px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 28px;
  border: none;
  border-radius: $radius-md;
  background: transparent;
  color: $surface-400;
  font-size: $font-size-sm;
  cursor: pointer;
  flex-shrink: 0;
  transition: all var(--app-transition-fast);

  &:hover {
    background: var(--p-surface-100);
    color: $surface-700;

    .app-dark & {
      background: var(--p-surface-200);
      color: var(--p-surface-800);
    }
  }
}

// ─── Type chip (dialog) ────────────────────────────────────────────────────────

.task-window__type-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2px 8px;
  border-radius: $radius-sm;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  cursor: default;

  i {
    font-size: $font-size-2xs;
  }
}

// ─── Due chip (dialog) ─────────────────────────────────────────────────────────

.task-window__due-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: $font-size-xs;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-800);
  }

  &--overdue {
    color: var(--p-red-500);
    font-weight: $font-weight-medium;

    .app-dark & {
      color: var(--p-red-400);
    }
  }
}

// ─── Related entity block ──────────────────────────────────────────────────────

.task-window__related {
  background: var(--p-surface-50);
  border-radius: $radius-md;
  padding: $space-2 $space-3;

  .app-dark & {
    background: var(--p-surface-100);
  }
}

.task-window__related-inner {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.task-window__related-icon {
  font-size: $font-size-sm;
  color: $surface-500;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.task-window__related-label {
  flex: 1;
  font-size: $font-size-sm;
  color: $surface-700;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.task-window__related-link {
  display: inline-flex;
  align-items: center;
  color: var(--p-primary-color);
  text-decoration: none;
  flex-shrink: 0;

  &:hover {
    opacity: 0.8;
  }
}

.task-window__related-ext {
  font-size: $font-size-2xs;
}

// ─── Fields grid (2-col) ──────────────────────────────────────────────────────

.task-window__fields {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: $space-3;
}

.task-window__field {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.task-window__field-label {
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  text-transform: uppercase;
  letter-spacing: 0.04em;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.task-window__field-value {
  display: inline-flex;
  align-items: center;
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

.task-window__field-empty {
  color: $surface-400;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.task-window__avatar-chip {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-sm;
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-800);
  }
}

// ─── Section (description / result) ──────────────────────────────────────────

.task-window__section {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.task-window__section-label {
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  color: $surface-600;
  text-transform: uppercase;
  letter-spacing: 0.04em;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.task-window__required-star {
  color: var(--p-red-500);
  margin-left: 2px;
}

.task-window__description {
  margin: 0;
  font-size: $font-size-sm;
  color: $surface-600;
  line-height: $line-height-normal;
  word-break: break-word;
}

.w-full {
  width: 100%;
}

// ─── Footer ───────────────────────────────────────────────────────────────────

.task-window__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding-top: $space-2;
  border-top: 1px solid var(--p-surface-200);

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

.task-window__footer-right {
  display: flex;
  align-items: center;
  gap: $space-2;
}

// Delete button — PrimeVue Button (severity="danger" text). The 3-step delete
// escalation is layered on top: step 1 = scale + amber; step 2 = solid red.
.task-window__delete-btn {
  transition: transform var(--app-transition-fast);

  // Step 1 — amber warning + slight scale
  &--warn {
    transform: scale(1.05);

    :deep(.p-button-label),
    :deep(.p-button-icon) {
      color: var(--p-orange-500);
    }
  }

  // Step 2 — solid red, final confirmation
  &--danger {
    transform: scale(1.1);
    background: var(--p-red-500);
    border-color: var(--p-red-500);

    :deep(.p-button-label),
    :deep(.p-button-icon) {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      color: #fff; // solid-red destructive button: white text is a brand invariant
    }

    &:hover {
      background: var(--p-red-600);
      border-color: var(--p-red-600);
    }
  }
}
</style>
