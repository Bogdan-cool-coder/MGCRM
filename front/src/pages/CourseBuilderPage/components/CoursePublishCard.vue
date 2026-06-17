<template>
  <Card class="mb-3">
    <template #title>{{ t('onboarding.builder.publishCard.title') }}</template>
    <template #content>
      <div class="d-flex align-items-center gap-2 mb-3">
        <span>{{ t('onboarding.builder.publishCard.status') }}:</span>
        <CourseStatusTag :is-published="course.is_published" />
      </div>
      <Button
        v-if="!course.is_published"
        :label="t('onboarding.courses.publish')"
        icon="pi pi-send"
        severity="success"
        class="w-100"
        :loading="saving"
        @click="emit('publish')"
      />
      <Button
        v-else
        :label="t('onboarding.courses.unpublish')"
        icon="pi pi-eye-slash"
        severity="warn"
        outlined
        class="w-100"
        :loading="saving"
        @click="emit('unpublish')"
      />
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import CourseStatusTag from '@/components/shared/CourseStatusTag.vue'
import type { Course } from '@/entities/course'

defineProps<{
  course: Course
  saving: boolean
}>()

const emit = defineEmits<{
  publish: []
  unpublish: []
}>()

const { t } = useI18n()
</script>
