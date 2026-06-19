<template>
  <div class="entity-composer">
    <!-- Type selector -->
    <div class="entity-composer__header">
      <Select
        v-model="activeTab"
        :options="composerTypeOptions"
        option-label="label"
        option-value="value"
        class="entity-composer__type-select"
        size="small"
      />
    </div>

    <div class="entity-composer__body">
      <!-- Note -->
      <template v-if="activeTab === 'note'">
        <Textarea
          v-model="noteForm.body"
          :placeholder="t('sales.deal.composer.notePlaceholder')"
          :rows="3"
          auto-resize
          fluid
          class="entity-composer__textarea"
        />
        <div class="entity-composer__footer">
          <Button
            icon="pi pi-send"
            :label="t('sales.deal.composer.save')"
            :loading="saving"
            @click="submitNote"
          />
        </div>
      </template>

      <!-- Task -->
      <template v-else>
        <div class="row g-2">
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
            <small v-if="errors.title" class="entity-composer__error">{{ errors.title }}</small>
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
        </div>
        <div class="entity-composer__footer">
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
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
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
const errors = ref<{ title?: string }>({})

const composerTypeOptions = computed(() => [
  { value: 'note' as ComposerTab, label: t('sales.deal.composer.note') },
  { value: 'task' as ComposerTab, label: t('sales.deal.composer.task') },
])

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
    errors.value.title = t('common.required')
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

const usersList = computed(() => props.usersList ?? [])
</script>

<style lang="scss" scoped>
.entity-composer {
  background: $surface-card;
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;
  overflow: hidden;

  .app-dark & {
    border-color: var(--p-surface-700);
  }
}

.entity-composer__header {
  padding: $space-2 $space-3;
  border-bottom: 1px solid var(--p-surface-200);
  background: var(--p-surface-50);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
    background: var(--p-surface-800);
  }
}

.entity-composer__type-select {
  width: 140px;
}

.entity-composer__body {
  padding: $space-3;
  display: flex;
  flex-direction: column;
  gap: $space-2;
}

.entity-composer__textarea {
  resize: none;
}

.entity-composer__footer {
  display: flex;
  justify-content: flex-end;
}

.entity-composer__error {
  color: var(--p-red-500);
  font-size: $font-size-xs;
}
</style>
