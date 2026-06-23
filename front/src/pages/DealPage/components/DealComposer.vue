<template>
  <!--
    DealComposer — spec §7.4.
    Layout: flex row — LEFT: two stacked mode btns (108px each: Заметка / Задача, active=navy).
    RIGHT: bordered content box (min-height:74), «Добавить» vertically centered.
    Task mode: top row 3 fields (дата+время · ответственный · тип) + colon + textarea below.
  -->
  <div class="deal-composer">
    <!-- LEFT: stacked mode buttons -->
    <div class="deal-composer__modes">
      <button
        type="button"
        class="deal-composer__mode-btn"
        :class="{ 'deal-composer__mode-btn--active': activeTab === 'note' }"
        @click="activeTab = 'note'"
      >
        <i class="pi pi-file" />
        {{ t('sales.deal.composer.note') }}
      </button>
      <button
        type="button"
        class="deal-composer__mode-btn"
        :class="{ 'deal-composer__mode-btn--active': activeTab === 'task' }"
        @click="activeTab = 'task'"
      >
        <i class="pi pi-check-square" />
        {{ t('sales.deal.composer.task') }}
      </button>
    </div>

    <!-- RIGHT: content box with border -->
    <div class="deal-composer__box">
      <!-- ─── Note mode ───────────────────────────────────────────── -->
      <template v-if="activeTab === 'note'">
        <Textarea
          v-model="noteForm.body"
          :placeholder="t('sales.deal.composer.notePlaceholder')"
          auto-resize
          fluid
          class="deal-composer__textarea"
          @keydown.ctrl.enter="submitNote"
        />
      </template>

      <!-- ─── Task mode ───────────────────────────────────────────── -->
      <template v-else>
        <!-- Top row: дата+время · ответственный · тип задачи -->
        <div class="deal-composer__task-row">
          <DatePicker
            v-model="taskForm.dueAt"
            show-time
            show-icon
            :placeholder="t('common.date')"
            class="deal-composer__task-field"
            append-to="body"
          />
          <Select
            v-model="taskForm.responsibleId"
            :options="usersList"
            option-label="name"
            option-value="id"
            :placeholder="t('activity.fields.responsible')"
            show-clear
            class="deal-composer__task-field"
            append-to="body"
          />
          <Select
            v-model="taskForm.subtype"
            :options="taskSubtypeOptions"
            option-label="label"
            option-value="value"
            :placeholder="t('sales.deal.composer.taskSubtype')"
            class="deal-composer__task-field"
            append-to="body"
          />
        </div>
        <!-- Colon + textarea -->
        <div class="deal-composer__task-body">
          <span class="deal-composer__colon">:</span>
          <Textarea
            v-model="taskForm.title"
            :placeholder="t('sales.deal.composer.titlePlaceholder')"
            :invalid="!!errors.title"
            auto-resize
            fluid
            class="deal-composer__textarea"
            @keydown.ctrl.enter="submitTask"
          />
        </div>
        <small v-if="errors.title" class="deal-composer__error">{{ errors.title }}</small>
      </template>

      <!-- «Добавить» — vertically centered (Y midpoint between two mode buttons) -->
      <div class="deal-composer__add-wrap">
        <Button
          :label="t('sales.deal.composer.save')"
          :loading="saving"
          size="small"
          @click="activeTab === 'note' ? submitNote() : submitTask()"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import type { ActivityDto, ActivityKind, ActivityPriority, CreateActivityPayload } from '@/entities/activity'

// ─── Task subtype type ────────────────────────────────────────────────────────

type TaskSubtype = 'task' | 'call' | 'meeting' | 'follow_up'
type ComposerTab = 'note' | 'task'

// ─── Props / emits ────────────────────────────────────────────────────────────

const props = defineProps<{
  dealId: number
  usersList: Array<{ id: number; name: string }>
  initialTab?: ActivityKind
}>()

const emit = defineEmits<{
  created: [activity: ActivityDto]
}>()

// ─── Setup ────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()
const mutation = useMutation<ActivityDto>()
const saving = computed(() => mutation.isPending.value)

function kindToTab(kind: ActivityKind | undefined): ComposerTab {
  if (!kind || kind === 'note') return 'note'
  return 'task'
}

const activeTab = ref<ComposerTab>(kindToTab(props.initialTab))

watch(
  () => props.initialTab,
  (kind) => {
    activeTab.value = kindToTab(kind)
    if (kind && kind !== 'note') {
      const subtypes: TaskSubtype[] = ['task', 'call', 'meeting', 'follow_up']
      if (subtypes.includes(kind as TaskSubtype)) {
        taskForm.value.subtype = kind as TaskSubtype
      }
    }
  },
)

const errors = ref<{ title?: string }>({})

// ─── Task subtype options ─────────────────────────────────────────────────────

const taskSubtypeOptions = computed(() => [
  { value: 'task' as TaskSubtype, label: t('sales.deal.composer.subtypes.task') },
  { value: 'call' as TaskSubtype, label: t('sales.deal.composer.subtypes.call') },
  { value: 'meeting' as TaskSubtype, label: t('sales.deal.composer.subtypes.meeting') },
  { value: 'follow_up' as TaskSubtype, label: t('sales.deal.composer.subtypes.follow_up') },
])

// ─── Form state ───────────────────────────────────────────────────────────────

const noteForm = ref({ body: '' })

const taskForm = ref({
  title: '',
  dueAt: null as Date | null,
  responsibleId: null as number | null,
  subtype: 'task' as TaskSubtype,
})

function resetNote() {
  noteForm.value = { body: '' }
}

function resetTask() {
  taskForm.value = {
    title: '',
    dueAt: null,
    responsibleId: null,
    subtype: taskForm.value.subtype,
  }
}

// ─── Submit helpers ───────────────────────────────────────────────────────────

async function doCreate(payload: CreateActivityPayload) {
  errors.value = {}
  const activity = await mutation.run(() => activityApi.createActivity(payload))
  emit('created', activity)
  toast.add({
    severity: 'success',
    summary: t('sales.deal.composer.successCreate'),
    life: 3000,
  })
  return activity
}

async function submitNote() {
  errors.value = {}
  if (!noteForm.value.body.trim()) {
    errors.value.title = t('common.required')
    return
  }
  try {
    await doCreate({
      kind: 'note',
      title: noteForm.value.body.slice(0, 80),
      body: noteForm.value.body,
      target_type: 'deal',
      target_id: props.dealId,
    })
    resetNote()
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
    const payload: CreateActivityPayload = {
      kind: taskForm.value.subtype,
      title: taskForm.value.title.trim(),
      body: null,
      due_at: taskForm.value.dueAt ? taskForm.value.dueAt.toISOString() : null,
      responsible_id: taskForm.value.responsibleId,
      priority: 'normal' as ActivityPriority,
      target_type: 'deal',
      target_id: props.dealId,
    }
    await doCreate(payload)
    resetTask()
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

// ─── Expose tab setter (for parent to switch tab) ─────────────────────────────

function setTab(tab: ActivityKind) {
  activeTab.value = kindToTab(tab)
  if (tab !== 'note') {
    const subtypes: TaskSubtype[] = ['task', 'call', 'meeting', 'follow_up']
    if (subtypes.includes(tab as TaskSubtype)) {
      taskForm.value.subtype = tab as TaskSubtype
    }
  }
}

defineExpose({ setTab })
</script>

<style lang="scss" scoped>
// spec §7.4: border-top; padding:12px 16px; background:--c-card; display:flex; gap:10; align-items:center
.deal-composer {
  display: flex;
  align-items: stretch;
  gap: 10px;
  padding: $space-3 $space-4;
  background: var(--p-card-background);
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

// ─── LEFT: two stacked mode buttons ──────────────────────────────────────────
// spec §7.4: «Заметка / Задача, active — заливка --mg-primary-900, белая»

.deal-composer__modes {
  display: flex;
  flex-direction: column;
  gap: 4px;
  flex-shrink: 0;
}

.deal-composer__mode-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: $space-1;
  width: 108px;
  padding: $space-2 $space-2;
  border-radius: $radius-md;
  border: 1px solid var(--p-surface-200);
  background: var(--p-surface-50);
  color: $surface-600;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  cursor: pointer;
  transition: background var(--app-transition-fast), color var(--app-transition-fast), border-color var(--app-transition-fast);
  white-space: nowrap;

  .app-dark & {
    background: var(--p-surface-100);
    border-color: var(--p-surface-700);
    color: var(--p-surface-300);
  }

  &:hover:not(.deal-composer__mode-btn--active) {
    background: var(--p-surface-100);
    border-color: var(--p-surface-300);

    .app-dark & {
      background: var(--p-surface-200);
      border-color: var(--p-surface-600);
    }
  }

  // Active state: navy fill, white text — dedicated BEM modifier (no compound &.active)
  &--active {
    background: $primary-900;
    border-color: $primary-900;
    color: $sidebar-text-active;

    .app-dark & {
      background: $primary-900;
      border-color: $primary-900;
      color: $sidebar-text-active;
    }
  }

  i {
    font-size: $font-size-xs;
  }
}

// ─── RIGHT: bordered content box ──────────────────────────────────────────────
// spec §7.4: «поле в рамке (min-height 74)»

.deal-composer__box {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-2 $space-3;
  min-height: 74px;
  position: relative;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

// Task top row: three fields in one flex row
.deal-composer__task-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: nowrap;
  overflow: hidden;
}

.deal-composer__task-field {
  flex: 1;
  min-width: 0;

  // Make PrimeVue DatePicker/Select compact inside this row
  :deep(.p-datepicker) {
    width: 100%;
  }

  :deep(.p-select) {
    width: 100%;
  }
}

// «:» + textarea row (task body)
.deal-composer__task-body {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
}

.deal-composer__colon {
  font-size: $font-size-sm;
  color: $surface-500;
  padding-top: 2px;
  flex-shrink: 0;
}

.deal-composer__textarea {
  flex: 1;
  resize: none;
  border: none;
  outline: none;
  background: transparent;
  padding: 0;
  font-size: $font-size-sm;
  color: $surface-800;
  font-family: inherit;
  line-height: $line-height-normal;

  // Override PrimeVue Textarea styling
  :deep(.p-textarea) {
    border: none;
    background: transparent;
    padding: 0;
    box-shadow: none;

    &:focus {
      box-shadow: none;
    }
  }

  .app-dark & {
    color: var(--p-text-color);
  }
}

// «Добавить» — vertically centered (aligned to Y midpoint between the two mode buttons)
// spec §7.4: «кнопка «Добавить» по центру по вертикали»
.deal-composer__add-wrap {
  display: flex;
  justify-content: flex-end;
  align-items: flex-end;
  flex: 1;
  margin-top: auto;
}

.deal-composer__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  display: block;
}
</style>
