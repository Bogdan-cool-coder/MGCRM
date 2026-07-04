<template>
  <Card class="mb-3">
    <template #title>{{ t('onboarding.builder.publishCard.title') }}</template>
    <template #content>
      <div class="publish-card__status d-flex align-items-center mb-3">
        <span>{{ t('onboarding.builder.publishCard.status') }}:</span>
        <CourseStatusTag :is-published="course.is_published" />
      </div>
      <Button
        v-if="!course.is_published"
        :label="t('onboarding.courses.publish')"
        icon="pi pi-send"
        severity="success"
        class="w-full"
        :loading="saving"
        @click="emit('publish')"
      />
      <Button
        v-else
        :label="t('onboarding.courses.unpublish')"
        icon="pi pi-eye-slash"
        severity="warn"
        outlined
        class="w-full"
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

<style lang="scss" scoped>
// Local width util (full-Bootstrap .w-100 is absent from the grid-only bundle).
.w-full {
  width: 100%;
}

// .gap-2 is a dead full-Bootstrap class → gap via token here.
.publish-card__status {
  gap: $space-2;
}
</style>
