<template>
  <!--
    OpenTasksList — spec DealCard §7.3 + §11.
    - Row order: Тип chip · Дата · Ответственный (all clickable).
    - ONE click on card = expand; collapse ONLY via click-outside.
    - Double-click title = edit (blur = exit edit).
    - «Выполнить» always visible top-right; 1st click on collapsed → expand +
      highlight result field (required); once result entered 2nd click → complete.
    - ✕ = 3-click delete (reddens, shows remaining).
    - Default OPEN (headerCollapsed=false).
  -->
  <div v-if="tasks.length > 0" ref="rootEl" class="open-tasks">
    <!-- Header: «Открытые задачи: N» — click collapses the list -->
    <button
      type="button"
      class="open-tasks__header"
      @click="headerCollapsed = !headerCollapsed"
    >
      <i class="pi pi-list-check open-tasks__header-icon" />
      <span class="open-tasks__header-label">
        {{ t('sales.deal.feed.openTasks.title', { n: tasks.length }) }}
      </span>
      <i
        class="pi open-tasks__header-chevron"
        :class="headerCollapsed ? 'pi-chevron-down' : 'pi-chevron-up'"
      />
    </button>

    <div v-if="!headerCollapsed" class="open-tasks__list">
      <div
        v-for="task in tasks"
        :key="task.id"
        :ref="(el) => registerRowRef(task.id, el as HTMLElement | null)"
        class="open-tasks__row"
        :class="{
          'open-tasks__row--overdue': isTaskOverdue(task),
          'open-tasks__row--expanded': expandedId === task.id,
        }"
        :style="taskRowStyle(task)"
      >
        <!-- ─── Compact card (collapses only on outside click) ────────────────── -->
        <div
          v-if="expandedId !== task.id"
          class="open-tasks__compact"
          role="button"
          tabindex="0"
          @click="expandTask(task.id)"
          @keydown.enter="expandTask(task.id)"
          @keydown.space.prevent="expandTask(task.id)"
        >
          <!-- Title — dbl-click = edit.
               Single click on the title area MUST still propagate up to expand the card
               (spec §11: one click anywhere expands). Stop propagation ONLY when editing. -->
          <div class="open-tasks__title-row">
            <textarea
              ref="titleInputRefs"
              class="open-tasks__title"
              :class="{ 'open-tasks__title--editing': editingTitleId === task.id }"
              :readonly="editingTitleId !== task.id"
              :value="taskTitleDraft[task.id] ?? task.title"
              rows="1"
              @dblclick.stop="startEditTitle(task)"
              @blur="saveEditTitle(task)"
              @change="(e) => onTitleChange(task.id, e)"
              @keydown.enter.prevent="saveEditTitle(task)"
              @keydown.escape="cancelEditTitle(task.id)"
              @click="(e) => { if (editingTitleId === task.id) e.stopPropagation(); }"
            />
          </div>

          <!-- Meta row: Тип chip · Дата · Ответственный — all clickable (spec §11).
               Clicks on picker buttons expand the card first, then the picker opens. -->
          <div class="open-tasks__meta">
            <!-- Type chip — click expands card + opens type picker (spec §11) -->
            <div class="open-tasks__picker-wrap">
              <button
                type="button"
                class="open-tasks__type-chip"
                :style="typeChipStyle(task.kind)"
                @click.stop="expandAndToggleTypePicker(task.id)"
              >
                <i :class="['pi', resolvedKindIcon(task.kind)]" />
                {{ kindLabel(task.kind) }}
              </button>
              <!-- Type picker popover -->
              <div
                v-if="typePickerOpenId === task.id"
                class="open-tasks__picker-popover"
                @click.stop
              >
                <button
                  v-for="opt in taskTypeOptions"
                  :key="opt.value"
                  type="button"
                  class="open-tasks__picker-option"
                  :class="{ 'open-tasks__picker-option--active': task.kind === opt.value }"
                  @click.stop="patchKind(task, opt.value)"
                >
                  <i :class="['pi', opt.icon]" />
                  {{ opt.label }}
                </button>
              </div>
            </div>

            <!-- Due date — click expands card + opens calendar (spec §11) -->
            <div class="open-tasks__picker-wrap">
              <button
                type="button"
                class="open-tasks__due"
                :class="{ 'open-tasks__due--overdue': isTaskOverdue(task) }"
                @click.stop="expandAndToggleDatePicker(task.id)"
              >
                <i class="pi pi-clock open-tasks__meta-icon" />
                {{ task.due_at ? formatDueDateShort(task.due_at) : t('activity.fields.dueAt') }}
              </button>
              <!-- DatePicker inline -->
              <div
                v-if="datePickerOpenId === task.id"
                class="open-tasks__picker-popover open-tasks__picker-popover--date"
                @click.stop
              >
                <DatePicker
                  :model-value="taskDueDrafts[task.id] ?? (task.due_at ? new Date(task.due_at) : null)"
                  inline
                  show-time
                  @date-select="(d: Date) => patchDueAt(task, d)"
                />
              </div>
            </div>

            <!-- Responsible — click expands card + opens user search (spec §11) -->
            <div class="open-tasks__picker-wrap">
              <button
                type="button"
                class="open-tasks__responsible"
                @click.stop="expandAndToggleResponsiblePicker(task.id)"
              >
                <i class="pi pi-user open-tasks__meta-icon" />
                {{ task.responsible?.full_name ?? t('activity.fields.responsible') }}
              </button>
              <!-- Responsible picker popover -->
              <div
                v-if="responsiblePickerOpenId === task.id"
                class="open-tasks__picker-popover"
                @click.stop
              >
                <div class="open-tasks__picker-search">
                  <i class="pi pi-search open-tasks__picker-search-icon" />
                  <input
                    v-model="responsibleSearch"
                    type="text"
                    class="open-tasks__picker-search-input"
                    :placeholder="t('common.search')"
                    @click.stop
                  />
                </div>
                <button
                  v-for="user in filteredUsers"
                  :key="user.id"
                  type="button"
                  class="open-tasks__picker-option"
                  :class="{ 'open-tasks__picker-option--active': task.responsible?.id === user.id }"
                  @click.stop="patchResponsible(task, user)"
                >
                  <i v-if="task.responsible?.id === user.id" class="pi pi-check" />
                  {{ user.name }}
                </button>
              </div>
            </div>
          </div>

          <!-- Right: «Выполнить» (always visible) + 3-step ✕.
               Spec §11: Выполнить in compact mode → expand card + highlight result field.
               @click.stop here so the actions area does NOT double-trigger the compact card's
               expandTask listener, but Выполнить uses onCompleteClick which handles expand. -->
          <div class="open-tasks__actions" @click.stop>
            <button
              type="button"
              class="open-tasks__complete-btn"
              @click.stop="onCompleteClick(task)"
            >
              <i class="pi pi-check" />
              <span class="open-tasks__complete-label">{{ t('activity.actions.complete') }}</span>
            </button>
            <button
              type="button"
              class="open-tasks__delete-btn"
              :class="{
                'open-tasks__delete-btn--warn': (deleteClickCounts[task.id] ?? 0) === 1,
                'open-tasks__delete-btn--danger': (deleteClickCounts[task.id] ?? 0) >= 2,
              }"
              :title="deleteTooltip(task.id)"
              @click="handleDeleteClick(task)"
            >
              <i class="pi pi-times" />
            </button>
          </div>
        </div>

        <!-- ─── Expanded view ────────────────────────────────────────────────── -->
        <Transition name="tqf-slide">
          <div
            v-if="expandedId === task.id"
            class="open-tasks__expanded-wrap"
            @click.stop
          >
            <!-- Result textarea + Выполнить in expanded mode -->
            <div class="open-tasks__expanded-header">
              <!-- Task title (read-only) -->
              <p class="open-tasks__expanded-title">{{ task.title }}</p>
              <!-- Always-visible actions in expanded mode -->
              <div class="open-tasks__expanded-actions">
                <button
                  type="button"
                  class="open-tasks__complete-btn"
                  :disabled="completingId === task.id"
                  @click.stop="onCompleteSubmit(task)"
                >
                  <i class="pi pi-check" />
                  <span class="open-tasks__complete-label">{{ t('activity.actions.complete') }}</span>
                </button>
                <button
                  type="button"
                  class="open-tasks__delete-btn"
                  :class="{
                    'open-tasks__delete-btn--warn': (deleteClickCounts[task.id] ?? 0) === 1,
                    'open-tasks__delete-btn--danger': (deleteClickCounts[task.id] ?? 0) >= 2,
                  }"
                  :title="deleteTooltip(task.id)"
                  @click="handleDeleteClick(task)"
                >
                  <i class="pi pi-times" />
                </button>
                <!-- Close / collapse back to compact -->
                <button
                  type="button"
                  class="open-tasks__collapse-btn"
                  :title="t('common.close')"
                  @click.stop="expandedId = null"
                >
                  <i class="pi pi-times" />
                </button>
              </div>
            </div>

            <!-- Meta row also visible in expanded state (overdue date stays red) -->
            <div class="open-tasks__meta open-tasks__meta--expanded">
              <span class="open-tasks__type-chip open-tasks__type-chip--static" :style="typeChipStyle(task.kind)">
                <i :class="['pi', resolvedKindIcon(task.kind)]" />
                {{ kindLabel(task.kind) }}
              </span>
              <span
                class="open-tasks__due"
                :class="{ 'open-tasks__due--overdue': isTaskOverdue(task) }"
              >
                <i class="pi pi-clock open-tasks__meta-icon" />
                {{ task.due_at ? formatDueDateShort(task.due_at) : t('activity.fields.dueAt') }}
              </span>
              <span v-if="task.responsible" class="open-tasks__responsible open-tasks__responsible--static">
                <i class="pi pi-user open-tasks__meta-icon" />
                {{ task.responsible.full_name }}
              </span>
            </div>

            <!-- Result textarea (required field — highlighted when Выполнить clicked without value) -->
            <div class="open-tasks__result-wrap">
              <textarea
                :ref="(el) => registerResultRef(task.id, el as HTMLTextAreaElement | null)"
                v-model="taskResultDrafts[task.id]"
                class="open-tasks__result-input"
                :class="{ 'open-tasks__result-input--required': resultRequired === task.id }"
                :placeholder="t('activity.fields.resultPlaceholder')"
                rows="2"
                @click.stop
                @input="() => { if (resultRequired === task.id && taskResultDrafts[task.id]?.trim()) resultRequired = null }"
              />
              <p v-if="resultRequired === task.id" class="open-tasks__result-error">
                {{ t('activity.fields.resultRequired') }}
              </p>
            </div>
          </div>
        </Transition>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, reactive, computed, nextTick, onMounted, onBeforeUnmount } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import DatePicker from 'primevue/datepicker'
import { kindIcon } from '@/utils/activity'
import { activityApi } from '@/api/activity'
import type { ActivityDto, ActivityKind, ActivityTargetType } from '@/entities/activity'

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  tasks: ActivityDto[]
  targetType: ActivityTargetType
  targetId: number
  /** Optional user list for responsible picker */
  usersList?: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{
  completed: [activity: ActivityDto]
  deleted: [activityId: number]
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()

const rootEl = ref<HTMLElement | null>(null)
const expandedId = ref<number | null>(null)
const headerCollapsed = ref(false) // default OPEN per spec

// Map task id → row element (for click-outside path check)
const rowRefs = reactive<Record<number, HTMLElement | null>>({})
function registerRowRef(id: number, el: HTMLElement | null) {
  rowRefs[id] = el
}

// Map task id → result textarea element (for focus-on-required)
const resultRefs = reactive<Record<number, HTMLTextAreaElement | null>>({})
function registerResultRef(id: number, el: HTMLTextAreaElement | null) {
  resultRefs[id] = el
}

// 3-step delete counter per task id
const deleteClickCounts = reactive<Record<number, number>>({})

// Result text drafts per task id
const taskResultDrafts = reactive<Record<number, string>>({})

// Which expanded task has the result field highlighted as required
const resultRequired = ref<number | null>(null)

// Which task is currently being completed (pending API call)
const completingId = ref<number | null>(null)

// ─── Click-outside (BUG C fix) ───────────────────────────────────────────────
// Problem: when the user clicks to expand a task, Vue re-renders synchronously,
// removing the compact card from the DOM. The document click listener then sees
// el.contains(target) === false (detached node) and fires collapseAll() — instant
// collapse on the same click that opened the card.
//
// Fix: use event.composedPath() which captures the path BEFORE re-render, so even
// if the clicked element is removed from DOM, the path still includes the root element.
// Additionally: NEVER collapse when there's an active picker open.

function onDocumentClick(event: MouseEvent) {
  if (!rootEl.value) return
  // composedPath() contains the full event path captured at dispatch time,
  // even after elements are removed from the DOM by re-renders.
  const path = event.composedPath()
  if (path.includes(rootEl.value)) {
    // Click was inside the open-tasks root — never collapse
    return
  }
  // Click was genuinely outside — collapse expanded task and close pickers
  collapseAll()
}

onMounted(() => {
  document.addEventListener('click', onDocumentClick)
})

onBeforeUnmount(() => {
  document.removeEventListener('click', onDocumentClick)
})

// ─── Title inline edit state ──────────────────────────────────────────────────

const editingTitleId = ref<number | null>(null)
const taskTitleDraft = reactive<Record<number, string>>({})

function startEditTitle(task: ActivityDto) {
  editingTitleId.value = task.id
  if (taskTitleDraft[task.id] === undefined) {
    taskTitleDraft[task.id] = task.title
  }
}

function onTitleChange(taskId: number, e: Event) {
  taskTitleDraft[taskId] = (e.target as HTMLTextAreaElement).value
}

async function saveEditTitle(task: ActivityDto) {
  if (editingTitleId.value !== task.id) return
  const draft = taskTitleDraft[task.id] ?? task.title
  editingTitleId.value = null
  if (draft.trim() && draft !== task.title) {
    try {
      await activityApi.updateActivity(task.id, { title: draft.trim() })
    } catch {
      taskTitleDraft[task.id] = task.title
    }
  }
}

function cancelEditTitle(taskId: number) {
  delete taskTitleDraft[taskId]
  editingTitleId.value = null
}

// ─── Type picker ──────────────────────────────────────────────────────────────

const typePickerOpenId = ref<number | null>(null)

const taskTypeOptions = computed(() => [
  { value: 'task' as ActivityKind, label: t('activity.kinds.task'), icon: 'pi-check-square' },
  { value: 'call' as ActivityKind, label: t('activity.kinds.call'), icon: 'pi-phone' },
  { value: 'meeting' as ActivityKind, label: t('activity.kinds.meeting'), icon: 'pi-calendar' },
  { value: 'follow_up' as ActivityKind, label: t('activity.kinds.follow_up'), icon: 'pi-reply' },
])

function toggleTypePicker(taskId: number) {
  // Close others first
  datePickerOpenId.value = null
  responsiblePickerOpenId.value = null
  typePickerOpenId.value = typePickerOpenId.value === taskId ? null : taskId
}

async function patchKind(task: ActivityDto, kind: ActivityKind) {
  typePickerOpenId.value = null
  try {
    await activityApi.updateActivity(task.id, { kind })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

// ─── Date picker ──────────────────────────────────────────────────────────────

const datePickerOpenId = ref<number | null>(null)
const taskDueDrafts = reactive<Record<number, Date | null>>({})

function toggleDatePicker(taskId: number) {
  typePickerOpenId.value = null
  responsiblePickerOpenId.value = null
  datePickerOpenId.value = datePickerOpenId.value === taskId ? null : taskId
}

async function patchDueAt(task: ActivityDto, date: Date | null) {
  datePickerOpenId.value = null
  try {
    await activityApi.updateActivity(task.id, { due_at: date ? date.toISOString() : null })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

// ─── Responsible picker ───────────────────────────────────────────────────────

const responsiblePickerOpenId = ref<number | null>(null)
const responsibleSearch = ref('')

const filteredUsers = computed(() => {
  const list = props.usersList ?? []
  const q = responsibleSearch.value.toLowerCase()
  if (!q) return list
  return list.filter((u) => u.name.toLowerCase().includes(q))
})

function toggleResponsiblePicker(taskId: number) {
  typePickerOpenId.value = null
  datePickerOpenId.value = null
  responsibleSearch.value = ''
  responsiblePickerOpenId.value = responsiblePickerOpenId.value === taskId ? null : taskId
}

async function patchResponsible(task: ActivityDto, user: { id: number; name: string }) {
  responsiblePickerOpenId.value = null
  try {
    await activityApi.updateActivity(task.id, { responsible_id: user.id })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

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
const KIND_COLORS: Partial<Record<ActivityKind, string>> = {
  call: '#2A6FDB',
  meeting: '#1F8A5B',
  follow_up: '#E8A317',
  presentation: '#E8A317',
  task: '#172747',
}

function typeChipStyle(kind: ActivityKind): Record<string, string> {
  const color = KIND_COLORS[kind]
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

function formatDueDateShort(dateStr: string): string {
  const d = new Date(dateStr)
  return d.toLocaleDateString('ru-RU', { day: '2-digit', month: '2-digit', year: 'numeric' })
}

// ─── Overdue check (BUG B fix) ───────────────────────────────────────────────
// Backend `is_overdue` may be stale (computed at fetch time). Re-derive locally
// using date-only comparison (no time) so the overdue flag is consistent regardless
// of what time of day the page was loaded and regardless of collapsed/expanded state.

function isTaskOverdue(task: ActivityDto): boolean {
  if (task.is_closed) return false
  if (!task.due_at) return false
  // Compare date parts only (strip time) to avoid timezone edge cases
  const todayStr = new Date().toISOString().slice(0, 10) // YYYY-MM-DD
  const dueStr = task.due_at.slice(0, 10) // YYYY-MM-DD
  return dueStr < todayStr
}

// ─── Task row border style (tinted to task type per spec §11) ────────────────

function taskRowStyle(task: ActivityDto): Record<string, string> {
  const color = KIND_COLORS[task.kind]
  const isExpanded = expandedId.value === task.id
  if (!color) {
    return {
      border: `1px solid var(--p-surface-200)`,
      borderRadius: 'var(--p-border-radius-md, 8px)',
      marginBottom: '6px',
    }
  }
  // In expanded state use full type color; compact = 45% mix (per HTML mockup)
  const borderColor = isExpanded
    ? color
    : `color-mix(in srgb, ${color} 45%, var(--p-surface-200))`
  return {
    border: `1px solid ${borderColor}`,
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    borderRadius: '8px',
    marginBottom: '6px',
    overflow: 'visible',
  }
}

// ─── Expand / collapse ────────────────────────────────────────────────────────

function expandTask(id: number) {
  // Close all pickers
  typePickerOpenId.value = null
  datePickerOpenId.value = null
  responsiblePickerOpenId.value = null
  resultRequired.value = null
  expandedId.value = id
}

function collapseAll() {
  expandedId.value = null
  typePickerOpenId.value = null
  datePickerOpenId.value = null
  responsiblePickerOpenId.value = null
  resultRequired.value = null
}

// ─── Expand-and-open picker helpers (meta row clicks in compact mode) ─────────
// Spec §11: ONE click on the card expands it. Meta clicks should also expand
// the card and then open the appropriate picker in the compact→expanded flow.
// Since pickers are positioned relative to their wrapper, we expand first then
// open the picker via the existing toggle functions (which work in both views).

function expandAndToggleTypePicker(taskId: number) {
  if (expandedId.value !== taskId) {
    expandedId.value = taskId
  }
  toggleTypePicker(taskId)
}

function expandAndToggleDatePicker(taskId: number) {
  if (expandedId.value !== taskId) {
    expandedId.value = taskId
  }
  toggleDatePicker(taskId)
}

function expandAndToggleResponsiblePicker(taskId: number) {
  if (expandedId.value !== taskId) {
    expandedId.value = taskId
  }
  toggleResponsiblePicker(taskId)
}

// ─── «Выполнить» flow (BUG A fix) ────────────────────────────────────────────
// Spec §11:
//   • Compact (collapsed) card: Выполнить → expand card + highlight result field as required.
//   • Expanded card: Выполнить → if result is empty, highlight field (red border) + do NOT complete;
//     if result is filled, call complete endpoint, remove task from list, refresh feed.
//   • Clicking Выполнить must NEVER just collapse the card.

function onCompleteClick(task: ActivityDto) {
  // In compact mode: expand + mark result as required to draw attention
  expandTask(task.id)
  // After DOM update, mark result as required and focus the textarea
  nextTick(() => {
    resultRequired.value = task.id
    const el = resultRefs[task.id]
    if (el) el.focus()
  })
}

async function onCompleteSubmit(task: ActivityDto) {
  const resultText = taskResultDrafts[task.id]?.trim() ?? ''
  // If result is empty, highlight the field and do NOT complete
  if (!resultText) {
    resultRequired.value = task.id
    await nextTick()
    const el = resultRefs[task.id]
    if (el) el.focus()
    return
  }
  // Call complete endpoint
  if (completingId.value !== null) return
  completingId.value = task.id
  try {
    const updated = await activityApi.completeActivity(task.id, resultText)
    // Clear local draft
    delete taskResultDrafts[task.id]
    resultRequired.value = null
    expandedId.value = null
    // Emit so parent (DealPage/index.vue) updates feedComposable → openTasks drops this task
    // and the feed shows the completed task
    emit('completed', updated)
    toast.add({ severity: 'success', summary: t('tasks.board.card.completed'), life: 2000 })
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  } finally {
    completingId.value = null
  }
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
    setTimeout(() => {
      if ((deleteClickCounts[task.id] ?? 0) <= current + 1) {
        deleteClickCounts[task.id] = 0
      }
    }, 3000)
  } else {
    deleteClickCounts[task.id] = 0
    onDelete(task.id)
  }
}

// ─── Handlers ─────────────────────────────────────────────────────────────────

function onDelete(id: number) {
  expandedId.value = null
  emit('deleted', id)
}
</script>

<style lang="scss" scoped>
.open-tasks {
  background: var(--p-surface-50); // --c-feed/--c-sub neutral tone
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-100);
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
  cursor: pointer;
  border-top: none;
  border-left: none;
  border-right: none;
  width: 100%;
  text-align: left;

  .app-dark & {
    background: var(--p-surface-100);
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

// ─── List ─────────────────────────────────────────────────────────────────────

.open-tasks__list {
  display: flex;
  flex-direction: column;
  max-height: 260px;
  overflow-y: auto;
  overflow-x: visible; // allow picker popovers to overflow
  padding: $space-2 $space-3 $space-1;
  scrollbar-width: none;
  -ms-overflow-style: none;

  &::-webkit-scrollbar {
    width: 0;
    height: 0;
    display: none;
  }
}

// ─── Row — styled card with type-colored border (inline style handles color) ──

.open-tasks__row {
  // Border/radius/margin applied via :style binding (taskRowStyle) — type-color.
  // We only add positional context here.
  position: relative;

  &--overdue {
    .open-tasks__compact {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(239, 68, 68, 0.04);
    }
    .open-tasks__expanded-wrap {
      // stylelint-disable-next-line scale-unlimited/declaration-strict-value
      background: rgba(239, 68, 68, 0.04);
    }
  }
}

// ─── Compact card ─────────────────────────────────────────────────────────────

.open-tasks__compact {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  padding: $space-2 $space-3; // matches HTML mockup: 8px 10px
  cursor: pointer;
  transition: background var(--app-transition-fast);
  position: relative;
  background: var(--p-surface-50); // --c-sub equivalent

  .app-dark & {
    background: var(--p-surface-100);
  }

  &:hover {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }
}

// ─── Title row (dbl-click = edit) ────────────────────────────────────────────

.open-tasks__title-row {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding-right: 120px; // reserve for «Выполнить» + ✕ action buttons
}

.open-tasks__title {
  display: block;
  width: 100%;
  font-size: $font-size-sm;
  color: $surface-700;
  font-weight: $font-weight-medium;
  background: transparent;
  border: 1px solid transparent;
  border-radius: $radius-sm;
  padding: 0;
  resize: none;
  font-family: inherit;
  line-height: $line-height-normal;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  min-height: 20px;
  overflow: hidden;
  cursor: default;

  .app-dark & {
    color: var(--p-surface-200);
  }

  &--editing {
    border-color: var(--p-primary-color);
    background: var(--p-surface-0);
    padding: 0 $space-1;
    cursor: text;

    .app-dark & {
      background: var(--p-surface-200);
    }
  }
}

// ─── Meta row: type chip · date · responsible ────────────────────────────────

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

// Wrapper for each picker button + popover
.open-tasks__picker-wrap {
  position: relative;
}

// ─── Type chip (clickable, DealCard §11) ──────────────────────────────────────

.open-tasks__type-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 2px 6px;
  border-radius: $radius-sm;
  font-size: $font-size-2xs;
  font-weight: $font-weight-semibold;
  cursor: pointer;
  border: none;
  transition: opacity var(--app-transition-fast);
  // background and color set via :style (dynamic per kind)

  &:hover {
    opacity: 0.8;
  }

  i {
    font-size: $font-size-3xs;
  }

  &--static {
    cursor: default;
    pointer-events: none;
  }
}

// ─── Date / Responsible buttons ───────────────────────────────────────────────

.open-tasks__due,
.open-tasks__responsible {
  font-size: $font-size-xs;
  color: $surface-400;
  display: inline-flex;
  align-items: center;
  gap: 3px;
  white-space: nowrap;
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  transition: color var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-400);
  }

  &:hover {
    color: $surface-600;

    .app-dark & {
      color: var(--p-surface-300);
    }
  }

  &--static {
    cursor: default;
    pointer-events: none;
  }
}

.open-tasks__due--overdue {
  color: var(--p-red-500);
  font-weight: $font-weight-medium;

  // Dark mode: surface-400 from parent .open-tasks__due beats red-500 here
  // (specificity: .app-dark .open-tasks__due > .open-tasks__due--overdue).
  // Explicit dark override restores readable red — red-400 has better contrast
  // on dark surfaces than red-500.
  .app-dark & {
    color: var(--p-red-400);
  }

  // Override hover so it stays red even in expanded/static meta row
  &:hover {
    color: var(--p-red-600);

    .app-dark & {
      color: var(--p-red-300);
    }
  }
}

.open-tasks__meta-icon {
  font-size: $font-size-3xs;
}

// ─── Picker popovers ──────────────────────────────────────────────────────────

.open-tasks__picker-popover {
  position: absolute;
  bottom: calc(100% + 4px);
  left: 0;
  z-index: 100;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  box-shadow: $shadow-md;
  min-width: 160px;
  padding: $space-1;
  max-height: 240px;
  overflow-y: auto;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }

  .app-dark & {
    border-color: var(--p-surface-700);
  }

  // Date picker variant: wider
  &--date {
    min-width: 280px;
    padding: $space-2;
  }
}

.open-tasks__picker-search {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-1 $space-2;
  border-bottom: 1px solid var(--p-surface-100);
  margin-bottom: $space-1;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.open-tasks__picker-search-icon {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
}

.open-tasks__picker-search-input {
  flex: 1;
  border: none;
  outline: none;
  background: transparent;
  font-size: $font-size-sm;
  color: $surface-700;
  font-family: inherit;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.open-tasks__picker-option {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-1 $space-2;
  border: none;
  background: none;
  border-radius: $radius-sm;
  font-size: $font-size-sm;
  color: $surface-700;
  cursor: pointer;
  text-align: left;
  transition: background var(--app-transition-fast);

  .app-dark & {
    color: var(--p-surface-200);
  }

  &:hover {
    background: var(--p-surface-100);

    .app-dark & {
      background: var(--p-surface-200);
    }
  }

  &--active {
    background: var(--p-primary-50);
    color: var(--p-primary-700);
    font-weight: $font-weight-medium;

    .app-dark & {
      background: var(--p-surface-200);
      color: var(--p-primary-300);
    }
  }

  i {
    font-size: $font-size-xs;
    flex-shrink: 0;
    width: 14px;
  }
}

// ─── Actions (top-right, ALWAYS visible) ─────────────────────────────────────

.open-tasks__actions {
  position: absolute;
  top: $space-2;
  right: $space-3; // matches padding of compact card
  display: flex;
  align-items: center;
  gap: $space-1;
}

// ─── «Выполнить» — always visible (NOT hover-only) per spec §11 ──────────────

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
    color: $sidebar-text-active;
  }

  &:disabled {
    opacity: 0.5;
    cursor: not-allowed;
  }

  .open-tasks__complete-label {
    // Always show label (not hover-only per spec §11)
    display: inline;
  }

  .pi {
    font-size: $font-size-2xs;
  }
}

// ─── 3-step delete (small gray ✕) ────────────────────────────────────────────

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

// ─── Expanded view ─────────────────────────────────────────────────────────────

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
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding-right: 0; // actions are inline-flex, not absolute in expanded mode
}

.open-tasks__expanded-title {
  flex: 1;
  margin: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  line-height: $line-height-normal;

  .app-dark & {
    color: var(--p-surface-200);
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
      color: var(--p-surface-200);
      background: var(--p-surface-200);
    }
  }
}

// ─── Result textarea ──────────────────────────────────────────────────────────

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
    background: var(--p-surface-200);
    color: var(--p-surface-100);
    border-color: var(--p-surface-600);
  }

  &:focus {
    border-color: var(--p-primary-color);
  }

  // BUG A fix: required highlight — red border when Выполнить clicked without result
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

// ─── Slide transition ─────────────────────────────────────────────────────────

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
