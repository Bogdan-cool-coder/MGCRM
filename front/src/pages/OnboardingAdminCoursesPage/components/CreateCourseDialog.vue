<template>
  <Dialog
    v-model:visible="visible"
    :header="t('onboarding.courses.createDialog.title')"
    modal
    :style="{ width: '36rem' }"
    :draggable="false"
    @hide="reset"
  >
    <div class="create-course-form">
      <div class="mb-3">
        <label class="form-label required">{{ t('onboarding.courses.createDialog.name') }}</label>
        <InputText
          v-model="form.title"
          :placeholder="t('onboarding.courses.createDialog.namePlaceholder')"
          class="w-100"
          :invalid="!!errors.title"
        />
        <small v-if="errors.title" class="text-danger">{{ errors.title }}</small>
      </div>

      <div class="mb-3">
        <label class="form-label">{{ t('onboarding.courses.createDialog.description') }}</label>
        <Textarea
          v-model="form.description"
          :auto-resize="true"
          rows="3"
          class="w-100"
        />
      </div>

      <div class="mb-3">
        <label class="form-label required">{{ t('onboarding.courses.createDialog.policyLabel') }}</label>
        <div class="mt-1">
          <SelectButton
            v-model="form.completion_policy"
            :options="policyOptions"
            option-label="label"
            option-value="value"
            class="w-100"
          />
        </div>
      </div>

      <div class="row g-3 mb-3">
        <div class="col-6">
          <label class="form-label required">{{ t('onboarding.courses.createDialog.passingScore') }}</label>
          <InputNumber
            v-model="form.passing_score_pct"
            :min="0"
            :max="100"
            suffix="%"
            class="w-100"
            :invalid="!!errors.passing_score_pct"
          />
          <small v-if="errors.passing_score_pct" class="text-danger">{{ errors.passing_score_pct }}</small>
        </div>
        <div class="col-6">
          <label class="form-label">{{ t('onboarding.courses.createDialog.deadlineDays') }}</label>
          <InputNumber
            v-model="form.deadline_days"
            :min="1"
            class="w-100"
          />
        </div>
      </div>

      <div class="mb-3">
        <label class="form-label">{{ t('onboarding.courses.createDialog.coverUrl') }}</label>
        <InputText
          v-model="form.cover_image_url"
          class="w-100"
        />
      </div>

      <!-- Validation error -->
      <Message v-if="formError" severity="error" :closable="false" class="mb-3">
        {{ formError }}
      </Message>
    </div>

    <template #footer>
      <Button
        :label="t('onboarding.courses.createDialog.cancel')"
        severity="secondary"
        outlined
        :disabled="saving"
        @click="visible = false"
      />
      <Button
        :label="t('onboarding.courses.createDialog.submit')"
        icon="pi pi-arrow-right"
        icon-pos="right"
        :loading="saving"
        @click="submit"
      />
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { ref, reactive, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Dialog from 'primevue/dialog'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import SelectButton from 'primevue/selectbutton'
import InputNumber from 'primevue/inputnumber'
import Button from 'primevue/button'
import Message from 'primevue/message'
import type { CourseCreatePayload } from '@/entities/course'

const { t } = useI18n()

const visible = defineModel<boolean>('visible', { default: false })

const emit = defineEmits<{
  create: [payload: CourseCreatePayload]
}>()

const saving = ref(false)
const formError = ref('')

const form = reactive({
  title: '',
  description: '',
  completion_policy: 'soft_gate' as 'soft_gate' | 'informational',
  passing_score_pct: 80,
  deadline_days: null as number | null,
  cover_image_url: '',
})

const errors = reactive({
  title: '',
  passing_score_pct: '',
})

const policyOptions = computed(() => [
  { label: t('onboarding.courses.policy.informational'), value: 'informational' },
  { label: t('onboarding.courses.policy.soft_gate'), value: 'soft_gate' },
])

function validate(): boolean {
  errors.title = ''
  errors.passing_score_pct = ''
  formError.value = ''
  let ok = true
  if (!form.title.trim()) {
    errors.title = t('common.required')
    ok = false
  }
  if (form.passing_score_pct == null || form.passing_score_pct < 0 || form.passing_score_pct > 100) {
    errors.passing_score_pct = t('common.invalidValue')
    ok = false
  }
  return ok
}

async function submit(): Promise<void> {
  if (!validate()) return
  saving.value = true
  formError.value = ''
  try {
    emit('create', {
      title: form.title.trim(),
      description: form.description.trim() || null,
      completion_policy: form.completion_policy,
      passing_score_pct: form.passing_score_pct,
      deadline_days: form.deadline_days,
      cover_image_url: form.cover_image_url.trim() || null,
    })
  } finally {
    saving.value = false
  }
}

function reset(): void {
  form.title = ''
  form.description = ''
  form.completion_policy = 'soft_gate'
  form.passing_score_pct = 80
  form.deadline_days = null
  form.cover_image_url = ''
  errors.title = ''
  errors.passing_score_pct = ''
  formError.value = ''
}
</script>

<style lang="scss" scoped>
.create-course-form {
  padding-top: $space-2;
}

.form-label {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: $surface-700;
  margin-bottom: $space-1;
  display: block;

  &.required::after {
    content: ' *';
    color: var(--p-red-500);
  }
}
</style>
