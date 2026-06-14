<template>
  <div class="course-builder-page">
    <!-- Header -->
    <div class="course-builder-page__header d-flex align-items-center gap-3 px-4 py-3 border-bottom">
      <Button
        :label="t('onboarding.builder.back')"
        icon="pi pi-arrow-left"
        text
        severity="secondary"
        @click="$router.push({ name: 'OnboardingAdminCourses' })"
      />
      <span class="course-builder-page__title flex-grow-1">
        {{ course?.title ?? '...' }}
      </span>
      <CourseStatusTag v-if="course" :is-published="course.is_published" />
      <Button
        v-if="course && !course.is_published"
        :label="t('onboarding.courses.publish')"
        icon="pi pi-send"
        severity="success"
        :loading="saving"
        @click="publishCourse"
      />
      <Button
        v-if="course && course.is_published"
        :label="t('onboarding.courses.unpublish')"
        icon="pi pi-eye-slash"
        severity="warn"
        outlined
        :loading="saving"
        @click="unpublishCourse"
      />
      <Button
        :label="t('onboarding.builder.save')"
        icon="pi pi-save"
        :loading="saving"
        @click="doSave"
      />
    </div>

    <!-- Error -->
    <div v-if="error" class="p-4">
      <Message severity="error" :closable="false">{{ error }}</Message>
      <Button
        :label="t('onboarding.builder.back')"
        icon="pi pi-arrow-left"
        class="mt-3"
        @click="$router.push({ name: 'OnboardingAdminCourses' })"
      />
    </div>

    <!-- Loading -->
    <div v-else-if="loading" class="row g-4 p-4">
      <div class="col-lg-8">
        <Skeleton height="200px" class="mb-4" />
        <Skeleton height="300px" />
      </div>
      <div class="col-lg-4">
        <Skeleton height="120px" class="mb-3" />
        <Skeleton height="200px" class="mb-3" />
        <Skeleton height="120px" />
      </div>
    </div>

    <!-- Content -->
    <div v-else-if="course" class="row g-4 p-4">
      <!-- Left column -->
      <div class="col-lg-8">
        <CourseSettingsCard :course="course" @change="onSettingsChange" />
        <CourseStructureCard
          :modules="modules"
          :loading-modules="loadingModules"
          @add-module="openCreateModule"
          @edit-module="openEditModule"
          @delete-module="deleteModule"
          @move-module="moveModule"
          @add-lesson="openCreateLesson"
          @edit-lesson="openEditLesson"
          @delete-lesson="handleDeleteLesson"
          @move-lesson="handleMoveLesson"
        />
      </div>

      <!-- Right column (sticky) -->
      <div class="col-lg-4">
        <div class="course-builder-page__sticky-col">
          <CoursePublishCard
            :course="course"
            :saving="saving"
            @publish="publishCourse"
            @unpublish="unpublishCourse"
          />
          <CourseAssignmentsCard
            :assignments="courseAssignments"
            :loading="loadingAssignments"
            @assign="assignDrawerVisible = true"
          />
          <CourseMetaCard :course="course" />
        </div>
      </div>
    </div>

    <!-- Module edit dialog -->
    <ModuleEditDialog
      v-model:visible="moduleDialogVisible"
      :editing-module="editingModule"
      @save="saveModule"
    />

    <!-- Lesson edit drawer -->
    <LessonEditDrawer
      v-model:visible="lessonDrawerVisible"
      :lesson="editingLesson"
      :default-kind="newLessonKind"
      @save="handleSaveLesson"
      @upload="handleUploadPdf"
      @publish="handlePublishLesson"
      @unpublish="handleUnpublishLesson"
    />

    <!-- Assign drawer -->
    <AssignCourseDrawer
      v-if="course"
      v-model:visible="assignDrawerVisible"
      :course-id="course.id"
      :course-name="course.title"
      :deadline-days="course.deadline_days"
      @assigned="loadCourseAssignments"
    />

    <ConfirmDialog />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Message from 'primevue/message'
import ConfirmDialog from 'primevue/confirmdialog'
import CourseStatusTag from '@/components/shared/CourseStatusTag.vue'
import { useCourseBuilder } from './composables/useCourseBuilder'
import { useCourseModules } from './composables/useCourseModules'
import { useCourseLessons } from './composables/useCourseLessons'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { Lesson, CoursePatchPayload } from '@/entities/course'
import type { CourseAssignment } from '@/entities/assignment'
// Components
import CourseSettingsCard from './components/CourseSettingsCard.vue'
import CourseStructureCard from './components/CourseStructureCard.vue'
import CoursePublishCard from './components/CoursePublishCard.vue'
import CourseAssignmentsCard from './components/CourseAssignmentsCard.vue'
import CourseMetaCard from './components/CourseMetaCard.vue'
import ModuleEditDialog from './components/ModuleEditDialog.vue'
import LessonEditDrawer from './components/LessonEditDrawer.vue'
import AssignCourseDrawer from './components/AssignCourseDrawer.vue'

const { t } = useI18n()
const route = useRoute()
const courseId = Number(route.params.id)

// ─── Composables ──────────────────────────────────────────────────────────────
const {
  course,
  loading,
  saving,
  error,
  loadCourse,
  saveCourse,
  publishCourse,
  unpublishCourse,
} = useCourseBuilder(courseId)

const {
  modules,
  loadingModules,
  moduleDialogVisible,
  editingModule,
  loadModules,
  openCreateModule,
  openEditModule,
  saveModule,
  deleteModule,
  moveModule,
} = useCourseModules(courseId)

const {
  lessonDrawerVisible,
  editingLesson,
  newLessonKind,
  openCreateLesson,
  openEditLesson,
  saveLesson,
  uploadPdf,
  deleteLesson,
  moveLesson,
  publishLesson,
  unpublishLesson,
} = useCourseLessons()

// ─── Course assignments (right-panel) ─────────────────────────────────────────
const courseAssignments = ref<CourseAssignment[]>([])
const loadingAssignments = ref(false)
const assignDrawerVisible = ref(false)

async function loadCourseAssignments(): Promise<void> {
  loadingAssignments.value = true
  try {
    courseAssignments.value = await onboardingAdminApi.getCourseAssignments(courseId)
  } catch {
    // non-critical
  } finally {
    loadingAssignments.value = false
  }
}

// ─── Deferred settings change ─────────────────────────────────────────────────
let pendingPatch: CoursePatchPayload = {}

function onSettingsChange(patch: CoursePatchPayload): void {
  pendingPatch = { ...pendingPatch, ...patch }
}

async function doSave(): Promise<void> {
  await saveCourse(pendingPatch)
  pendingPatch = {}
}

// ─── Lesson handlers ──────────────────────────────────────────────────────────
async function handleSaveLesson(payload: unknown): Promise<void> {
  const updated = await saveLesson(modules.value, payload as Parameters<typeof saveLesson>[1])
  modules.value = updated
}

async function handleUploadPdf(file: File): Promise<void> {
  if (!editingLesson.value) return
  await uploadPdf(editingLesson.value.id, file)
}

async function handleDeleteLesson(moduleId: number, lesson: Lesson): Promise<void> {
  modules.value = await deleteLesson(moduleId, lesson, modules.value)
}

async function handleMoveLesson(moduleId: number, lessonIndex: number, direction: 'up' | 'down'): Promise<void> {
  modules.value = await moveLesson(moduleId, lessonIndex, direction, modules.value)
}

async function handlePublishLesson(): Promise<void> {
  if (!editingLesson.value) return
  const moduleId = modules.value.find((m) =>
    (m.lessons ?? []).some((l) => l.id === editingLesson.value!.id),
  )?.id
  if (!moduleId) return
  modules.value = await publishLesson(moduleId, editingLesson.value, modules.value)
}

async function handleUnpublishLesson(): Promise<void> {
  if (!editingLesson.value) return
  const moduleId = modules.value.find((m) =>
    (m.lessons ?? []).some((l) => l.id === editingLesson.value!.id),
  )?.id
  if (!moduleId) return
  modules.value = await unpublishLesson(moduleId, editingLesson.value, modules.value)
}

// ─── Init ─────────────────────────────────────────────────────────────────────
onMounted(async () => {
  await Promise.all([loadCourse(), loadModules(), loadCourseAssignments()])
})
</script>

<style lang="scss" scoped>
.course-builder-page {
  min-height: 100%;

  &__header {
    background: var(--p-card-background);
    position: sticky;
    top: 0;
    z-index: 10;
  }

  &__title {
    font-size: $font-size-lg;
    font-weight: $font-weight-semibold;
    color: var(--p-surface-800);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__sticky-col {
    position: sticky;
    top: 64px;
  }
}
</style>
