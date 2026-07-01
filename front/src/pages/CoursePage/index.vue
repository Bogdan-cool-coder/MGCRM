<template>
  <div class="course-page">
    <!-- Loading skeleton -->
    <div v-if="loading" class="course-page__skeleton d-flex gap-3 p-4">
      <Skeleton width="280px" height="100vh" />
      <div class="flex-1">
        <Skeleton height="40px" class="mb-3" />
        <Skeleton height="300px" />
      </div>
    </div>

    <!-- Error -->
    <div v-else-if="error" class="p-4">
      <Message severity="error" :closable="false">
        {{ t('common.loadError') }}
      </Message>
      <Button
        :label="t('onboarding.coursePage.back')"
        icon="pi pi-arrow-left"
        text
        class="mt-2"
        @click="$router.push({ name: 'MyCourses' })"
      />
    </div>

    <!-- Main layout -->
    <template v-else-if="assignment">
      <!-- Admin view-only warning -->
      <Message v-if="!isOwner" severity="warn" :closable="false" class="mx-4 mt-3 mb-0">
        {{ t('onboarding.coursePage.aiTutor.adminViewOnly') }}
      </Message>

      <div class="course-page__layout">
        <!-- Sidebar -->
        <CourseSidebar
          :modules="modules"
          :current-lesson-id="currentLessonId"
          :completed-ids="completedLessonIds"
          :progress-pct="assignment.progress_pct"
          @select="navigateToLesson"
        />

        <!-- Content area -->
        <div class="course-page__content">
          <!-- Course header -->
          <div class="course-page__header">
            <div class="d-flex align-items-center gap-2 mb-2">
              <Button
                :label="t('onboarding.coursePage.back')"
                icon="pi pi-arrow-left"
                text
                size="small"
                @click="$router.push({ name: 'MyCourses' })"
              />
              <AssignmentStatusTag :status="assignment.status" />
            </div>
            <h1 class="course-page__course-title">{{ assignment.course.title }}</h1>
            <ProgressBar :value="assignment.progress_pct" style="height: 8px; max-width: 400px" />
          </div>

          <!-- Lesson header -->
          <div v-if="currentLesson" class="course-page__lesson-header">
            <div class="d-flex align-items-center gap-2">
              <i :class="kindIcon(currentLesson.kind)" class="text-primary" />
              <h2 class="course-page__lesson-title">{{ currentLesson.title }}</h2>
            </div>
          </div>

          <!-- Lesson view -->
          <div v-if="currentLesson" class="course-page__lesson-body">
            <LessonView
              :lesson="currentLesson"
              :completed="isLessonCompleted(currentLesson.id)"
              :completing="completingLesson"
              :completion-policy="assignment.course.completion_policy"
              @complete="handleCompleteLesson"
              @next="navigateNext"
            />
          </div>

          <div v-else class="p-4 text-muted text-center">
            <i class="pi pi-book-open course-page__empty-icon" />
            <p class="mt-2">Выберите урок из списка слева</p>
          </div>

          <!-- AI tutor button (owner only, non-quiz lessons) -->
          <div
            v-if="currentLesson && currentLesson.kind !== 'quiz' && isOwner"
            class="course-page__ai-btn-wrap"
          >
            <Button
              :label="t('onboarding.coursePage.aiTutor.btnLabel')"
              icon="pi pi-sparkles"
              severity="secondary"
              @click="showAiTutor = true"
            />
          </div>

          <!-- Prev / Next navigation -->
          <div class="course-page__nav">
            <Button
              v-if="hasPrev"
              :label="t('onboarding.coursePage.prev')"
              icon="pi pi-chevron-left"
              severity="secondary"
              outlined
              @click="navigatePrev"
            />
            <span v-else />
            <Button
              v-if="hasNext"
              :label="t('onboarding.coursePage.next')"
              icon="pi pi-chevron-right"
              icon-pos="right"
              :disabled="nextDisabled"
              @click="navigateNext"
            />
            <!-- Last lesson: show complete button.
                 Disabled until ALL lessons (not just the current one) are completed.
                 For quiz lessons: @quiz-passed → handleCompleteLesson populates
                 completedLessonIds; canFinishCourse becomes true automatically. -->
            <Button
              v-else-if="currentLesson && isOwner && assignment?.status !== 'completed'"
              :label="t('onboarding.coursePage.completeBtn')"
              icon="pi pi-check-circle"
              severity="success"
              :loading="completingLesson"
              :disabled="!canFinishCourse"
              @click="handleCompleteLesson"
            />
          </div>
        </div>
      </div>

      <!-- AI Tutor Drawer -->
      <AiTutorDrawer
        v-if="currentLesson && isOwner"
        v-model:visible="showAiTutor"
        :lesson-id="currentLesson.id"
        :lesson-title="currentLesson.title"
      />

      <!-- Course Complete Dialog -->
      <CourseCompleteDialog
        v-model:visible="showCompleteDialog"
        :course-title="assignment.course.title"
        :certificate="certificate"
        @download-cert="downloadCertificate"
        @back="$router.push({ name: 'MyCourses' })"
      />
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, computed, onMounted, onUnmounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import ProgressBar from 'primevue/progressbar'
import AssignmentStatusTag from '@/components/shared/AssignmentStatusTag.vue'
import CourseSidebar from './components/CourseSidebar.vue'
import LessonView from './components/LessonView.vue'
import AiTutorDrawer from './components/AiTutorDrawer.vue'
import CourseCompleteDialog from './components/CourseCompleteDialog.vue'
import { useCoursePage } from './composables/useCoursePage'
import type { LessonKind } from '@/entities/course'

const route = useRoute()
const { t } = useI18n()
const assignmentId = Number(route.params.id)

const {
  assignment,
  loading,
  error,
  modules,
  currentLesson,
  currentLessonId,
  allLessons,
  hasPrev,
  hasNext,
  isOwner,
  completedLessonIds,
  isLessonCompleted,
  completingLesson,
  showCompleteDialog,
  certificate,
  load,
  navigateToLesson,
  navigatePrev,
  navigateNext,
  completeCurrentLesson,
  downloadCertificate,
  cleanup,
} = useCoursePage(assignmentId)

const showAiTutor = ref(false)

// "Finish course" button: active when every lesson in the course is completed.
// Checking only the current lesson was the BUG-NEXT-LESSON-DEAD: on the last
// lesson a user could never click "Complete course" if any prior lesson was
// missing from completedLessonIds (e.g. skipped non-required lessons).
// For quiz lessons the set is populated via the @quiz-passed → handleCompleteLesson
// chain, so this computed stays in sync automatically.
const canFinishCourse = computed(() => {
  if (allLessons.value.length === 0) return false
  return allLessons.value.every((l) => isLessonCompleted(l.id))
})

// Next disabled if soft_gate and lesson not completed (non-quiz) or quiz not passed
const nextDisabled = computed(() => {
  if (!currentLesson.value) return false
  if (assignment.value?.course.completion_policy === 'informational') return false
  // For quiz: handled inside LessonViewQuiz via result
  if (currentLesson.value.kind === 'quiz') return false
  return !isLessonCompleted(currentLesson.value.id)
})

const KIND_ICONS: Record<LessonKind, string> = {
  text: 'pi pi-align-left',
  video: 'pi pi-video',
  pdf: 'pi pi-file-pdf',
  quiz: 'pi pi-question-circle',
}

function kindIcon(kind: LessonKind): string {
  return KIND_ICONS[kind] ?? 'pi pi-file'
}

async function handleCompleteLesson() {
  await completeCurrentLesson()
}

onMounted(async () => {
  await load()
})

onUnmounted(() => {
  cleanup()
})
</script>

<style lang="scss" scoped>
.course-page {
  height: 100%;

  &__layout {
    display: flex;
    min-height: 100vh;
  }

  &__content {
    flex: 1;
    display: flex;
    flex-direction: column;
    overflow-y: auto;
    padding: $space-6;
    min-width: 0;
  }

  &__header {
    margin-bottom: $space-6;
  }

  &__course-title {
    font-size: $font-size-icon-md;
    font-weight: $font-weight-bold;
    margin: $space-1 0 $space-3;
  }

  &__lesson-header {
    margin-bottom: $space-4;
    padding-bottom: $space-3;
    border-bottom: 1px solid var(--p-surface-200);
  }

  &__lesson-title {
    font-size: $font-size-lg;
    font-weight: $font-weight-semibold;
    margin: 0;
  }

  &__lesson-body {
    flex: 1;
    margin-bottom: $space-8;
  }

  &__ai-btn-wrap {
    margin-bottom: $space-6;
  }

  &__nav {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding-top: $space-4;
    border-top: 1px solid var(--p-surface-200);
    margin-top: auto;
  }

  &__empty-icon {
    font-size: $font-size-icon-lg;
    opacity: 0.4;
  }

  &__skeleton {
    .flex-1 {
      flex: 1;
    }
  }
}
</style>
