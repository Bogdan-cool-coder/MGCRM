<template>
  <Card class="my-course-card h-100">
    <!-- Cover -->
    <template #header>
      <div class="my-course-card__cover">
        <img
          v-if="assignment.course.cover_image_path"
          :src="assignment.course.cover_image_path"
          :alt="assignment.course.title"
          class="my-course-card__cover-img"
        />
        <div v-else class="my-course-card__cover-placeholder">
          <i class="pi pi-book-open my-course-card__cover-icon" />
          <div v-if="assignment.status === 'completed'" class="my-course-card__cover-check">
            <i class="pi pi-check-circle" />
          </div>
        </div>
      </div>
    </template>

    <template #content>
      <h3 class="my-course-card__title">{{ assignment.course.title }}</h3>

      <div class="d-flex align-items-center gap-2 mb-2">
        <AssignmentStatusTag :status="assignment.status" />
        <span v-if="deadlineText" :class="['my-course-card__deadline', { 'text-danger': isOverdue }]">
          · {{ deadlineText }}
        </span>
      </div>

      <div class="my-course-card__progress-wrap mb-1">
        <ProgressBar
          :value="assignment.progress_pct"
          :class="{ 'my-course-card__progress--overdue': assignment.status === 'overdue' }"
          style="height: 6px"
        />
      </div>
      <span class="my-course-card__progress-label">
        {{ t('onboarding.myCourses.progress', { n: assignment.progress_pct }) }}
      </span>
    </template>

    <template #footer>
      <Button
        v-if="assignment.status === 'completed'"
        :label="t('onboarding.myCourses.viewCertificate')"
        severity="secondary"
        class="w-100"
        icon="pi pi-award"
        @click="$router.push({ name: 'MyOnboardingCertificates' })"
      />
      <Button
        v-else
        :label="t('onboarding.myCourses.continue')"
        class="w-100"
        icon="pi pi-play"
        icon-pos="right"
        @click="$router.push({ name: 'CoursePlayer', params: { id: assignment.id } })"
      />
    </template>
  </Card>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import ProgressBar from 'primevue/progressbar'
import AssignmentStatusTag from '@/components/shared/AssignmentStatusTag.vue'
import type { CourseAssignment } from '@/entities/assignment'

const props = defineProps<{
  assignment: CourseAssignment
}>()

const { t } = useI18n()

const isOverdue = computed(() => props.assignment.status === 'overdue')

const deadlineText = computed<string | null>(() => {
  if (!props.assignment.due_date) return null
  const d = new Date(props.assignment.due_date)
  const label = isOverdue.value
    ? t('onboarding.myCourses.overdueFrom')
    : t('onboarding.myCourses.deadline')
  return `${label}: ${d.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' })}`
})
</script>

<style lang="scss" scoped>
.my-course-card {
  &__cover {
    position: relative;
    width: 100%;
    aspect-ratio: 16 / 9;
    background: var(--p-surface-100);
    overflow: hidden;
    border-radius: var(--p-card-border-radius, 8px) var(--p-card-border-radius, 8px) 0 0;
  }

  &__cover-img {
    width: 100%;
    height: 100%;
    object-fit: cover;
  }

  &__cover-placeholder {
    width: 100%;
    height: 100%;
    display: flex;
    align-items: center;
    justify-content: center;
  }

  &__cover-icon {
    font-size: 3rem;
    color: var(--p-surface-400);
  }

  &__cover-check {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;

    i {
      font-size: 3rem;
      color: var(--p-green-400);
    }
  }

  &__title {
    font-size: 1rem;
    font-weight: 600;
    margin: 0 0 0.5rem 0;
    line-height: 1.4;
  }

  &__deadline {
    font-size: 0.8125rem;
    color: var(--p-surface-500);
  }

  &__progress-label {
    font-size: 0.75rem;
    color: var(--p-surface-500);
  }

  :deep(.my-course-card__progress--overdue .p-progressbar-value) {
    background: var(--p-red-400);
  }
}
</style>
