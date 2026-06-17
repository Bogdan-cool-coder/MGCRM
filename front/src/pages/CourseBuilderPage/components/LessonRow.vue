<template>
  <div class="lesson-row d-flex align-items-center gap-2 py-2">
    <!-- Kind icon -->
    <i :class="['lesson-row__kind-icon', kindIcon]" />

    <!-- Sort order + title -->
    <span class="lesson-row__title flex-grow-1">
      {{ lesson.sort_order }}. {{ lesson.title }}
    </span>

    <!-- Published badge -->
    <span v-if="lesson.is_published" class="lesson-row__pub-badge">
      <i class="pi pi-eye" />
    </span>

    <!-- Reorder buttons -->
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

    <!-- Edit -->
    <Button
      icon="pi pi-pencil"
      size="small"
      text
      severity="secondary"
      @click="emit('edit')"
    />

    <!-- Delete -->
    <Button
      icon="pi pi-trash"
      size="small"
      text
      severity="danger"
      @click="emit('delete')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import type { Lesson } from '@/entities/course'

const props = defineProps<{
  lesson: Lesson
  isFirst: boolean
  isLast: boolean
}>()

const emit = defineEmits<{
  edit: []
  delete: []
  move: [direction: 'up' | 'down']
}>()

const KIND_ICONS: Record<string, string> = {
  text: 'pi pi-align-left',
  video: 'pi pi-video',
  pdf: 'pi pi-file-pdf',
  quiz: 'pi pi-question-circle',
}

const kindIcon = computed(() => KIND_ICONS[props.lesson.kind] ?? 'pi pi-file')
</script>

<style lang="scss" scoped>
.lesson-row {
  border-radius: $radius-md;
  padding-left: $space-2;
  padding-right: $space-2;
  background: var(--p-card-background);
  border: 1px solid var(--p-surface-200);

  &:hover {
    background: var(--p-surface-50);
  }

  &__kind-icon {
    font-size: $font-size-sm;
    color: var(--p-surface-400);
    flex-shrink: 0;
    width: 16px;
    text-align: center;
  }

  &__title {
    font-size: $font-size-sm;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
  }

  &__pub-badge {
    font-size: $font-size-xs;
    color: var(--p-green-500);
    flex-shrink: 0;
  }
}
</style>
