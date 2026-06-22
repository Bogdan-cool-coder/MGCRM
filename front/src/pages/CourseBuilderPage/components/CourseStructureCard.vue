<template>
  <Card class="course-structure-card mb-4">
    <template #title>{{ t('onboarding.builder.structureTitle') }}</template>
    <template #content>
      <template v-if="loadingModules">
        <Skeleton height="60px" class="mb-2" />
        <Skeleton height="60px" class="mb-2" />
        <Skeleton height="60px" />
      </template>

      <template v-else>
        <ModulePanel
          v-for="(mod, idx) in modules"
          :key="mod.id"
          :module="mod"
          :is-first="idx === 0"
          :is-last="idx === modules.length - 1"
          @edit="emit('editModule', mod)"
          @delete="emit('deleteModule', mod)"
          @move="(dir) => emit('moveModule', idx, dir)"
          @add-lesson="(moduleId, kind) => emit('addLesson', moduleId, kind)"
          @edit-lesson="(moduleId, lesson) => emit('editLesson', moduleId, lesson)"
          @delete-lesson="(moduleId, lesson) => emit('deleteLesson', moduleId, lesson)"
          @move-lesson="(moduleId, lessonIdx, dir) => emit('moveLesson', moduleId, lessonIdx, dir)"
        />

        <div v-if="modules.length === 0" class="course-structure-card__empty">
          <i class="pi pi-list course-structure-card__empty-icon" />
          <p class="text-muted">{{ t('onboarding.builder.noModules') }}</p>
        </div>

        <Button
          :label="t('onboarding.builder.addModule')"
          severity="secondary"
          outlined
          icon="pi pi-plus"
          size="small"
          class="mt-3"
          @click="emit('addModule')"
        />
      </template>
    </template>
  </Card>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import ModulePanel from './ModulePanel.vue'
import type { CourseModule, Lesson, LessonKind } from '@/entities/course'

defineProps<{
  modules: CourseModule[]
  loadingModules: boolean
}>()

const emit = defineEmits<{
  addModule: []
  editModule: [mod: CourseModule]
  deleteModule: [mod: CourseModule]
  moveModule: [index: number, direction: 'up' | 'down']
  addLesson: [moduleId: number, kind: LessonKind]
  editLesson: [moduleId: number, lesson: Lesson]
  deleteLesson: [moduleId: number, lesson: Lesson]
  moveLesson: [moduleId: number, lessonIndex: number, direction: 'up' | 'down']
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.course-structure-card {
  :deep(.p-card-title) {
    font-size: $font-size-md;
    font-weight: $font-weight-semibold;
  }

  &__empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: $space-2;
    padding: $space-6;
    text-align: center;
  }

  &__empty-icon {
    font-size: $font-size-icon-lg;
    color: var(--p-surface-400);
  }
}
</style>
