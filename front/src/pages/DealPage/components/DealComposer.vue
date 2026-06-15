<template>
  <div class="deal-composer">
    <!-- Tab bar -->
    <Tabs v-model:value="activeTab" class="deal-composer__tabs">
      <TabList class="deal-composer__tab-list">
        <Tab value="note">{{ t('sales.deal.composer.note') }}</Tab>
        <Tab value="task">{{ t('sales.deal.composer.task') }}</Tab>
        <Tab value="call">{{ t('sales.deal.composer.call') }}</Tab>
        <Tab value="meeting">{{ t('sales.deal.composer.meeting') }}</Tab>
      </TabList>
    </Tabs>

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
          <MultiSelect
            v-model="noteForm.participantIds"
            :options="usersList"
            option-label="name"
            option-value="id"
            :placeholder="t('sales.deal.composer.participants')"
            display="chip"
            size="small"
            class="deal-composer__participants"
          />
          <Button
            icon="pi pi-send"
            :label="t('sales.deal.composer.save')"
            :loading="saving"
            @click="submitNote"
          />
        </div>
      </template>

      <!-- ─── Task ──────────────────────────────────────────────────────────── -->
      <template v-else-if="activeTab === 'task'">
        <div class="row g-2">
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
          <MultiSelect
            v-model="taskForm.participantIds"
            :options="usersList"
            option-label="name"
            option-value="id"
            :placeholder="t('sales.deal.composer.participants')"
            display="chip"
            size="small"
            class="deal-composer__participants"
          />
          <Button
            icon="pi pi-send"
            :label="t('sales.deal.composer.save')"
            :loading="saving"
            @click="submitTask"
          />
        </div>
      </template>

      <!-- ─── Call ──────────────────────────────────────────────────────────── -->
      <template v-else-if="activeTab === 'call'">
        <div class="row g-2">
          <div class="col-12">
            <InputText
              v-model="callForm.title"
              :placeholder="t('sales.deal.composer.titlePlaceholder')"
              :invalid="!!errors.title"
              fluid
            />
            <small v-if="errors.title" class="deal-composer__error">{{ errors.title }}</small>
          </div>
          <div class="col-6">
            <DatePicker
              v-model="callForm.dueAt"
              show-icon
              fluid
              :placeholder="t('common.date')"
            />
          </div>
          <div class="col-6">
            <Select
              v-model="callForm.responsibleId"
              :options="usersList"
              option-label="name"
              option-value="id"
              :placeholder="t('activity.fields.responsible')"
              show-clear
              fluid
            />
          </div>
          <div class="col-12">
            <Textarea
              v-model="callForm.body"
              :placeholder="t('sales.deal.composer.notePlaceholder')"
              :rows="2"
              fluid
            />
          </div>
        </div>
        <div class="deal-composer__footer">
          <span />
          <Button
            icon="pi pi-send"
            :label="t('sales.deal.composer.save')"
            :loading="saving"
            @click="submitCall"
          />
        </div>
      </template>

      <!-- ─── Meeting ───────────────────────────────────────────────────────── -->
      <template v-else-if="activeTab === 'meeting'">
        <div class="row g-2">
          <div class="col-12">
            <InputText
              v-model="meetingForm.title"
              :placeholder="t('sales.deal.composer.titlePlaceholder')"
              :invalid="!!errors.title"
              fluid
            />
            <small v-if="errors.title" class="deal-composer__error">{{ errors.title }}</small>
          </div>
          <div class="col-6">
            <DatePicker
              v-model="meetingForm.dueAt"
              show-time
              show-icon
              fluid
              :placeholder="t('common.date')"
            />
          </div>
          <div class="col-6">
            <InputText
              v-model="meetingForm.location"
              :placeholder="t('sales.deal.composer.location')"
              fluid
            />
          </div>
          <div class="col-12">
            <Textarea
              v-model="meetingForm.body"
              :placeholder="t('sales.deal.composer.notePlaceholder')"
              :rows="2"
              fluid
            />
          </div>
        </div>
        <div class="deal-composer__footer">
          <MultiSelect
            v-model="meetingForm.participantIds"
            :options="usersList"
            option-label="name"
            option-value="id"
            :placeholder="t('sales.deal.composer.participants')"
            display="chip"
            size="small"
            class="deal-composer__participants"
          />
          <Button
            icon="pi pi-send"
            :label="t('sales.deal.composer.save')"
            :loading="saving"
            @click="submitMeeting"
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
import Tabs from 'primevue/tabs'
import TabList from 'primevue/tablist'
import Tab from 'primevue/tab'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import DatePicker from 'primevue/datepicker'
import Select from 'primevue/select'
import MultiSelect from 'primevue/multiselect'
import SelectButton from 'primevue/selectbutton'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import type { ActivityDto, ActivityKind, ActivityPriority, CreateActivityPayload } from '@/entities/activity'

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

const activeTab = ref<ActivityKind>(props.initialTab ?? 'note')

watch(
  () => props.initialTab,
  (tab) => {
    if (tab) activeTab.value = tab
  },
)

const errors = ref<{ title?: string }>({})

// ─── Priority options ─────────────────────────────────────────────────────────

const priorityOptions = computed(() => [
  { value: 'low' as ActivityPriority, label: t('activity.priorities.low') },
  { value: 'normal' as ActivityPriority, label: t('activity.priorities.normal') },
  { value: 'high' as ActivityPriority, label: t('activity.priorities.high') },
])

// ─── Per-tab form state ───────────────────────────────────────────────────────

const noteForm = ref({
  body: '',
  participantIds: [] as number[],
})

const taskForm = ref({
  title: '',
  body: '',
  dueAt: null as Date | null,
  responsibleId: null as number | null,
  priority: 'normal' as ActivityPriority,
  participantIds: [] as number[],
})

const callForm = ref({
  title: '',
  body: '',
  dueAt: null as Date | null,
  responsibleId: null as number | null,
})

const meetingForm = ref({
  title: '',
  body: '',
  dueAt: null as Date | null,
  location: '',
  participantIds: [] as number[],
})

// ─── Reset helpers ────────────────────────────────────────────────────────────

function resetNote() {
  noteForm.value = { body: '', participantIds: [] }
}
function resetTask() {
  taskForm.value = {
    title: '',
    body: '',
    dueAt: null,
    responsibleId: null,
    priority: 'normal',
    participantIds: [],
  }
}
function resetCall() {
  callForm.value = { title: '', body: '', dueAt: null, responsibleId: null }
}
function resetMeeting() {
  meetingForm.value = { title: '', body: '', dueAt: null, location: '', participantIds: [] }
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
    await doCreate({
      kind: 'task',
      title: taskForm.value.title.trim(),
      body: taskForm.value.body || null,
      due_at: taskForm.value.dueAt ? taskForm.value.dueAt.toISOString() : null,
      responsible_id: taskForm.value.responsibleId,
      priority: taskForm.value.priority,
      target_type: 'deal',
      target_id: props.dealId,
    })
    resetTask()
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

async function submitCall() {
  errors.value = {}
  if (!callForm.value.title.trim()) {
    errors.value.title = t('common.required')
    return
  }
  try {
    await doCreate({
      kind: 'call',
      title: callForm.value.title.trim(),
      body: callForm.value.body || null,
      due_at: callForm.value.dueAt ? callForm.value.dueAt.toISOString() : null,
      responsible_id: callForm.value.responsibleId,
      target_type: 'deal',
      target_id: props.dealId,
    })
    resetCall()
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

async function submitMeeting() {
  errors.value = {}
  if (!meetingForm.value.title.trim()) {
    errors.value.title = t('common.required')
    return
  }
  try {
    await doCreate({
      kind: 'meeting',
      title: meetingForm.value.title.trim(),
      body: meetingForm.value.body || null,
      due_at: meetingForm.value.dueAt ? meetingForm.value.dueAt.toISOString() : null,
      target_type: 'deal',
      target_id: props.dealId,
    })
    resetMeeting()
  } catch {
    toast.add({ severity: 'error', summary: t('errors.server_error'), life: 3000 })
  }
}

// ─── Expose tab setter (for parent to switch tab) ─────────────────────────────

function setTab(tab: ActivityKind) {
  activeTab.value = tab
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

.deal-composer__tabs {
  :deep(.p-tablist) {
    border-bottom: 1px solid var(--p-surface-200);
    padding: 0 $space-3;

    .app-dark & {
      border-bottom-color: var(--p-surface-700);
    }
  }

  :deep(.p-tab) {
    padding: $space-2 $space-3;
    font-size: $font-size-sm;
  }
}

.deal-composer__tab-list {
  // compact style
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
  justify-content: space-between;
  gap: $space-2;
  margin-top: $space-1;
}

.deal-composer__participants {
  flex: 1;
  min-width: 0;
  max-width: 260px;
}

.deal-composer__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
  display: block;
  margin-top: $space-1;
}
</style>
