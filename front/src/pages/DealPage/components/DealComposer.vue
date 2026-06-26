<template>
  <!--
    DealComposer — spec §7.4.
    Layout: flex row — LEFT: two stacked mode btns (108px each: Примечание / Задача, active=navy).
    RIGHT: bordered content box (min-height:74), «Добавить» absolutely right-center (B1).
    Task mode field order (spec §11): Тип задачи → Дата → Ответственный (B2).
    Ответственный and Тип задачи: SearchPicker with colored task-type icons (B4).
    Date: DateField with auto-format ДД.ММ.ГГГГ + calendar (B3).
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
        <i class="pi pi-comment" />
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

    <!-- RIGHT: content box with border + «Добавить» absolutely right-center (B1) -->
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
        <!-- Top row: spec §11 order: Тип задачи · Дата · Ответственный (B2) -->
        <div class="deal-composer__task-row">
          <!-- Тип задачи — SearchPicker with colored icons (B4) -->
          <div class="deal-composer__task-field">
            <SearchPicker
              v-model="taskForm.subtype"
              :options="taskSubtypeOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('sales.deal.composer.taskSubtype')"
              :display-label="currentSubtypeLabel"
              class="deal-composer__picker"
            >
              <template #trigger-content>
                <span class="deal-composer__picker-trigger">
                  <i
                    v-if="currentSubtypeIcon"
                    :class="['pi', currentSubtypeIcon, 'deal-composer__type-icon']"
                    :style="{ color: currentSubtypeColor }"
                  />
                  <span class="deal-composer__picker-label">{{ currentSubtypeLabel || t('sales.deal.composer.taskSubtype') }}</span>
                </span>
              </template>
              <template #option="{ option }">
                <span class="deal-composer__type-option">
                  <i
                    :class="['pi', String(option.icon ?? ''), 'deal-composer__type-option-icon']"
                    :style="{ color: String(option.color ?? '') }"
                  />
                  <span>{{ String(option.label ?? '') }}</span>
                </span>
              </template>
            </SearchPicker>
          </div>

          <!-- Дата — DateField with auto-format ДД.ММ.ГГГГ (B3) -->
          <div class="deal-composer__task-field">
            <DateField
              v-model="taskFormDate"
              :placeholder="t('common.date')"
            />
          </div>

          <!-- Ответственный — SearchPicker (B4) -->
          <div class="deal-composer__task-field">
            <SearchPicker
              v-model="taskForm.responsibleId"
              :options="usersList"
              option-label="name"
              option-value="id"
              :placeholder="t('activity.fields.responsible')"
              :display-label="currentResponsibleName"
              class="deal-composer__picker"
            />
          </div>
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

      <!-- «Добавить» — absolutely right-center in the box (B1) -->
      <!-- spec §7.4: button between the two mode buttons, field text wraps and never runs under -->
      <div class="deal-composer__add-wrap">
        <Button
          :label="t('sales.deal.composer.add')"
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
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import SearchPicker from '@/components/crm/SearchPicker.vue'
import DateField from '@/components/crm/DateField.vue'
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

// ─── Task subtype options with colored icons (B4) ────────────────────────────
// spec §9 + §11: call=#2A6FDB pi-phone, meeting=#1F8A5B pi-calendar,
//               follow_up=#E8A317 pi-replay, task=navy pi-check-square

interface SubtypeOption {
  value: TaskSubtype
  label: string
  icon: string
  color: string
  [key: string]: unknown
}

const taskSubtypeOptions = computed((): SubtypeOption[] => [
  { value: 'task', label: t('sales.deal.composer.subtypes.task'), icon: 'pi-check-square', color: '#172747' },
  { value: 'call', label: t('sales.deal.composer.subtypes.call'), icon: 'pi-phone', color: '#2A6FDB' },
  { value: 'meeting', label: t('sales.deal.composer.subtypes.meeting'), icon: 'pi-calendar', color: '#1F8A5B' },
  { value: 'follow_up', label: t('sales.deal.composer.subtypes.follow_up'), icon: 'pi-replay', color: '#E8A317' },
])

const currentSubtype = computed(() =>
  taskSubtypeOptions.value.find((o) => o.value === taskForm.value.subtype),
)
const currentSubtypeLabel = computed(() => currentSubtype.value?.label ?? '')
const currentSubtypeIcon = computed(() => currentSubtype.value?.icon ?? '')
const currentSubtypeColor = computed(() => currentSubtype.value?.color ?? '#172747')

const currentResponsibleName = computed(() => {
  if (taskForm.value.responsibleId == null) return ''
  const found = props.usersList.find((u) => u.id === taskForm.value.responsibleId)
  return found?.name ?? ''
})

// ─── Form state ───────────────────────────────────────────────────────────────

const noteForm = ref({ body: '' })

const taskForm = ref({
  title: '',
  dueAt: null as Date | null,
  responsibleId: null as number | null,
  subtype: 'task' as TaskSubtype,
})

// DateField uses ISO string; bridge via computed
const taskFormDate = computed({
  get(): string | null {
    if (!taskForm.value.dueAt) return null
    const d = taskForm.value.dueAt
    const yyyy = d.getFullYear()
    const mm = String(d.getMonth() + 1).padStart(2, '0')
    const dd = String(d.getDate()).padStart(2, '0')
    return `${yyyy}-${mm}-${dd}`
  },
  set(iso: string | null) {
    taskForm.value.dueAt = iso ? new Date(iso) : null
  },
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
  // In-flight guard: Ctrl+Enter can bypass the :loading button (F4)
  if (saving.value) return
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
  // In-flight guard: Ctrl+Enter can bypass the :loading button (F4)
  if (saving.value) return
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
  gap: $space-2;
  padding: $space-3 $space-4;
  background: var(--p-card-background);
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

// ─── LEFT: two stacked mode buttons ──────────────────────────────────────────
.deal-composer__modes {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  flex-shrink: 0;
}

.deal-composer__mode-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: $space-1;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 108px; // spec §7.4 fixed — layout invariant
  padding: $space-2;
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
// spec §7.4: min-height 74, button «Добавить» absolute right-center (B1)
// padding-right reserves space so text never runs under the button.
.deal-composer__box {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-2 $space-3;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  min-height: 74px; // spec §7.4 — layout invariant
  position: relative;
  // Right padding leaves room for «Добавить» button (~90px + gap)
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding-right: 100px;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

// Task top row — order B2: Тип · Дата · Ответственный
.deal-composer__task-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: nowrap;
}

.deal-composer__task-field {
  flex: 1;
  min-width: 0;

  .search-picker,
  .date-field {
    display: flex;
    width: 100%;
  }
}

// SearchPicker full-width in task row
.deal-composer__picker {
  display: flex !important;
  width: 100% !important;

  :deep(.search-picker__trigger) {
    width: 100%;
    min-width: 0;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    height: 34px;
    padding: 0 $space-2;
  }

  :deep(.search-picker__popover) {
    top: auto;
    bottom: calc(100% + $space-1);
    left: 0;
  }
}

.deal-composer__picker-trigger {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex: 1;
  min-width: 0;
  overflow: hidden;
}

.deal-composer__picker-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.deal-composer__type-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

.deal-composer__type-option {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.deal-composer__type-option-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

// DateField full-width
:deep(.date-field) {
  display: flex;
  flex: 1;
  width: 100%;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  height: 34px;
  box-sizing: border-box;
}

// «:» + textarea row
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

// «Добавить» — absolute right-center (B1)
// spec §7.4: vertically centered between mode buttons = 50% of box height
.deal-composer__add-wrap {
  position: absolute;
  right: $space-3;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
}

.deal-composer__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  display: block;
}
</style>
