<template>
  <Dialog
    v-model:visible="visible"
    :header="t('activity.meetingReport.dialogTitle')"
    modal
    style="width: 600px"
    :closable="!saving"
    class="meeting-report-dialog"
  >
    <!-- Loading -->
    <div v-if="questionsLoading" class="meeting-report-dialog__spinner">
      <ProgressSpinner style="width: 36px; height: 36px" />
      <Skeleton height="40px" class="mb-2 mt-3" />
      <Skeleton height="40px" class="mb-2" />
      <Skeleton height="40px" />
    </div>

    <div v-else class="meeting-report-dialog__body">
      <!-- Empty questions notice -->
      <Message
        v-if="questions.length === 0"
        severity="info"
        :closable="false"
        class="mb-3"
      >
        {{ t('activity.meetingReport.emptyQuestions') }}
      </Message>

      <!-- Questions -->
      <template v-else>
        <div
          v-for="q in questions"
          :key="q.id"
          class="meeting-report-dialog__question"
        >
          <label class="meeting-report-dialog__label">
            {{ q.text }}
            <span v-if="q.is_required" class="meeting-report-dialog__req">*</span>
          </label>

          <!-- text question -->
          <Textarea
            v-if="q.kind === 'text'"
            v-model="answers[q.id]"
            class="w-full"
            :rows="2"
            auto-resize
          />

          <!-- select question -->
          <div v-else-if="q.kind === 'select'" class="meeting-report-dialog__options">
            <button
              v-for="opt in q.options"
              :key="opt.id"
              class="meeting-report-dialog__opt-btn"
              :class="{ 'meeting-report-dialog__opt-btn--active': answers[q.id] === opt.text }"
              type="button"
              @click="answers[q.id] = answers[q.id] === opt.text ? '' : opt.text"
            >
              {{ opt.text }}
            </button>
          </div>
        </div>
      </template>

      <!-- Comment -->
      <div class="meeting-report-dialog__question">
        <label class="meeting-report-dialog__label">
          {{ t('activity.meetingReport.comment') }}
        </label>
        <Textarea
          v-model="comment"
          class="w-full"
          :rows="4"
          :placeholder="t('activity.meetingReport.commentPlaceholder')"
        />
      </div>
    </div>

    <template #footer>
      <div class="meeting-report-dialog__footer">
        <Button
          :label="t('activity.meetingReport.cancel')"
          severity="secondary"
          text
          :disabled="saving"
          @click="visible = false"
        />
        <Button
          icon="pi pi-check"
          :label="t('activity.meetingReport.save')"
          :loading="saving"
          severity="primary"
          @click="onSubmit"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, reactive, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Textarea from 'primevue/textarea'
import ProgressSpinner from 'primevue/progressspinner'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import { getApiErrorMessage } from '@/utils/errors'
import type { MeetingReportQuestionDto } from '@/entities/activity'

const props = defineProps<{
  visible: boolean
  activityId: number
  dealId: number | null
  pipelineId?: number | null
}>()

const emit = defineEmits<{
  'update:visible': [v: boolean]
  saved: []
}>()

const { t } = useI18n()
const toast = useToast()

const visible = computed({
  get: () => props.visible,
  set: (v) => emit('update:visible', v),
})

const questionsLoading = ref(false)
const saving = ref(false)
const questions = ref<MeetingReportQuestionDto[]>([])
const answers = reactive<Record<number, string>>({})
const comment = ref('')

const mutation = useMutation()

async function loadQuestions() {
  questionsLoading.value = true
  try {
    const [qs] = await Promise.all([
      activityApi.getMeetingReportQuestions(props.pipelineId ?? null),
      loadExistingReport(),
    ])
    questions.value = qs
  } catch {
    questions.value = []
  } finally {
    questionsLoading.value = false
  }
}

async function loadExistingReport() {
  if (!props.activityId) return
  try {
    const activity = await activityApi.getActivity(props.activityId)
    if (activity.meeting_report_json) {
      for (const ans of activity.meeting_report_json.answers) {
        answers[ans.question_id] = ans.answer
      }
      comment.value = activity.meeting_report_json.comment ?? ''
    }
  } catch {
    // Silently ignore — dialog still works, just starts empty
  }
}

watch(
  () => props.visible,
  async (open) => {
    if (open) {
      comment.value = ''
      // Reset answers
      for (const key of Object.keys(answers)) {
        delete (answers as Record<string, string>)[key]
      }
      await loadQuestions()
    }
  },
  // immediate: true ensures loadQuestions() fires even when the component
  // is mounted with visible=true (v-if pattern in ActivityFormDialog).
  { immediate: true },
)

async function onSubmit() {
  if (!props.dealId) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      life: 3000,
    })
    return
  }

  const answersArray = Object.entries(answers)
    .filter(([, v]) => v && v.trim() !== '')
    .map(([id, answer]) => ({ question_id: Number(id), answer }))

  if (answersArray.length === 0 && !comment.value.trim()) {
    toast.add({
      severity: 'warn',
      summary: t('activity.meetingReport.errorEmpty'),
      life: 3000,
    })
    return
  }

  saving.value = true
  try {
    await mutation.run(() =>
      activityApi.saveMeetingReport(props.dealId!, {
        answers: answersArray,
        comment: comment.value.trim() || null,
        activity_id: props.activityId,
      }),
    )
    toast.add({
      severity: 'success',
      summary: t('activity.meetingReport.saveSuccess'),
      life: 3000,
    })
    emit('saved')
    visible.value = false
  } catch (err: unknown) {
    toast.add({
      severity: 'error',
      summary: t('errors.server_error'),
      detail: getApiErrorMessage(err, t('errors.server_error')),
      life: 4000,
    })
  } finally {
    saving.value = false
  }
}
</script>

<style lang="scss" scoped>
.meeting-report-dialog {
  &__spinner {
    padding: $space-4;
  }

  &__body {
    display: flex;
    flex-direction: column;
    gap: $space-4;
  }

  &__question {
    display: flex;
    flex-direction: column;
    gap: $space-1;
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

  &__options {
    display: flex;
    flex-wrap: wrap;
    gap: $space-2;
  }

  &__opt-btn {
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

    &--active {
      border-color: $primary-color;
      background: rgba($primary-color, 0.08);
      color: $primary-color;
      font-weight: $font-weight-semibold;
    }

    &:hover:not(.meeting-report-dialog__opt-btn--active) {
      border-color: $primary-color;
      color: $primary-color;
    }
  }

  &__footer {
    display: flex;
    justify-content: flex-end;
    gap: $space-2;
  }
}

.w-full {
  width: 100%;
}
</style>
