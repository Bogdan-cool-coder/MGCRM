<template>
  <!-- ═══════════════════════════════════════════════════════════════
       TaskQuickForm — AMO-style compact task card.
       Modes:
         mode="create"  — new task card (title + kind + due shortcut)
         mode="complete"— execute existing task (result + reschedule)
       Entity-agnostic: pass targetType/targetId for context binding.

       Single root wrapper required so <Transition> can animate correctly.
  ══════════════════════════════════════════════════════════════════ -->
  <div class="tqf-root">
  <div class="tqf" :class="{ 'tqf--completing': mode === 'complete' }">
    <!-- ── Header row ─────────────────────────────────────────────── -->
    <div class="tqf__header">
      <!-- Kind icon chip -->
      <button
        v-if="mode === 'create'"
        type="button"
        class="tqf__kind-chip"
        :title="t('tasks.quick.changeKind')"
        @click="kindMenuRef?.toggle($event)"
      >
        <i :class="currentKindIcon" class="tqf__kind-icon" />
        <i class="pi pi-chevron-down tqf__kind-caret" />
      </button>
      <div v-else class="tqf__kind-chip tqf__kind-chip--static">
        <i :class="currentKindIcon" class="tqf__kind-icon" />
      </div>

      <!-- Due + Responsible row -->
      <div class="tqf__meta">
        <span
          v-if="dueLabel"
          class="tqf__due"
          :class="{ 'tqf__due--overdue': isOverdue }"
        >
          <i class="pi pi-clock tqf__due-icon" />
          {{ dueLabel }}
        </span>
        <span v-if="responsibleLabel" class="tqf__responsible">
          · {{ responsibleLabel }}
        </span>
      </div>

      <!-- Close button (when used inline / embedded) -->
      <button
        v-if="closable"
        type="button"
        class="tqf__close"
        :title="t('common.close')"
        @click="emit('cancel')"
      >
        <i class="pi pi-times" />
      </button>
    </div>

    <!-- ── Title (create) or read-only task name (complete) ──────── -->
    <div class="tqf__title-wrap">
      <InputText
        v-if="mode === 'create'"
        ref="titleInputRef"
        v-model="form.title"
        class="tqf__title-input"
        :class="{ 'p-invalid': titleError }"
        :placeholder="t('tasks.quick.titlePlaceholder')"
        @keydown="onTitleKeydown"
      />
      <p v-else class="tqf__task-title">{{ activity?.title }}</p>
      <small v-if="titleError" class="tqf__error">{{ titleError }}</small>
    </div>

    <!-- ── Required visible due-date field (create + requireDueDate) ─
         In NoTask-widget context the server "deal has a task" criterion is
         Deal::nextTask() with whereNotNull('due_at'); a task without due_at
         leaves the deal in the "without tasks" list. So here the date is
         explicit, required, prefilled to tomorrow — no silent default. -->
    <div v-if="mode === 'create' && requireDueDate" class="tqf__due-field">
      <label for="tqf-required-due" class="tqf__due-field-label">
        {{ t('tasks.quick.dueDateLabel') }}
      </label>
      <DatePicker
        input-id="tqf-required-due"
        v-model="form.due_at"
        show-time
        hour-format="24"
        date-format="dd.mm.yy"
        show-icon
        icon-display="input"
        class="tqf__due-field-input"
        :class="{ 'p-invalid': dueError }"
        :placeholder="t('tasks.quick.pickDate')"
      />
      <small v-if="dueError" class="tqf__error">{{ dueError }}</small>
    </div>

    <!-- ── Result text (complete mode only) ──────────────────────── -->
    <div v-if="mode === 'complete'" class="tqf__result">
      <Textarea
        v-model="form.result_text"
        class="tqf__result-input"
        :rows="2"
        auto-resize
        :placeholder="t('tasks.quick.resultPlaceholder')"
      />
    </div>

    <!-- ── Footer row ─────────────────────────────────────────────── -->
    <div class="tqf__footer">
      <!-- Left side: checkbox (complete) + kind select (create) + quick dates -->
      <div class="tqf__footer-left">
        <!-- Complete mode: checkbox acts as "done" toggle -->
        <Checkbox
          v-if="mode === 'complete'"
          v-model="doneChecked"
          :binary="true"
          :title="t('tasks.quick.markDone')"
          class="tqf__done-check"
        />

        <!-- Create mode: kind select pill -->
        <button
          v-if="mode === 'create'"
          type="button"
          class="tqf__kind-label"
          @click="kindMenuRef?.toggle($event)"
        >
          {{ currentKindLabel }}
        </button>

        <!-- Quick date shortcuts -->
        <div class="tqf__quick-dates">
          <button
            type="button"
            class="tqf__quick-btn"
            :class="{ 'tqf__quick-btn--active': activeDateShortcut === 'tomorrow' }"
            @click="applyDateShortcut('tomorrow')"
          >
            {{ t('tasks.quick.tomorrow') }}
          </button>
          <button
            type="button"
            class="tqf__quick-btn"
            :class="{ 'tqf__quick-btn--active': activeDateShortcut === 'week' }"
            @click="applyDateShortcut('week')"
          >
            {{ t('tasks.quick.nextWeek') }}
          </button>
          <button
            type="button"
            class="tqf__quick-btn"
            :class="{ 'tqf__quick-btn--active': activeDateShortcut === 'month' }"
            @click="applyDateShortcut('month')"
          >
            {{ t('tasks.quick.nextMonth') }}
          </button>

          <!-- Inline DatePicker trigger via label+hidden input -->
          <label
            class="tqf__quick-btn tqf__quick-btn--calendar"
            :class="{ 'tqf__quick-btn--active': activeDateShortcut === 'custom' }"
            :title="t('tasks.quick.pickDate')"
            for="tqf-datepicker-input"
          >
            <i class="pi pi-calendar" />
          </label>
          <!-- Hidden DatePicker — triggered via label click -->
          <DatePicker
            input-id="tqf-datepicker-input"
            v-model="form.due_at"
            show-time
            hour-format="24"
            date-format="dd.mm.yy"
            class="tqf__hidden-picker"
            @date-select="onCustomDatePicked"
          />
        </div>
      </div>

      <!-- Right side: Delete (complete mode) + primary action -->
      <div class="tqf__footer-right">
        <button
          v-if="mode === 'complete' && activity"
          type="button"
          class="tqf__delete-btn"
          :title="t('tasks.quick.delete')"
          @click="onDelete"
        >
          <i class="pi pi-trash" />
        </button>
        <Button
          class="tqf__submit-btn"
          :label="mode === 'create' ? t('tasks.quick.create') : t('tasks.quick.complete')"
          :icon="mode === 'create' ? 'pi pi-plus' : 'pi pi-check'"
          :loading="mutation.isPending.value"
          severity="primary"
          size="small"
          @click="onSubmit"
        />
      </div>
    </div>
  </div>

  <!-- Kind picker popover (create mode) -->
  <Menu ref="kindMenuRef" :model="kindMenuItems" popup />
  </div><!-- /.tqf-root -->
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Checkbox from 'primevue/checkbox'
import DatePicker from 'primevue/datepicker'
import Menu from 'primevue/menu'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import { kindIcon, formatDueDateOperational } from '@/utils/activity'
import type {
  ActivityDto,
  ActivityKind,
  ActivityTargetType,
} from '@/entities/activity'

// ─── Props ────────────────────────────────────────────────────────────────────

const props = withDefaults(
  defineProps<{
    /**
     * "create" — new task form (title + kind + due + responsible)
     * "complete" — execute existing task (result + reschedule + delete)
     */
    mode?: 'create' | 'complete'

    /** Existing activity to complete (required when mode="complete") */
    activity?: ActivityDto | null

    /** Context binding for new task (entity-agnostic) */
    targetType?: ActivityTargetType | null
    targetId?: number | null

    /** Pre-select a kind when mode=create */
    defaultKind?: ActivityKind

    /** Pre-fill responsible (user id) when mode=create */
    defaultResponsibleId?: number | null
    defaultResponsibleName?: string | null

    /** Show ✕ button to dismiss the form */
    closable?: boolean

    /** Auto-focus title field on mount (useful in inline-creation contexts) */
    autoFocus?: boolean

    /**
     * Make due_at a visible, required field prefilled with "tomorrow".
     * Used by the NoTask widget where the server counts a deal as "has a task"
     * only when the activity has a due_at (Deal::nextTask → whereNotNull('due_at')).
     * Default false → existing forms elsewhere keep due optional.
     */
    requireDueDate?: boolean
  }>(),
  {
    mode: 'create',
    activity: null,
    targetType: null,
    targetId: null,
    defaultKind: 'task',
    defaultResponsibleId: null,
    defaultResponsibleName: null,
    closable: false,
    autoFocus: false,
    requireDueDate: false,
  },
)

// ─── Emits ────────────────────────────────────────────────────────────────────

const emit = defineEmits<{
  /** Emitted after successful create */
  created: [activity: ActivityDto]
  /** Emitted after successful complete (with optional result) */
  completed: [activity: ActivityDto]
  /** Emitted after user clicks delete (caller handles confirm) */
  delete: [activityId: number]
  /** Emitted when user dismisses the form without action */
  cancel: []
}>()

// ─── i18n ─────────────────────────────────────────────────────────────────────

const { t } = useI18n()

// ─── Refs ─────────────────────────────────────────────────────────────────────

const titleInputRef = ref<InstanceType<typeof InputText> | null>(null)
const kindMenuRef = ref<InstanceType<typeof Menu> | null>(null)

// ─── Form state ───────────────────────────────────────────────────────────────

type DateShortcut = 'tomorrow' | 'week' | 'month' | 'custom' | null

interface QuickForm {
  kind: ActivityKind
  title: string
  due_at: Date | null
  result_text: string
}

/** Tomorrow at 09:00 (local) — prefill for the required-due-date context. */
function tomorrowAt9(): Date {
  const d = new Date()
  d.setHours(9, 0, 0, 0)
  d.setDate(d.getDate() + 1)
  return d
}

function buildDefaultForm(): QuickForm {
  return {
    kind: props.defaultKind ?? 'task',
    title: '',
    // In require-due-date mode, prefill "tomorrow" so the deal leaves the
    // "without tasks" list on the server (whereNotNull('due_at')). Editable.
    due_at: props.requireDueDate ? tomorrowAt9() : null,
    result_text: '',
  }
}

const form = ref<QuickForm>(buildDefaultForm())
const titleError = ref<string | null>(null)
const dueError = ref<string | null>(null)
const doneChecked = ref(false)
const activeDateShortcut = ref<DateShortcut>(null)

const mutation = useMutation<ActivityDto>()

// ─── Computed helpers ─────────────────────────────────────────────────────────

const ALL_KINDS: ActivityKind[] = ['call', 'meeting', 'task', 'note', 'follow_up']

const currentKindIcon = computed(() => {
  const k = props.mode === 'complete' ? (props.activity?.kind ?? 'task') : form.value.kind
  return kindIcon(k)
})

const currentKindLabel = computed(() =>
  t(`tasks.board.taskTypes.${form.value.kind}`),
)

const kindMenuItems = computed(() =>
  ALL_KINDS.map((k) => ({
    label: t(`tasks.board.taskTypes.${k}`),
    icon: kindIcon(k),
    command: () => {
      form.value.kind = k
    },
  })),
)

const responsibleLabel = computed(() =>
  props.defaultResponsibleName ?? null,
)

const dueLabel = computed(() => {
  const d = form.value.due_at
  if (!d) return null
  // B32: format in operational tz (Asia/Dubai) so the label matches the server deadline.
  return formatDueDateOperational(d.toISOString(), t)
})

const isOverdue = computed(() => {
  if (props.mode === 'complete' && props.activity) {
    return props.activity.is_overdue && !props.activity.is_closed
  }
  return false
})

// ─── Date shortcuts ───────────────────────────────────────────────────────────

function applyDateShortcut(shortcut: 'tomorrow' | 'week' | 'month') {
  const d = new Date()
  d.setHours(9, 0, 0, 0)
  if (shortcut === 'tomorrow') {
    d.setDate(d.getDate() + 1)
  } else if (shortcut === 'week') {
    d.setDate(d.getDate() + 7)
  } else {
    d.setMonth(d.getMonth() + 1)
  }
  form.value.due_at = d
  activeDateShortcut.value = activeDateShortcut.value === shortcut ? null : shortcut
}

function onCustomDatePicked() {
  activeDateShortcut.value = 'custom'
}

// Watch manual date change to clear shortcut highlight
watch(
  () => form.value.due_at,
  () => {
    // if programmatically cleared, reset highlight
    if (!form.value.due_at) activeDateShortcut.value = null
    // clear the required-due error as soon as the user provides a date
    if (form.value.due_at) dueError.value = null
  },
)

// ─── Keyboard handling ────────────────────────────────────────────────────────

function onTitleKeydown(e: KeyboardEvent) {
  if (e.key === 'Enter') {
    e.preventDefault()
    void onSubmit()
  } else if (e.key === 'Escape') {
    e.preventDefault()
    emit('cancel')
  }
}

// ─── Submit ───────────────────────────────────────────────────────────────────

async function onSubmit() {
  if (props.mode === 'create') {
    await doCreate()
  } else {
    await doComplete()
  }
}

async function doCreate() {
  titleError.value = null
  dueError.value = null
  if (!form.value.title.trim()) {
    titleError.value = t('errors.validation')
    await nextTick()
    if (titleInputRef.value) {
      const el = (titleInputRef.value as unknown as { $el?: HTMLElement }).$el
      if (el instanceof HTMLElement) el.focus()
    }
    return
  }

  // In require-due-date context, block creation without a date: a task without
  // due_at would not count on the server → deal bounces back to the list.
  if (props.requireDueDate && !form.value.due_at) {
    dueError.value = t('tasks.quick.dueDateRequired')
    return
  }

  const result = await mutation.run(() =>
    activityApi.createActivity({
      kind: form.value.kind,
      title: form.value.title.trim(),
      due_at: form.value.due_at ? form.value.due_at.toISOString() : null,
      responsible_id: props.defaultResponsibleId ?? null,
      target_type: props.targetType ?? null,
      target_id: props.targetId ?? null,
    }),
  )

  emit('created', result)
  resetForm()
}

async function doComplete() {
  if (!props.activity) return

  const result = await mutation.run(() => {
    const resultText = form.value.result_text.trim() || null
    // If user chose to reschedule instead of completing
    if (!doneChecked.value && form.value.due_at) {
      return activityApi.updateActivity(props.activity!.id, {
        due_at: form.value.due_at.toISOString(),
        result_text: resultText,
      })
    }
    return activityApi.completeActivity(props.activity!.id, resultText)
  })

  emit('completed', result)
}

function onDelete() {
  if (props.activity) {
    emit('delete', props.activity.id)
  }
}

function resetForm() {
  form.value = buildDefaultForm()
  titleError.value = null
  dueError.value = null
  activeDateShortcut.value = null
  doneChecked.value = false
}

// ─── Auto-focus ───────────────────────────────────────────────────────────────

onMounted(async () => {
  if (props.autoFocus && props.mode === 'create') {
    await nextTick()
    if (titleInputRef.value) {
      const el = (titleInputRef.value as unknown as { $el?: HTMLElement }).$el
      if (el instanceof HTMLElement) el.focus()
    }
  }
  // In complete mode do NOT pre-fill due_at — the date picker is used only
  // when the user explicitly wants to reschedule. Pre-filling caused doComplete()
  // to reschedule (instead of complete) for every task that had a due_at set.
})

// No watcher needed for complete mode due_at sync (intentionally removed).
</script>

<style lang="scss" scoped>
// Single-root wrapper — needed so <Transition> in parent has a single element root.
// Overflow hidden allows the tqf-slide max-height animation to work correctly.
.tqf-root {
  overflow: hidden;
}

.tqf {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-md;
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  transition: box-shadow var(--app-transition-fast);

  // theme-reactive: $surface-card = navy card, $surface-200 = soft navy border in dark.
  // Dead `:global(.app-dark) &` override removed (compiled to bare .app-dark{}).

  &:focus-within {
    // rgba($primary-color, …) was invalid (var-hex dropped → no focus ring). color-mix.
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    box-shadow: 0 0 0 2px color-mix(in srgb, var(--app-primary-color) 18%, transparent);
    border-color: color-mix(in srgb, var(--app-primary-color) 40%, transparent);
  }

  &--completing {
    border-left: 3px solid var(--p-green-500);
  }
}

// ── Header ────────────────────────────────────────────────────────────────────

.tqf__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  min-height: 28px;
}

.tqf__kind-chip {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  padding: 3px 8px 3px 6px;
  border: 1px solid $surface-300;
  border-radius: $radius-sm;
  background: $surface-50;
  color: $surface-600;
  font-size: $font-size-2xs;
  cursor: pointer;
  transition: all var(--app-transition-fast);
  flex-shrink: 0;

  // theme-reactive base tokens read correctly in navy dark. Dead override removed.

  &:hover:not(&--static) {
    border-color: $primary-color;
    color: $primary-color;
  }

  &--static {
    cursor: default;
    pointer-events: none;
  }
}

.tqf__kind-icon {
  font-size: $font-size-xs;
}

.tqf__kind-caret {
  font-size: $font-size-3xs; // snap from 9px
  opacity: 0.6;
}

.tqf__meta {
  flex: 1;
  display: flex;
  align-items: center;
  gap: $space-1;
  overflow: hidden;
}

.tqf__due {
  display: inline-flex;
  align-items: center;
  gap: 3px;
  font-size: $font-size-xs;
  color: $surface-500;
  white-space: nowrap;

  &--overdue {
    color: $color-danger;
    font-weight: $font-weight-medium;
  }
}

.tqf__due-icon {
  font-size: $font-size-2xs;
}

.tqf__responsible {
  font-size: $font-size-xs;
  color: $surface-400;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tqf__close {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  border: none;
  background: transparent;
  color: $surface-400;
  border-radius: $radius-sm;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: $font-size-2xs;
  transition: all var(--app-transition-fast);

  &:hover {
    color: $surface-700;
    // reactive raised step (light grey / navy #172847) — visible on both card bgs.
    // Was dead `:global(.app-dark) &` override; base surface-100 = card bg (no hover in dark).
    background: $surface-200;
  }
}

// ── Title ─────────────────────────────────────────────────────────────────────

.tqf__title-wrap {
  display: flex;
  flex-direction: column;
  gap: 2px;
}

.tqf__title-input {
  width: 100%;
  font-size: $font-size-sm;
}

.tqf__task-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  // theme-reactive (dark = #C6D0E2 strong text). Dead override to surface-100 removed
  // (surface-100 = card bg in dark = invisible title).
  color: $surface-800;
  margin: 0;
  line-height: 1.4;
}

.tqf__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
}

// ── Required due-date field (require-due-date context) ──────────────────────────

.tqf__due-field {
  display: flex;
  flex-direction: column;
  gap: 3px;
}

.tqf__due-field-label {
  font-size: $font-size-2xs;
  font-weight: $font-weight-medium;
  // theme-reactive: dark = soft navy label; light = mid-grey.
  color: $surface-600;
}

.tqf__due-field-input {
  width: 100%;

  :deep(.p-inputtext) {
    width: 100%;
    font-size: $font-size-sm;
  }
}

// ── Result (complete mode) ────────────────────────────────────────────────────

.tqf__result-input {
  width: 100%;
  font-size: $font-size-sm;
  resize: none;
}

// ── Footer ────────────────────────────────────────────────────────────────────

.tqf__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
  flex-wrap: wrap;
}

.tqf__footer-left {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: wrap;
  flex: 1;
  min-width: 0;
}

.tqf__footer-right {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-shrink: 0;
}

.tqf__done-check {
  flex-shrink: 0;
}

.tqf__kind-label {
  font-size: $font-size-xs;
  color: $primary-color;
  border: none;
  background: transparent;
  cursor: pointer;
  padding: 0;
  text-decoration: underline;
  text-underline-offset: 2px;

  &:hover {
    opacity: 0.8;
  }
}

.tqf__quick-dates {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex-wrap: wrap;
  position: relative;
}

.tqf__quick-btn {
  font-size: $font-size-2xs;
  padding: 2px 8px;
  border: 1px solid $surface-300;
  border-radius: $radius-sm;
  background: transparent;
  color: $surface-600;
  cursor: pointer;
  transition: all var(--app-transition-fast);
  white-space: nowrap;
  line-height: 1.4;

  // theme-reactive base tokens read correctly in navy dark. Dead override removed.

  &:hover {
    border-color: $primary-color;
    color: $primary-color;
    // rgba($primary-color, …) was invalid (var-hex dropped → transparent). color-mix.
    background: color-mix(in srgb, var(--app-primary-color) 6%, transparent);
  }

  &--active {
    border-color: $primary-color;
    // rgba($primary-color, …) was invalid (var-hex dropped → transparent). color-mix.
    background: color-mix(in srgb, var(--app-primary-color) 10%, transparent);
    color: $primary-color;
    font-weight: $font-weight-medium;
  }

  &--calendar {
    padding: 2px 6px;
  }
}

// Hide the DatePicker input visually but keep it in DOM for popup
.tqf__hidden-picker {
  position: absolute;
  width: 0;
  height: 0;
  overflow: hidden;
  opacity: 0;
  pointer-events: none;
}

.tqf__delete-btn {
  width: 28px;
  height: 28px;
  border: none;
  background: transparent;
  color: $surface-400;
  border-radius: $radius-sm;
  display: flex;
  align-items: center;
  justify-content: center;
  cursor: pointer;
  font-size: $font-size-xs; // snap from 13px
  transition: all var(--app-transition-fast);

  &:hover {
    color: $color-danger;
    background: $color-danger-bg;
  }
}

.tqf__submit-btn {
  flex-shrink: 0;
}
</style>
