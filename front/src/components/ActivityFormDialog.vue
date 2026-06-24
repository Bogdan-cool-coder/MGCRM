<template>
  <Dialog
    v-model:visible="visible"
    :header="isEditMode ? t('activity.form.editTitle') : t('activity.form.createTitle')"
    modal
    style="width: 520px"
    :closable="!saving"
    class="activity-form-dialog"
    @hide="onHide"
  >
    <!-- Loading in edit mode -->
    <div v-if="loadingActivity" class="activity-form-dialog__spinner">
      <ProgressSpinner style="width: 40px; height: 40px" />
    </div>

    <div v-else class="activity-form-dialog__body">
      <!-- Kind SelectButton -->
      <div class="activity-form-dialog__field">
        <label class="activity-form-dialog__label">
          {{ t('activity.form.kind') }} <span class="activity-form-dialog__req">*</span>
        </label>
        <div class="activity-form-dialog__kind-row">
          <button
            v-for="opt in kindOptions"
            :key="opt.value"
            class="activity-form-dialog__kind-btn"
            :class="{
              'activity-form-dialog__kind-btn--active': form.kind === opt.value,
              'activity-form-dialog__kind-btn--disabled': opt.disabled,
            }"
            :disabled="opt.disabled || isEditMode"
            :title="opt.disabled ? t('activity.form.kindDisabledTooltip') : opt.label"
            type="button"
            @click="!opt.disabled && !isEditMode && (form.kind = opt.value)"
          >
            <i :class="opt.icon" class="activity-form-dialog__kind-icon" />
            <span>{{ opt.label }}</span>
          </button>
        </div>
      </div>

      <!-- Title -->
      <div class="activity-form-dialog__field">
        <label class="activity-form-dialog__label">
          {{ t('activity.form.title') }} <span class="activity-form-dialog__req">*</span>
        </label>
        <InputText
          v-model="form.title"
          class="w-full"
          :class="{ 'p-invalid': errors.title }"
          :placeholder="t('activity.form.titlePlaceholder')"
        />
        <small v-if="errors.title" class="p-error">{{ errors.title }}</small>
      </div>

      <!-- Body -->
      <div class="activity-form-dialog__field">
        <label class="activity-form-dialog__label">{{ t('activity.form.body') }}</label>
        <Textarea
          v-model="form.body"
          class="w-full"
          :rows="3"
          auto-resize
          :placeholder="t('activity.form.body')"
        />
      </div>

      <!-- Responsible -->
      <div class="activity-form-dialog__field">
        <label class="activity-form-dialog__label">{{ t('activity.form.responsible') }}</label>
        <Select
          v-model="form.responsible_id"
          :options="users"
          option-label="full_name"
          option-value="id"
          filter
          show-clear
          class="w-full"
          :placeholder="t('activity.form.responsible')"
        />
      </div>

      <!-- Due at (hidden for note) -->
      <div v-if="form.kind !== 'note'" class="activity-form-dialog__field">
        <label class="activity-form-dialog__label">{{ t('activity.form.dueAt') }}</label>
        <DatePicker
          v-model="form.due_at"
          show-icon
          show-time
          hour-format="24"
          date-format="dd.mm.yy"
          class="w-full"
        />
      </div>

      <!-- Priority (task only) -->
      <div v-if="form.kind === 'task'" class="activity-form-dialog__field">
        <label class="activity-form-dialog__label">{{ t('activity.form.priority') }}</label>
        <div class="activity-form-dialog__kind-row">
          <button
            v-for="opt in priorityOptions"
            :key="opt.value"
            class="activity-form-dialog__kind-btn"
            :class="{ 'activity-form-dialog__kind-btn--active': form.priority === opt.value }"
            type="button"
            @click="form.priority = opt.value"
          >
            {{ opt.label }}
          </button>
        </div>
      </div>

      <!-- Meeting fields -->
      <template v-if="form.kind === 'meeting'">
        <div class="activity-form-dialog__field activity-form-dialog__field--row">
          <label class="activity-form-dialog__label">
            {{ t('activity.form.ftmDecisionMakerAttended') }}
          </label>
          <ToggleSwitch v-model="form.ftm_decision_maker_attended" />
        </div>
        <div class="activity-form-dialog__field activity-form-dialog__field--row">
          <label class="activity-form-dialog__label">
            {{ t('activity.form.ftmPresentationShown') }}
          </label>
          <ToggleSwitch v-model="form.ftm_presentation_shown" />
        </div>
        <div class="activity-form-dialog__field">
          <label class="activity-form-dialog__label">{{ t('activity.form.ftmReportUrl') }}</label>
          <InputText
            v-model="form.ftm_report_url"
            class="w-full"
            placeholder="https://..."
          />
        </div>
      </template>

      <!-- Result text (edit mode, done status) -->
      <div
        v-if="isEditMode && form.status === 'done'"
        class="activity-form-dialog__field"
      >
        <label class="activity-form-dialog__label">{{ t('activity.form.resultText') }}</label>
        <Textarea
          v-model="form.result_text"
          class="w-full"
          :rows="2"
          :placeholder="t('activity.form.resultTextPlaceholder')"
        />
      </div>
    </div>

    <template #footer>
      <div class="activity-form-dialog__footer">
        <Button
          :label="t('activity.form.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="onCancel"
        />
        <Button
          v-if="isEditMode && form.kind === 'meeting' && savedActivityId"
          :label="t('activity.form.fillMeetingReport')"
          severity="secondary"
          text
          @click="onFillReport"
        />
        <Button
          icon="pi pi-check"
          :label="isEditMode ? t('activity.form.save') : t('activity.form.create')"
          :loading="saving"
          severity="primary"
          @click="onSubmit"
        />
      </div>
    </template>
  </Dialog>

  <MeetingReportDialog
    v-if="meetingReportOpen"
    v-model:visible="meetingReportOpen"
    :activity-id="savedActivityId ?? 0"
    :deal-id="meetingReportDealId"
    :pipeline-id="meetingReportPipelineId"
    @saved="meetingReportOpen = false"
  />
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import Select from 'primevue/select'
import DatePicker from 'primevue/datepicker'
import ToggleSwitch from 'primevue/toggleswitch'
import ProgressSpinner from 'primevue/progressspinner'
import MeetingReportDialog from './MeetingReportDialog.vue'
import { activityApi } from '@/api/activity'
import { usersApi } from '@/api/users'
import { salesApi } from '@/api/sales'
import { useMutation } from '@/composables/async/useMutation'
import { kindIcon } from '@/utils/activity'
import { getApiErrorStatus, getValidationErrors, getApiErrorMessage } from '@/utils/errors'
import type {
  ActivityDto,
  ActivityKind,
  ActivityStatus,
  ActivityPriority,
  ActivityTargetType,
} from '@/entities/activity'

const props = defineProps<{
  modelValue: boolean
  activityId?: number | null
  targetType?: ActivityTargetType | null
  targetId?: number | null
  defaultKind?: ActivityKind
  allowedKinds?: ActivityKind[]
}>()

const emit = defineEmits<{
  'update:modelValue': [v: boolean]
  created: [activity: ActivityDto]
  updated: [activity: ActivityDto]
}>()

const { t } = useI18n()
const toast = useToast()
const confirm = useConfirm()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

const isEditMode = computed(() => !!props.activityId)

// ── State ──────────────────────────────────────────────────────────────────────

const loadingActivity = ref(false)
const saving = ref(false)
const isDirty = ref(false)
const savedActivityId = ref<number | null>(null)
const meetingReportOpen = ref(false)
const meetingReportDealId = ref<number | null>(null)
// Pipeline of the target deal, threaded into MeetingReportDialog so per-pipeline
// questions are reachable from this entry too (mirrors DealFeedItem). Resolved
// lazily on fill-report because the dialog only has the deal id, not the deal.
const meetingReportPipelineId = ref<number | null>(null)

interface ActivityForm {
  kind: ActivityKind
  title: string
  body: string
  responsible_id: number | null
  due_at: Date | null
  priority: ActivityPriority
  status: ActivityStatus | null
  result_text: string
  ftm_decision_maker_attended: boolean
  ftm_presentation_shown: boolean
  ftm_report_url: string
}

function defaultKindValue(): ActivityKind {
  // When the stage restricts activity types, default to the first allowed kind
  // instead of 'task' — which may be disabled and would force the user to
  // manually select before being able to submit.
  const firstAllowed = props.allowedKinds && props.allowedKinds.length > 0
    ? props.allowedKinds[0]
    : undefined
  if (firstAllowed !== undefined) return firstAllowed
  return props.defaultKind ?? 'task'
}

function defaultForm(): ActivityForm {
  return {
    kind: defaultKindValue(),
    title: '',
    body: '',
    responsible_id: null,
    due_at: null,
    priority: 'normal',
    status: null,
    result_text: '',
    ftm_decision_maker_attended: false,
    ftm_presentation_shown: false,
    ftm_report_url: '',
  }
}

const form = ref<ActivityForm>(defaultForm())
const errors = ref<Record<string, string>>({})
const users = ref<{ id: number; full_name: string }[]>([])

// ── Options ────────────────────────────────────────────────────────────────────

const ALL_KINDS: ActivityKind[] = ['call', 'meeting', 'task', 'note']

const kindOptions = computed(() =>
  ALL_KINDS.map((k) => ({
    value: k,
    label: t(`activity.kinds.${k}`),
    icon: kindIcon(k),
    disabled:
      props.allowedKinds && props.allowedKinds.length > 0
        ? !props.allowedKinds.includes(k)
        : false,
  })),
)

const priorityOptions = computed(() =>
  (['low', 'normal', 'high', 'critical'] as ActivityPriority[]).map((p) => ({
    value: p,
    label: t(`activity.priorities.${p}`),
  })),
)

// ── Load data ──────────────────────────────────────────────────────────────────

async function loadUsers() {
  try {
    const list = await usersApi.getUsers()
    users.value = list.map((u) => ({ id: u.id, full_name: u.full_name }))
  } catch {
    // non-critical
  }
}

async function loadActivity() {
  if (!props.activityId) return
  loadingActivity.value = true
  try {
    const activity = await activityApi.getActivity(props.activityId)
    savedActivityId.value = activity.id
    form.value = {
      kind: activity.kind,
      title: activity.title,
      body: activity.body ?? '',
      responsible_id: activity.responsible?.id ?? null,
      due_at: activity.due_at ? new Date(activity.due_at) : null,
      priority: activity.priority,
      status: activity.status,
      result_text: activity.result_text ?? '',
      ftm_decision_maker_attended: activity.ftm_decision_maker_attended,
      ftm_presentation_shown: activity.ftm_presentation_shown,
      ftm_report_url: activity.ftm_report_url ?? '',
    }
    // If this is a deal activity, store deal id for meeting report
    if (activity.target_type === 'deal' && activity.target_id) {
      meetingReportDealId.value = activity.target_id
    }
  } catch {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      life: 4000,
    })
    visible.value = false
  } finally {
    loadingActivity.value = false
  }
}

watch(
  () => props.modelValue,
  async (open) => {
    if (open) {
      errors.value = {}
      isDirty.value = false
      savedActivityId.value = props.activityId ?? null
      meetingReportDealId.value =
        props.targetType === 'deal' && props.targetId ? props.targetId : null
      // Reset the resolved pipeline so a reused dialog never carries a stale
      // pipeline id from a previously-opened deal.
      meetingReportPipelineId.value = null
      if (isEditMode.value) {
        form.value = defaultForm()
        await Promise.all([loadUsers(), loadActivity()])
      } else {
        form.value = defaultForm()
        await loadUsers()
      }
    }
  },
  { immediate: false },
)

watch(form, () => {
  isDirty.value = true
}, { deep: true })

// ── Submit ─────────────────────────────────────────────────────────────────────

const mutation = useMutation<ActivityDto>()

async function onSubmit() {
  errors.value = {}

  if (!form.value.title.trim()) {
    errors.value.title = t('errors.validation')
    return
  }

  saving.value = true
  try {
    let result: ActivityDto

    const payload = {
      kind: form.value.kind,
      title: form.value.title.trim(),
      body: form.value.body || null,
      responsible_id: form.value.responsible_id,
      due_at: form.value.due_at ? form.value.due_at.toISOString() : null,
      priority: form.value.priority,
      result_text: form.value.result_text || null,
      ftm_decision_maker_attended: form.value.ftm_decision_maker_attended,
      ftm_presentation_shown: form.value.ftm_presentation_shown,
      ftm_report_url: form.value.ftm_report_url || null,
    }

    if (isEditMode.value && props.activityId) {
      result = await mutation.run(() => activityApi.updateActivity(props.activityId!, payload))
      emit('updated', result)
    } else {
      const createPayload = {
        ...payload,
        target_type: props.targetType ?? null,
        target_id: props.targetId ?? null,
      }
      result = await mutation.run(() => activityApi.createActivity(createPayload))
      savedActivityId.value = result.id
      emit('created', result)
    }

    isDirty.value = false
    visible.value = false
  } catch (err: unknown) {
    const status = getApiErrorStatus(err)
    if (status === 422) {
      const validationErrors = getValidationErrors(err)
      errors.value = validationErrors ?? {}
    } else {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    }
  } finally {
    saving.value = false
  }
}

function onCancel() {
  if (isDirty.value) {
    confirm.require({
      header: t('activity.form.confirmDiscard'),
      message: t('activity.form.confirmDiscardBody'),
      acceptLabel: t('common.confirm'),
      rejectLabel: t('common.cancel'),
      accept: () => {
        visible.value = false
      },
    })
  } else {
    visible.value = false
  }
}

async function onFillReport() {
  // Resolve the deal's pipeline so MeetingReportDialog can load per-pipeline
  // questions (not just the global ones). Best-effort: on failure the dialog
  // degrades to global questions, same as before.
  if (meetingReportDealId.value !== null && meetingReportPipelineId.value === null) {
    try {
      const deal = await salesApi.getDeal(meetingReportDealId.value)
      meetingReportPipelineId.value = deal.pipeline?.id ?? null
    } catch {
      meetingReportPipelineId.value = null
    }
  }
  meetingReportOpen.value = true
}

function onHide() {
  isDirty.value = false
  errors.value = {}
}
</script>

<style lang="scss" scoped>
.activity-form-dialog {
  &__spinner {
    display: flex;
    justify-content: center;
    align-items: center;
    padding: $space-6;
  }

  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-4;
  }

  &__field {
    display: flex;
    flex-direction: column;
    gap: $space-1;

    &--row {
      flex-direction: row;
      align-items: center;
      justify-content: space-between;
    }
  }

  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: $surface-700;

    :global(.app-dark) & {
      color: var(--p-surface-300);
    }
  }

  &__req {
    color: var(--p-red-500);
  }

  &__kind-row {
    display: flex;
    flex-wrap: wrap;
    gap: $space-2;
  }

  &__kind-btn {
    display: flex;
    align-items: center;
    gap: $space-1;
    padding: $space-1 $space-3;
    border: 1px solid $surface-300;
    border-radius: $radius-md;
    background: $surface-card;
    color: $surface-600;
    font-size: $font-size-sm;
    cursor: pointer;
    transition: all var(--app-transition-fast);

    :global(.app-dark) & {
      border-color: var(--p-surface-600);
      background: var(--p-surface-800);
      color: var(--p-surface-300);
    }

    &:hover:not(:disabled) {
      border-color: $primary-color;
      color: $primary-color;
    }

    &--active {
      border-color: $primary-color;
      background: rgba($primary-color, 0.08);
      color: $primary-color;
      font-weight: $font-weight-semibold;

      :global(.app-dark) & {
        background: rgba(23, 39, 71, 0.4);
      }
    }

    &--disabled {
      opacity: 0.45;
      cursor: not-allowed;
    }
  }

  &__kind-icon {
    font-size: $font-size-sm;
  }

  &__footer {
    display: flex;
    align-items: center;
    justify-content: flex-end;
    gap: $space-2;
    flex-wrap: wrap;
  }
}

.w-full {
  width: 100%;
}
</style>
