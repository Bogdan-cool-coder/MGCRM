<template>
  <!--
    EntityComposer — spec §12 (parity with DealComposer §7.4).
    Layout: flex row — LEFT: two stacked mode btns (Примечание / Задача, active=navy).
    RIGHT: bordered content box, «Добавить» absolutely right-center.
    Task mode field order (spec §11): Тип задачи → Дата → Ответственный.
    SearchPicker for type/responsible; DateField for date; colored task-type icons.
  -->
  <div class="entity-composer">
    <!-- LEFT: stacked mode buttons -->
    <div class="entity-composer__modes">
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

    <!-- RIGHT: content box with border + «Добавить» absolutely right-center -->
    <div class="entity-composer__box">
      <!-- ─── Note mode ─────────────────────────────────────────────── -->
      <template v-if="activeTab === 'note'">
        <Textarea
          ref="noteTextareaRef"
          v-model="noteForm.body"
          :placeholder="t('sales.deal.composer.notePlaceholder')"
          :rows="2"
          auto-resize
          fluid
          class="entity-composer__textarea"
          @keydown.ctrl.enter="submitNote"
        />
        <small v-if="errors.body" class="entity-composer__error">{{ errors.body }}</small>
      </template>

      <!-- ─── Task mode ─────────────────────────────────────────────── -->
      <template v-else>
        <!-- Top row: Тип задачи · Дата · Ответственный (spec §11) -->
        <div class="entity-composer__task-row">
          <!-- Тип задачи — SearchPicker with colored icons -->
          <div class="entity-composer__task-field">
            <SearchPicker
              v-model="taskForm.subtype"
              :options="taskSubtypeOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('sales.deal.composer.taskSubtype')"
              :display-label="currentSubtypeLabel"
              class="entity-composer__picker"
            >
              <template #trigger-content>
                <span class="entity-composer__picker-trigger">
                  <i
                    v-if="currentSubtypeIcon"
                    :class="['pi', currentSubtypeIcon, 'entity-composer__type-icon']"
                    :style="{ color: currentSubtypeColor }"
                  />
                  <span class="entity-composer__picker-label">{{ currentSubtypeLabel || t('sales.deal.composer.taskSubtype') }}</span>
                </span>
              </template>
              <template #option="{ option }">
                <span class="entity-composer__type-option">
                  <i
                    :class="['pi', String(option.icon ?? ''), 'entity-composer__type-option-icon']"
                    :style="{ color: String(option.color ?? '') }"
                  />
                  <span>{{ String(option.label ?? '') }}</span>
                </span>
              </template>
            </SearchPicker>
          </div>

          <!-- Дата — DateField with auto-format ДД.ММ.ГГГГ -->
          <div class="entity-composer__task-field">
            <DateField
              v-model="taskFormDate"
              :placeholder="t('common.date')"
            />
          </div>

          <!-- Ответственный — SearchPicker -->
          <div class="entity-composer__task-field">
            <SearchPicker
              v-model="taskForm.responsibleId"
              :options="resolvedUsersList"
              option-label="name"
              option-value="id"
              :placeholder="t('activity.fields.responsible')"
              :display-label="currentResponsibleName"
              class="entity-composer__picker"
            />
          </div>
        </div>
        <!-- Colon + textarea -->
        <div class="entity-composer__task-body">
          <span class="entity-composer__colon">:</span>
          <Textarea
            v-model="taskForm.title"
            :placeholder="t('sales.deal.composer.titlePlaceholder')"
            :invalid="!!errors.title"
            :rows="2"
            auto-resize
            fluid
            class="entity-composer__textarea entity-composer__task-title"
            @keydown.ctrl.enter="submitTask"
          />
        </div>
        <small v-if="errors.title" class="entity-composer__error">{{ errors.title }}</small>
      </template>

      <!-- «Добавить» — absolutely right-center in box -->
      <div class="entity-composer__add-wrap">
        <Button
          :label="t('crm.entity.composer.add')"
          size="small"
          :loading="saving"
          @click="activeTab === 'note' ? submitNote() : submitTask()"
        />
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
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import SearchPicker from '@/components/crm/SearchPicker.vue'
import DateField from '@/components/crm/DateField.vue'
import type { ActivityDto, ActivityKind, ActivityPriority, CreateActivityPayload } from '@/entities/activity'

// ── Types ─────────────────────────────────────────────────────────────────────

type TaskSubtype = 'task' | 'call' | 'meeting' | 'follow_up'
type ComposerTab = 'note' | 'task'

interface SubtypeOption {
  value: TaskSubtype
  label: string
  icon: string
  color: string
  [key: string]: unknown
}

// ── Props / emits ─────────────────────────────────────────────────────────────

const props = defineProps<{
  entityType: 'company' | 'contact'
  entityId: number
  usersList?: Array<{ id: number; name: string }>
}>()

const emit = defineEmits<{
  created: [activity: ActivityDto]
}>()

// ── Setup ─────────────────────────────────────────────────────────────────────

const { t } = useI18n()
const toast = useToast()
const mutation = useMutation<ActivityDto>()
const saving = computed(() => mutation.isPending.value)

const activeTab = ref<ComposerTab>('note')
const noteTextareaRef = ref<{ $el?: HTMLElement } | null>(null)
const errors = ref<{ title?: string; body?: string }>({})

// ── Subtype options with colored icons ────────────────────────────────────────

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

const resolvedUsersList = computed(() => props.usersList ?? [])

const currentResponsibleName = computed(() => {
  if (taskForm.value.responsibleId == null) return ''
  const found = resolvedUsersList.value.find((u) => u.id === taskForm.value.responsibleId)
  return found?.name ?? ''
})

// ── Form state ────────────────────────────────────────────────────────────────

const noteForm = ref({ body: '' })

const taskForm = ref({
  title: '',
  dueAt: null as Date | null,
  responsibleId: null as number | null,
  subtype: 'task' as TaskSubtype,
})

// Bridge DateField ISO string ↔ taskForm.dueAt Date
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

// ── Helpers ───────────────────────────────────────────────────────────────────

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
      body: null,
      due_at: taskForm.value.dueAt ? taskForm.value.dueAt.toISOString() : null,
      responsible_id: taskForm.value.responsibleId,
      priority: 'normal' as ActivityPriority,
      target_type: targetType(),
      target_id: props.entityId,
    })
    taskForm.value = {
      title: '',
      dueAt: null,
      responsibleId: null,
      subtype: taskForm.value.subtype,
    }
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

// ── Exposed API ───────────────────────────────────────────────────────────────

function focusNote() {
  activeTab.value = 'note'
  void nextTick(() => {
    const el = noteTextareaRef.value?.$el
    const textarea = el?.querySelector?.('textarea') ?? (el instanceof HTMLTextAreaElement ? el : null)
    textarea?.focus()
  })
}

function focusTask() {
  activeTab.value = 'task'
}

defineExpose({ focusNote, focusTask })
</script>

<style lang="scss" scoped>
.entity-composer {
  display: flex;
  align-items: stretch;
  gap: $space-2;
  padding: $space-3 $space-4;
  background: var(--p-surface-50);
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-surface-200);
    border-top-color: var(--p-surface-600);
  }
}

// ── LEFT: stacked mode buttons ────────────────────────────────────────────────
.entity-composer__modes {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  flex-shrink: 0;
}

.entity-composer__mode-btn {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: $space-1;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  width: 108px; // spec §12 fixed — layout invariant
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

  &:hover:not(.entity-composer__mode-btn--active) {
    background: var(--p-surface-100);
    border-color: var(--p-surface-300);

    .app-dark & {
      background: var(--p-surface-200);
      border-color: var(--p-surface-600);
    }
  }

  i {
    font-size: $font-size-xs;
  }
}

.entity-composer__mode-btn--active {
  background: $primary-900;
  border-color: $primary-900;
  color: $sidebar-text-active;

  .app-dark & {
    background: $primary-900;
    border-color: $primary-900;
    color: $sidebar-text-active;
  }
}

// ── RIGHT: bordered content box ───────────────────────────────────────────────
.entity-composer__box {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  gap: $space-2;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  padding: $space-2 $space-3;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  min-height: 74px;
  position: relative;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding-right: 100px;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

// Task top row — order: Тип · Дата · Ответственный
.entity-composer__task-row {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-wrap: nowrap;
}

.entity-composer__task-field {
  flex: 1;
  min-width: 0;
}

// SearchPicker sizing
.entity-composer__picker {
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

.entity-composer__picker-trigger {
  display: flex;
  align-items: center;
  gap: $space-1;
  flex: 1;
  min-width: 0;
  overflow: hidden;
}

.entity-composer__picker-label {
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  text-align: left;
}

.entity-composer__type-icon {
  font-size: $font-size-xs;
  flex-shrink: 0;
}

.entity-composer__type-option {
  display: flex;
  align-items: center;
  gap: $space-2;
}

.entity-composer__type-option-icon {
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
.entity-composer__task-body {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
}

.entity-composer__colon {
  font-size: $font-size-sm;
  color: $surface-500;
  padding-top: 2px;
  flex-shrink: 0;
}

.entity-composer__textarea {
  flex: 1;
  resize: none;

  :deep(textarea) {
    border: none;
    background: transparent;
    box-shadow: none;
    padding: 0;
    outline: none;
  }
}

.entity-composer__task-title {
  flex: 1;
}

// «Добавить» — absolute right-center
.entity-composer__add-wrap {
  position: absolute;
  right: $space-3;
  top: 50%;
  transform: translateY(-50%);
  display: flex;
  align-items: center;
}

.entity-composer__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  display: block;
}
</style>
