<template>
  <div class="module-panel mb-3">
    <!-- Module header -->
    <div class="module-panel__header d-flex align-items-center gap-2">
      <Button
        :icon="collapsed ? 'pi pi-chevron-right' : 'pi pi-chevron-down'"
        size="small"
        text
        severity="secondary"
        @click="collapsed = !collapsed"
      />
      <span class="module-panel__title flex-grow-1 fw-medium" @click="collapsed = !collapsed">
        {{ module.sort_order }}. {{ module.title }}
      </span>
      <Button
        icon="pi pi-chevron-up"
        size="small"
        text
        severity="secondary"
        :disabled="isFirst"
        @click="emit('move', 'up')"
      />
      <Button
        icon="pi pi-chevron-down"
        size="small"
        text
        severity="secondary"
        :disabled="isLast"
        @click="emit('move', 'down')"
      />
      <Button
        icon="pi pi-pencil"
        size="small"
        text
        severity="secondary"
        :title="t('common.edit')"
        @click="emit('edit')"
      />
      <Button
        icon="pi pi-trash"
        size="small"
        text
        severity="danger"
        :title="t('common.delete')"
        @click="emit('delete')"
      />
    </div>

    <!-- Module body (lessons + add button) -->
    <div v-if="!collapsed" class="module-panel__body">
      <!-- Lessons list -->
      <LessonRow
        v-for="(lesson, idx) in (module.lessons ?? [])"
        :key="lesson.id"
        :lesson="lesson"
        :is-first="idx === 0"
        :is-last="idx === (module.lessons?.length ?? 0) - 1"
        class="mb-1"
        @edit="emit('editLesson', module.id, lesson)"
        @delete="emit('deleteLesson', module.id, lesson)"
        @move="(dir) => emit('moveLesson', module.id, idx, dir)"
      />

      <!-- Empty lessons -->
      <div v-if="(module.lessons?.length ?? 0) === 0" class="module-panel__empty-lessons">
        <span class="text-muted">{{ t('onboarding.builder.noLessons') }}</span>
      </div>

      <!-- Add lesson dropdown -->
      <div class="mt-2">
        <SplitButton
          :label="t('onboarding.builder.addLesson')"
          size="small"
          severity="secondary"
          outlined
          :model="addLessonMenuItems"
          @click="emit('addLesson', module.id, 'text')"
        />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import SplitButton from 'primevue/splitbutton'
import LessonRow from './LessonRow.vue'
import type { CourseModule, Lesson, LessonKind } from '@/entities/course'

const props = defineProps<{
  module: CourseModule
  isFirst: boolean
  isLast: boolean
}>()

const emit = defineEmits<{
  edit: []
  delete: []
  move: [direction: 'up' | 'down']
  addLesson: [moduleId: number, kind: LessonKind]
  editLesson: [moduleId: number, lesson: Lesson]
  deleteLesson: [moduleId: number, lesson: Lesson]
  moveLesson: [moduleId: number, lessonIndex: number, direction: 'up' | 'down']
}>()

const { t } = useI18n()

const collapsed = ref(false)

const addLessonMenuItems = computed(() => [
  { label: t('onboarding.builder.lessonKinds.text'), icon: 'pi pi-align-left', command: () => emit('addLesson', props.module.id, 'text') },
  { label: t('onboarding.builder.lessonKinds.video'), icon: 'pi pi-video', command: () => emit('addLesson', props.module.id, 'video') },
  { label: t('onboarding.builder.lessonKinds.pdf'), icon: 'pi pi-file-pdf', command: () => emit('addLesson', props.module.id, 'pdf') },
  { label: t('onboarding.builder.lessonKinds.quiz'), icon: 'pi pi-question-circle', command: () => emit('addLesson', props.module.id, 'quiz') },
])
</script>

<style lang="scss" scoped>
.module-panel {
  border: 1px solid var(--p-surface-200);
  border-radius: $radius-md;

  &__header {
    background: var(--p-surface-50);
    padding: $space-2 $space-3;
    cursor: pointer;
    user-select: none;
    border-radius: $radius-md $radius-md 0 0;
  }

  &__title {
    font-size: $font-size-sm;
    color: var(--p-surface-700);
  }

  &__body {
    padding: $space-3;
    background: var(--p-card-background);
  }

  &__empty-lessons {
    padding: $space-3 0;
    font-size: $font-size-sm;
    text-align: center;
  }
}
</style>
