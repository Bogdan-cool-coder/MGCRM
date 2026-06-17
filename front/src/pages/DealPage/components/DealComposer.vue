<template>
  <div class="deal-composer">
    <!-- Type selector dropdown -->
    <div class="deal-composer__header">
      <Select
        v-model="activeTab"
        :options="composerTypeOptions"
        option-label="label"
        option-value="value"
        class="deal-composer__type-select"
        size="small"
      />
    </div>

    <!-- Form body -->
    <div class="deal-composer__body">
      <!-- ─── Note ──────────────────────────────────────────────────────────── -->
      <template v-if="activeTab === 'note'">
        <Textarea
          v-model="noteForm.body"
          :placeholder="t('sales.deal.composer.notePlaceholder')"
          :rows="3"
          auto-resize
          fluid
          class="deal-composer__textarea"
        />
        <div class="deal-composer__footer">
          <Button
            icon="pi pi-send"
            :label="t('sales.deal.composer.save')"
            :loading="saving"
            @click="submitNote"
          />
        </div>
      </template>

      <!-- ─── Task (with subtype selector) ─────────────────────────────────── -->
      <template v-else>
        <div class="row g-2">
          <!-- Task subtype -->
          <div class="col-12">
            <Select
              v-model="taskForm.subtype"
              :options="taskSubtypeOptions"
              option-label="label"
              option-value="value"
              :placeholder="t('sales.deal.composer.taskSubtype')"
              fluid
              append-to="body"
            />
          </div>
          <div class="col-12">
            <InputText
              v-model="taskForm.title"
              :placeholder="t('sales.deal.composer.titlePlaceholder')"
              :invalid="!!errors.title"
              fluid
            />
            <small v-if="errors.title" class="deal-composer__error">{{ errors.title }}</small>
          </div>
          <div class="col-6">
            <DatePicker
              v-model="taskForm.dueAt"
              show-time
              show-icon
              fluid
              :placeholder="t('common.date')"
            />
          </div>
          <div class="col-6">
            <Select
              v-model="taskForm.responsibleId"
              :options="usersList"
              option-label="name"
              option-value="id"
              :placeholder="t('activity.fields.responsible')"
              show-clear
              fluid
              append-to="body"
            />
          </div>
          <div class="col-12">
            <Textarea
              v-model="taskForm.body"
              :placeholder="t('sales.deal.composer.notePlaceholder')"
              :rows="2"
              fluid
            />
          </div>
          <div class="col-12">
            <SelectButton
              v-model="taskForm.priority"
              :options="priorityOptions"
              option-label="label"
              option-value="value"
              size="small"
            />
          </div>
        </div>
        <div class="deal-composer__footer">
          <Button
            icon="pi pi-send"
            :label="t('sales.deal.composer.save')"
            :loading="saving"
            @click="submitTask"
          />
        </div>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import SelectButton from 'primevue/selectbutton'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import type { ActivityDto, ActivityKind, ActivityPriority, CreateActivityPayload } from '@/entities/activity'

// ─── Task subtype type ────────────────────────────────────────────────────────

type TaskSubtype = 'task' | 'call' | 'meeting' | 'follow_up'

// ─── Composer top-level tab (only note / task) ───────────────────────────────

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

/** Map any incoming ActivityKind to the two composer tabs */
function kindToTab(kind: ActivityKind | undefined): ComposerTab {
  if (!kind || kind === 'note') return 'note'
  return 'task'
}

const activeTab = ref<ComposerTab>(kindToTab(props.initialTab))

watch(
  () => props.initialTab,
  (kind) => {
    activeTab.value = kindToTab(kind)
    // When switching from outside with a specific subtype, mirror it
    if (kind && kind !== 'note') {
      const subtypes: TaskSubtype[] = ['task', 'call', 'meeting', 'follow_up']
      if (subtypes.includes(kind as TaskSubtype)) {
        taskForm.value.subtype = kind as TaskSubtype
      }
    }
  },
)

const errors = ref<{ title?: string }>({})

// ─── Type dropdown options (only Note + Task) ─────────────────────────────────

const composerTypeOptions = computed(() => [
  { value: 'note' as ComposerTab, label: t('sales.deal.composer.note') },
  { value: 'task' as ComposerTab, label: t('sales.deal.composer.task') },
])

// ─── Task subtype options ─────────────────────────────────────────────────────

const taskSubtypeOptions = computed(() => [
  { value: 'task' as TaskSubtype, label: t('sales.deal.composer.subtypes.task') },
  { value: 'call' as TaskSubtype, label: t('sales.deal.composer.subtypes.call') },
  { value: 'meeting' as TaskSubtype, label: t('sales.deal.composer.subtypes.meeting') },
  { value: 'follow_up' as TaskSubtype, label: t('sales.deal.composer.subtypes.follow_up') },
])

// ─── Priority options ─────────────────────────────────────────────────────────

const priorityOptions = computed(() => [
  { value: 'low' as ActivityPriority, label: t('activity.priorities.low') },
  { value: 'normal' as ActivityPriority, label: t('activity.priorities.normal') },
  { value: 'high' as ActivityPriority, label: t('activity.priorities.high') },
])

// ─── Form state ───────────────────────────────────────────────────────────────

const noteForm = ref({
  body: '',
})

const taskForm = ref({
  title: '',
  body: '',
  dueAt: null as Date | null,
  responsibleId: null as number | null,
  priority: 'normal' as ActivityPriority,
  subtype: 'task' as TaskSubtype,
})

function resetNote() {
  noteForm.value = { body: '' }
}

function resetTask() {
  taskForm.value = {
    title: '',
    body: '',
    dueAt: null,
    responsibleId: null,
    priority: 'normal',
    subtype: taskForm.value.subtype, // preserve subtype between submissions
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
      body: taskForm.value.body || null,
      due_at: taskForm.value.dueAt ? taskForm.value.dueAt.toISOString() : null,
      responsible_id: taskForm.value.responsibleId,
      priority: taskForm.value.priority,
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
.deal-composer {
  background: var(--p-card-background);
  border-top: 1px solid var(--p-surface-200);
  flex-shrink: 0;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}

.deal-composer__header {
  display: flex;
  align-items: center;
  padding: $space-2 $space-4 0;
  border-bottom: 1px solid var(--p-surface-100);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.deal-composer__type-select {
  :deep(.p-select) {
    border: none;
    background: transparent;
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;
    padding: 0;
    box-shadow: none;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }
}

.deal-composer__body {
  padding: $space-3 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.deal-composer__textarea {
  width: 100%;
  resize: vertical;
}

.deal-composer__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  margin-top: $space-1;
}

.deal-composer__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  display: block;
  margin-top: $space-1;
}
</style>
