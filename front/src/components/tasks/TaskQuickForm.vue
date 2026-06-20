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
import { kindIcon } from '@/utils/activity'
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

function buildDefaultForm(): QuickForm {
  return {
    kind: props.defaultKind ?? 'task',
    title: '',
    due_at: null,
    result_text: '',
  }
}

const form = ref<QuickForm>(buildDefaultForm())
const titleError = ref<string | null>(null)
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
  if (!form.value.title.trim()) {
    titleError.value = t('errors.validation')
    await nextTick()
    if (titleInputRef.value) {
      const el = (titleInputRef.value as unknown as { $el?: HTMLElement }).$el
      if (el instanceof HTMLElement) el.focus()
    }
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

  :global(.app-dark) & {
    background: var(--p-surface-900);
    border-color: var(--p-surface-700);
  }

  &:focus-within {
    box-shadow: 0 0 0 2px rgba($primary-color, 0.18);
    border-color: rgba($primary-color, 0.4);
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
  font-size: 11px;
  cursor: pointer;
  transition: all var(--app-transition-fast);
  flex-shrink: 0;

  :global(.app-dark) & {
    border-color: var(--p-surface-600);
    background: var(--p-surface-800);
    color: var(--p-surface-300);
  }

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
  font-size: 12px;
}

.tqf__kind-caret {
  font-size: 9px;
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
  font-size: 11px;
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
  font-size: 11px;
  transition: all var(--app-transition-fast);

  &:hover {
    color: $surface-700;
    background: $surface-100;

    :global(.app-dark) & {
      color: var(--p-surface-200);
      background: var(--p-surface-700);
    }
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
  color: $surface-800;
  margin: 0;
  line-height: 1.4;

  :global(.app-dark) & {
    color: var(--p-surface-100);
  }
}

.tqf__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
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
  font-size: 11px;
  padding: 2px 8px;
  border: 1px solid $surface-300;
  border-radius: $radius-sm;
  background: transparent;
  color: $surface-600;
  cursor: pointer;
  transition: all var(--app-transition-fast);
  white-space: nowrap;
  line-height: 1.4;

  :global(.app-dark) & {
    border-color: var(--p-surface-600);
    color: var(--p-surface-300);
  }

  &:hover {
    border-color: $primary-color;
    color: $primary-color;
    background: rgba($primary-color, 0.06);
  }

  &--active {
    border-color: $primary-color;
    background: rgba($primary-color, 0.1);
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
  font-size: 13px;
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
