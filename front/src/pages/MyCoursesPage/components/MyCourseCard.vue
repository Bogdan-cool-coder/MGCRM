<template>
  <Card class="my-course-card">
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
          <i class="pi pi-book my-course-card__cover-icon" />
          <div v-if="assignment.status === 'completed'" class="my-course-card__cover-check">
            <i class="pi pi-check-circle" />
          </div>
        </div>
      </div>
    </template>

    <template #content>
      <h3 class="my-course-card__title">{{ assignment.course.title }}</h3>

      <div class="my-course-card__meta d-flex align-items-center mb-2">
        <AssignmentStatusTag :status="assignment.status" />
        <span v-if="deadlineText" :class="['my-course-card__deadline', { 'my-course-card__deadline--overdue': isOverdue }]">
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
        class="w-full"
        icon="pi pi-verified"
        @click="$router.push({ name: 'MyOnboardingCertificates' })"
      />
      <Button
        v-else
        :label="t('onboarding.myCourses.continue')"
        class="w-full"
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
// Local width util (full-Bootstrap .w-100 is absent from the grid-only bundle).
.w-full {
  width: 100%;
}

.my-course-card {
  // .h-100 is a dead full-Bootstrap class → equal-height cards via 100% here.
  height: 100%;
  // PrimeVue Card body/content don't stretch by default, so the footer button
  // floated at different heights across a row (audit L1). Make body a flex column
  // and let content grow → footer pins to the bottom, buttons align across cards.
  display: flex;
  flex-direction: column;

  :deep(.p-card-body) {
    flex: 1;
    display: flex;
    flex-direction: column;
  }

  :deep(.p-card-content) {
    flex: 1;
  }

  &__meta {
    gap: $space-2;
  }

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
    font-size: $font-size-icon-lg;
    color: var(--p-surface-400);
  }

  &__cover-check {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: rgba(0, 0, 0, 0.35);
    display: flex;
    align-items: center;
    justify-content: center;

    i {
      font-size: $font-size-icon-lg;
      color: var(--p-green-400);
    }
  }

  &__title {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
    margin: 0 0 $space-2 0;
    line-height: 1.4;
    // Clamp to 2 lines so cards in a row keep a consistent header height.
    display: -webkit-box;
    -webkit-line-clamp: 2;
    -webkit-box-orient: vertical;
    overflow: hidden;
  }

  &__deadline {
    font-size: $font-size-xs; // snap from 13px
    color: var(--p-surface-500);

    // .text-danger is a dead full-Bootstrap class → overdue tint via token here.
    &--overdue {
      color: var(--p-red-500);
    }
  }

  &__progress-label {
    font-size: $font-size-xs;
    color: var(--p-surface-500);
  }

  :deep(.my-course-card__progress--overdue .p-progressbar-value) {
    background: var(--p-red-400);
  }
}
</style>
