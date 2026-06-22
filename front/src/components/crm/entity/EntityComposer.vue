<template>
  <div class="entity-composer">
    <!-- Mode column: Заметка / Задача -->
    <div class="entity-composer__mode-col">
      <button
        type="button"
        class="entity-composer__mode-btn"
        :class="{ 'entity-composer__mode-btn--active': activeTab === 'note' }"
        @click="activeTab = 'note'"
      >
        <i class="pi pi-comment" />
        {{ t('crm.entity.composer.note') }}
      </button>
      <button
        type="button"
        class="entity-composer__mode-btn"
        :class="{ 'entity-composer__mode-btn--active': activeTab === 'task' }"
        @click="activeTab = 'task'"
      >
        <i class="pi pi-check-square" />
        {{ t('crm.entity.composer.task') }}
      </button>
    </div>

    <!-- Content column -->
    <div class="entity-composer__content-col">
      <!-- Note mode -->
      <div v-if="activeTab === 'note'" class="entity-composer__frame">
        <div class="entity-composer__textarea-wrap">
          <Textarea
            ref="noteTextareaRef"
            v-model="noteForm.body"
            :placeholder="t('sales.deal.composer.notePlaceholder')"
            :rows="2"
            auto-resize
            fluid
            class="entity-composer__textarea"
          />
          <small v-if="errors.body" class="entity-composer__error">{{ errors.body }}</small>
        </div>
        <Button
          :label="t('crm.entity.composer.add')"
          size="small"
          :loading="saving"
          class="entity-composer__add-btn"
          @click="submitNote"
        />
      </div>

      <!-- Task mode -->
      <div v-else class="entity-composer__frame entity-composer__frame--task">
        <!-- Top row: date + responsible + type -->
        <div class="entity-composer__task-controls">
          <DatePicker
            v-model="taskForm.dueAt"
            show-time
            show-icon
            :placeholder="t('common.date')"
            class="entity-composer__task-date"
            append-to="body"
          />
          <Select
            v-model="taskForm.responsibleId"
            :options="usersList"
            option-label="name"
            option-value="id"
            :placeholder="t('activity.fields.responsible')"
            show-clear
            fluid
            append-to="body"
            class="entity-composer__task-responsible"
          />
          <Select
            v-model="taskForm.subtype"
            :options="taskSubtypeOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('sales.deal.composer.taskSubtype')"
            fluid
            append-to="body"
            class="entity-composer__task-type"
          />
        </div>
        <!-- Bottom row: colon separator + textarea + add button, spec §5 -->
        <div class="entity-composer__task-body">
          <span class="entity-composer__colon">:</span>
          <div class="entity-composer__textarea-wrap">
            <!-- spec §5: task body = textarea (multi-line, wraps), NOT InputText -->
            <Textarea
              v-model="taskForm.title"
              :placeholder="t('sales.deal.composer.titlePlaceholder')"
              :rows="2"
              auto-resize
              fluid
              class="entity-composer__textarea entity-composer__task-title"
            />
            <small v-if="errors.title" class="entity-composer__error">{{ errors.title }}</small>
          </div>
          <Button
            :label="t('crm.entity.composer.add')"
            size="small"
            :loading="saving"
            class="entity-composer__add-btn"
            @click="submitTask"
          />
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, nextTick } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import type { ActivityDto, ActivityKind, ActivityPriority, CreateActivityPayload } from '@/entities/activity'

type TaskSubtype = 'task' | 'call' | 'meeting' | 'follow_up'
type ComposerTab = 'note' | 'task'

const props = defineProps<{
  /** 'company' | 'contact' — the entity context */
  entityType: 'company' | 'contact'
  entityId: number
  usersList?: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{
  created: [activity: ActivityDto]
}>()

const { t } = useI18n()
const toast = useToast()
const mutation = useMutation<ActivityDto>()
const saving = computed(() => mutation.isPending.value)

const activeTab = ref<ComposerTab>('note')
const noteTextareaRef = ref<{ $el?: HTMLElement } | null>(null)
const errors = ref<{ title?: string; body?: string }>({})

const taskSubtypeOptions = computed(() => [
  { value: 'task' as TaskSubtype, label: t('sales.deal.composer.subtypes.task') },
  { value: 'call' as TaskSubtype, label: t('sales.deal.composer.subtypes.call') },
  { value: 'meeting' as TaskSubtype, label: t('sales.deal.composer.subtypes.meeting') },
  { value: 'follow_up' as TaskSubtype, label: t('sales.deal.composer.subtypes.follow_up') },
])

const noteForm = ref({ body: '' })

const taskForm = ref({
  title: '',
  body: '',
  dueAt: null as Date | null,
  responsibleId: null as number | null,
  priority: 'normal' as ActivityPriority,
  subtype: 'task' as TaskSubtype,
})

// Map entityType to activity target_type
function targetType(): 'company' | 'contact' | 'deal' {
  return props.entityType === 'company' ? 'company' : 'contact'
}

async function doCreate(payload: CreateActivityPayload) {
  errors.value = {}
  const activity = await mutation.run(() => activityApi.createActivity(payload))
  emit('created', activity)
  toast.add({ severity: 'success', summary: t('sales.deal.composer.successCreate'), life: 3000 })
  return activity
}

async function submitNote() {
  errors.value = {}
  if (!noteForm.value.body.trim()) {
    errors.value.body = t('common.required')
    return
  }
  try {
    await doCreate({
      kind: 'note',
      title: noteForm.value.body.slice(0, 80),
      body: noteForm.value.body,
      target_type: targetType(),
      target_id: props.entityId,
    })
    noteForm.value = { body: '' }
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

async function submitTask() {
  errors.value = {}
  if (!taskForm.value.title.trim()) {
    errors.value.title = t('common.required')
    return
  }
  try {
    await doCreate({
      kind: taskForm.value.subtype as ActivityKind,
      title: taskForm.value.title.trim(),
      body: taskForm.value.body || null,
      due_at: taskForm.value.dueAt ? taskForm.value.dueAt.toISOString() : null,
      responsible_id: taskForm.value.responsibleId,
      priority: taskForm.value.priority,
      target_type: targetType(),
      target_id: props.entityId,
    })
    taskForm.value = {
      title: '',
      body: '',
      dueAt: null,
      responsibleId: null,
      priority: 'normal',
      subtype: taskForm.value.subtype,
    }
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

// ── Exposed API (for parent components) ──────────────────────────────────────

/** Switch to note mode and focus the textarea */
function focusNote() {
  activeTab.value = 'note'
  void nextTick(() => {
    const el = noteTextareaRef.value?.$el
    const textarea = el?.querySelector?.('textarea') ?? (el instanceof HTMLTextAreaElement ? el : null)
    textarea?.focus()
  })
}

/** Switch to task mode (focuses naturally via v-if render) */
function focusTask() {
  activeTab.value = 'task'
}

defineExpose({ focusNote, focusTask })

const usersList = computed(() => props.usersList ?? [])
</script>

<style lang="scss" scoped>
.entity-composer {
  display: flex;
  gap: $space-3;
  // spec §5: composer bg = --c-hover (#F9FAFB light / #3a3b3d dark = surface-200)
  background: var(--p-surface-50);
  padding: $space-3 $space-4;
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    // --c-hover in dark = #3a3b3d = var(--p-surface-200) in our dark scale
    background: var(--p-surface-200);
    border-top-color: var(--p-surface-600);
  }
}

// ── Mode column ────────────────────────────────────────────────────────────────

.entity-composer__mode-col {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 110px; // spec: 110px fixed — layout invariant
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.entity-composer__mode-btn {
  width: 100%;
  padding: $space-2 $space-3;
  border-radius: $radius-md;
  border: 1px solid var(--p-surface-300);
  background: transparent;
  cursor: pointer;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-600;
  display: flex;
  align-items: center;
  gap: $space-2;
  transition: background var(--app-transition-fast), color var(--app-transition-fast), border-color var(--app-transition-fast);

  i {
    font-size: $font-size-sm;
    flex-shrink: 0;
  }

  .app-dark & {
    color: var(--p-surface-300);
    border-color: var(--p-surface-600);
  }
}

.entity-composer__mode-btn--active {
  background: $brand-header-bg;
  color: $sidebar-text-active;
  border-color: $brand-header-bg;

  .app-dark & {
    background: $brand-header-bg;
    color: $sidebar-text-active;
    border-color: $brand-header-bg;
  }
}

// ── Content column ────────────────────────────────────────────────────────────

.entity-composer__content-col {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-width: 0;
}

// ── Frame (wraps textarea + button) ───────────────────────────────────────────

.entity-composer__frame {
  flex: 1;
  border: 1px solid var(--p-surface-300);
  border-radius: $radius-md;
  padding: $space-2;
  min-height: 78px; // spec §5: min-height = 78px (height of two mode buttons)
  display: flex;
  // spec §5: button «Добавить» vertically centered — note mode uses align-items:center
  align-items: center;
  gap: $space-2;
  background: $surface-card;

  .app-dark & {
    border-color: var(--p-surface-600);
  }

  &--task {
    flex-direction: column;
    align-items: stretch;
    min-height: 78px;
    gap: $space-2;
  }
}

.entity-composer__textarea-wrap {
  flex: 1;
  min-width: 0;
}

.entity-composer__textarea {
  resize: none;

  :deep(textarea) {
    border: none;
    background: transparent;
    box-shadow: none;
    padding: 0;
    outline: none;
  }
}

.entity-composer__add-btn {
  flex-shrink: 0;
  // note mode: frame is align-items:center → button is centered by flex container
  // task mode: __task-body is flex + align-items:flex-end → add-btn aligns to bottom
}

// spec §5: colon separator before task textarea
.entity-composer__colon {
  color: $surface-400;
  font-weight: $font-weight-bold;
  flex-shrink: 0;
  line-height: 1;
  align-self: flex-start;
  padding-top: 2px;

  .app-dark & {
    color: var(--p-surface-500);
  }
}

.entity-composer__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  display: block;
  margin-top: $space-1;
}

// ── Task mode controls ────────────────────────────────────────────────────────

.entity-composer__task-controls {
  display: flex;
  gap: $space-2;
  flex-wrap: wrap;
}

.entity-composer__task-date {
  flex: 1;
  min-width: 140px;
}

.entity-composer__task-responsible {
  flex: 1;
  min-width: 130px;
}

.entity-composer__task-type {
  flex: 1;
  min-width: 120px;
}

.entity-composer__task-body {
  display: flex;
  align-items: flex-end;
  gap: $space-2;
  flex: 1;
}

.entity-composer__task-title {
  flex: 1;
}
</style>
