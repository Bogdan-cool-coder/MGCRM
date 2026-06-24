<template>
  <Dialog
    v-model:visible="visible"
    :header="editing ? t('admin.meetingReportQuestions.edit') : t('admin.meetingReportQuestions.add')"
    modal
    :style="{ width: '34rem' }"
    :draggable="false"
  >
    <div class="row g-3">
      <!-- Pipeline (global by default) -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.meetingReportQuestions.fields.pipeline') }}</label>
        <Select
          v-model="form.pipeline_id"
          :options="pipelineOptions"
          option-label="label"
          option-value="value"
          class="w-100 mt-1"
        />
      </div>

      <!-- Text -->
      <div class="col-12">
        <label class="dir-dialog__label">{{ t('admin.meetingReportQuestions.fields.text') }}</label>
        <InputText
          v-model="form.text"
          class="w-100 mt-1"
          :class="{ 'p-invalid': textError }"
          autofocus
        />
        <small v-if="textError" class="p-error">{{ t('common.required') }}</small>
      </div>

      <!-- Kind -->
      <div class="col-6">
        <label class="dir-dialog__label">{{ t('admin.meetingReportQuestions.fields.kind') }}</label>
        <Select
          v-model="form.kind"
          :options="kindOptions"
          option-label="label"
          option-value="value"
          class="w-100 mt-1"
        />
      </div>

      <!-- Sort order -->
      <div class="col-6">
        <label class="dir-dialog__label">{{ t('admin.meetingReportQuestions.fields.sortOrder') }}</label>
        <InputNumber v-model="form.sort_order" :min="0" class="w-100 mt-1" />
      </div>

      <!-- Required -->
      <div class="col-6 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_required" />
        <label class="mb-0 dir-dialog__label">{{ t('admin.meetingReportQuestions.fields.isRequired') }}</label>
      </div>

      <!-- Active -->
      <div class="col-6 d-flex align-items-center gap-2">
        <ToggleSwitch v-model="form.is_active" />
        <label class="mb-0 dir-dialog__label">{{ t('admin.meetingReportQuestions.fields.isActive') }}</label>
      </div>

      <!-- Options (select kind only) -->
      <div v-if="form.kind === 'select'" class="col-12">
        <label class="dir-dialog__label">{{ t('admin.meetingReportQuestions.fields.options') }}</label>
        <div class="mt-1 d-flex flex-column gap-2">
          <div
            v-for="(opt, idx) in form.options"
            :key="idx"
            class="d-flex align-items-center gap-2"
          >
            <InputText v-model="opt.text" class="flex-grow-1" />
            <Button
              icon="pi pi-trash"
              text
              severity="danger"
              size="small"
              :title="t('common.delete')"
              @click="removeOption(idx)"
            />
          </div>
          <Button
            :label="t('admin.meetingReportQuestions.addOption')"
            icon="pi pi-plus"
            text
            severity="secondary"
            size="small"
            @click="addOption"
          />
          <small v-if="optionsError" class="p-error">
            {{ t('admin.meetingReportQuestions.optionsRequired') }}
          </small>
        </div>
      </div>
    </div>

    <template #footer>
      <Button :label="t('common.cancel')" severity="secondary" text @click="cancel" />
      <Button :label="t('common.save')" :loading="loading" @click="submit" />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import InputText from 'primevue/inputtext'
import InputNumber from 'primevue/inputnumber'
import Select from 'primevue/select'
import ToggleSwitch from 'primevue/toggleswitch'
import type {
  MeetingReportQuestionDto,
  SaveMeetingReportQuestionPayload,
} from '@/entities/activity'
import type { PipelineDto } from '@/entities/sales'

const props = defineProps<{
  modelValue: boolean
  editing: MeetingReportQuestionDto | null
  pipelines: PipelineDto[]
  loading: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  save: [payload: SaveMeetingReportQuestionPayload]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

interface QuestionForm {
  pipeline_id: number | null
  text: string
  kind: 'text' | 'select'
  is_required: boolean
  is_active: boolean
  sort_order: number
  options: Array<{ text: string }>
}

function emptyForm(): QuestionForm {
  return {
    pipeline_id: null,
    text: '',
    kind: 'text',
    is_required: false,
    is_active: true,
    sort_order: 0,
    options: [],
  }
}

const form = ref<QuestionForm>(emptyForm())
const textError = ref(false)
const optionsError = ref(false)

const pipelineOptions = computed(() => [
  { label: t('admin.meetingReportQuestions.global'), value: null },
  ...props.pipelines.map((p) => ({ label: p.name, value: p.id })),
])

const kindOptions = computed(() => [
  { label: t('admin.meetingReportQuestions.kinds.text'), value: 'text' },
  { label: t('admin.meetingReportQuestions.kinds.select'), value: 'select' },
])

watch(
  () => props.modelValue,
  (open) => {
    if (!open) return
    textError.value = false
    optionsError.value = false
    if (props.editing) {
      form.value = {
        pipeline_id: props.editing.pipeline_id ?? null,
        text: props.editing.text,
        kind: props.editing.kind,
        is_required: props.editing.is_required,
        is_active: props.editing.is_active ?? true,
        sort_order: props.editing.sort_order,
        options: props.editing.options.map((o) => ({ text: o.text })),
      }
    } else {
      form.value = emptyForm()
    }
  },
)

function addOption() {
  form.value.options.push({ text: '' })
}

function removeOption(idx: number) {
  form.value.options.splice(idx, 1)
}

function cancel() {
  visible.value = false
}

function submit() {
  textError.value = !form.value.text.trim()

  const cleanedOptions = form.value.options
    .map((o) => ({ text: o.text.trim() }))
    .filter((o) => o.text !== '')

  optionsError.value = form.value.kind === 'select' && cleanedOptions.length === 0

  if (textError.value || optionsError.value) return

  const payload: SaveMeetingReportQuestionPayload = {
    pipeline_id: form.value.pipeline_id,
    text: form.value.text.trim(),
    kind: form.value.kind,
    is_required: form.value.is_required,
    is_active: form.value.is_active,
    sort_order: form.value.sort_order,
    options: form.value.kind === 'select'
      ? cleanedOptions.map((o, i) => ({ text: o.text, sort_order: i }))
      : [],
  }

  emit('save', payload)
  // Parent composable closes the dialog in its onSuccess handler after the API
  // responds — do NOT close here (races the async mutation, re-opens on pending).
}
</script>

<style lang="scss" scoped>
.dir-dialog {
  &__label {
    font-size: $font-size-sm;
    font-weight: $font-weight-medium;
    color: var(--p-text-color);
    display: block;
  }
}
</style>
