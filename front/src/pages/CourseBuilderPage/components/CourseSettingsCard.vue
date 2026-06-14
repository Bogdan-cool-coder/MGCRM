<template>
  <Card class="course-settings-card mb-4">
    <template #title>{{ t('onboarding.builder.settingsTitle') }}</template>
    <template #content>
      <div class="row g-3">
        <div class="col-12">
          <label class="form-label required">{{ t('onboarding.courses.columns.title') }}</label>
          <InputText v-model="localForm.title" class="w-100" />
        </div>

        <div class="col-12">
          <label class="form-label">{{ t('onboarding.courses.createDialog.description') }}</label>
          <Textarea v-model="localForm.description" :auto-resize="true" rows="3" class="w-100" />
        </div>

        <div class="col-12">
          <label class="form-label required">{{ t('onboarding.courses.createDialog.policyLabel') }}</label>
          <div class="mt-1">
            <SelectButton
              v-model="localForm.completion_policy"
              :options="policyOptions"
              option-label="label"
              option-value="value"
            />
          </div>
        </div>

        <div class="col-md-4">
          <label class="form-label required">{{ t('onboarding.courses.createDialog.passingScore') }}</label>
          <InputNumber
            v-model="localForm.passing_score_pct"
            :min="0"
            :max="100"
            suffix="%"
            class="w-100"
          />
        </div>

        <div class="col-md-4">
          <label class="form-label">{{ t('onboarding.courses.createDialog.deadlineDays') }}</label>
          <InputNumber v-model="localForm.deadline_days" :min="1" class="w-100" />
        </div>

        <div class="col-12">
          <label class="form-label">{{ t('onboarding.courses.createDialog.coverUrl') }}</label>
          <InputText v-model="localForm.cover_image_url" class="w-100" />
        </div>
      </div>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { reactive, watch, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import InputText from 'primevue/inputtext'
import Textarea from 'primevue/textarea'
import SelectButton from 'primevue/selectbutton'
import InputNumber from 'primevue/inputnumber'
import type { Course, CoursePatchPayload } from '@/entities/course'

const props = defineProps<{
  course: Course
}>()

const emit = defineEmits<{
  change: [payload: CoursePatchPayload]
}>()

const { t } = useI18n()

const localForm = reactive({
  title: props.course.title,
  description: props.course.description ?? '',
  completion_policy: props.course.completion_policy,
  passing_score_pct: props.course.passing_score_pct,
  deadline_days: props.course.deadline_days,
  cover_image_url: props.course.cover_image_path ?? '',
})

watch(
  () => props.course,
  (c) => {
    localForm.title = c.title
    localForm.description = c.description ?? ''
    localForm.completion_policy = c.completion_policy
    localForm.passing_score_pct = c.passing_score_pct
    localForm.deadline_days = c.deadline_days
    localForm.cover_image_url = c.cover_image_path ?? ''
  },
)

// Emit on any change (parent saves)
watch(localForm, (v) => {
  emit('change', {
    title: v.title,
    description: v.description || null,
    completion_policy: v.completion_policy,
    passing_score_pct: v.passing_score_pct,
    deadline_days: v.deadline_days,
    cover_image_url: v.cover_image_url || null,
  })
})

const policyOptions = computed(() => [
  { label: t('onboarding.courses.policy.informational'), value: 'informational' },
  { label: t('onboarding.courses.policy.soft_gate'), value: 'soft_gate' },
])
</script>

<style lang="scss" scoped>
.course-settings-card {
  :deep(.p-card-title) {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
  }
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
