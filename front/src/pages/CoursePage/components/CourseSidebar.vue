<template>
  <aside class="course-sidebar">
    <div class="course-sidebar__progress mb-3">
      <span class="course-sidebar__progress-label">
        {{ t('onboarding.myCourses.progress', { n: progressPct }) }}
      </span>
      <ProgressBar :value="progressPct" style="height: 6px; margin-top: 0.25rem" />
    </div>

    <div
      v-for="mod in modules"
      :key="mod.id"
      class="course-sidebar__module mb-3"
    >
      <div class="course-sidebar__module-title">{{ mod.title }}</div>
      <ul class="course-sidebar__lessons list-unstyled mb-0">
        <li
          v-for="lesson in mod.lessons"
          :key="lesson.id"
          :class="[
            'course-sidebar__lesson',
            { 'course-sidebar__lesson--active': lesson.id === currentLessonId },
            { 'course-sidebar__lesson--completed': completedIds.has(lesson.id) },
          ]"
          @click="$emit('select', lesson.id)"
        >
          <i :class="['course-sidebar__lesson-status', lessonStatusIcon(lesson)]" />
          <i :class="['course-sidebar__lesson-kind', kindIcon(lesson.kind)]" />
          <span class="course-sidebar__lesson-name">{{ lesson.title }}</span>
        </li>
      </ul>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import ProgressBar from 'primevue/progressbar'
import type { CourseModule, Lesson, LessonKind } from '@/entities/course'

const props = defineProps<{
  modules: CourseModule[]
  currentLessonId: number | null
  completedIds: Set<number>
  progressPct: number
}>()

defineEmits<{
  select: [lessonId: number]
}>()

const { t } = useI18n()

function lessonStatusIcon(lesson: Lesson): string {
  if (props.completedIds.has(lesson.id)) return 'pi pi-check-circle text-success'
  if (lesson.id === props.currentLessonId) return 'pi pi-play text-primary'
  return 'pi pi-circle text-surface-400'
}

const KIND_ICONS: Record<LessonKind, string> = {
  text: 'pi pi-align-left',
  video: 'pi pi-video',
  pdf: 'pi pi-file-pdf',
  quiz: 'pi pi-question-circle',
}

function kindIcon(kind: LessonKind): string {
  return KIND_ICONS[kind] ?? 'pi pi-file'
}

</script>

<style lang="scss" scoped>
.course-sidebar {
  width: 280px;
  min-width: 240px;
  flex-shrink: 0;
  position: sticky;
  top: 0;
  height: 100vh;
  overflow-y: auto;
  padding: 1.25rem 1rem;
  background: var(--p-card-background);
  border-right: 1px solid var(--p-surface-200);

  &__progress-label {
    font-size: 0.75rem;
    color: var(--p-surface-500);
  }

  &__module-title {
    font-size: 0.6875rem;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    color: var(--p-surface-500);
    padding: 0.5rem 0.25rem 0.25rem;
  }

  &__lesson {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    padding: 0.4rem 0.5rem;
    border-radius: 6px;
    cursor: pointer;
    transition: background 0.15s;

    &:hover {
      background: var(--p-surface-100);
    }

    &--active {
      background: var(--p-primary-50);
      border-left: 3px solid var(--p-primary-color);
    }

    &--completed {
      opacity: 0.75;
    }
  }

  &__lesson-status {
    font-size: 0.875rem;
    flex-shrink: 0;
  }

  &__lesson-kind {
    font-size: 0.75rem;
    color: var(--p-surface-400);
    flex-shrink: 0;
  }

  &__lesson-name {
    font-size: 0.875rem;
    line-height: 1.3;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }
}
</style>
