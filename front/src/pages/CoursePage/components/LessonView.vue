<template>
  <div class="lesson-view">
    <LessonViewText
      v-if="lesson.kind === 'text'"
      :content="textMarkdown"
      :completed="completed"
      :completing="completing"
      @complete="$emit('complete')"
    />

    <LessonViewVideo
      v-else-if="lesson.kind === 'video'"
      :video-url="videoUrl"
      :completed="completed"
      :completing="completing"
      @complete="$emit('complete')"
    />

    <LessonViewPdf
      v-else-if="lesson.kind === 'pdf'"
      :pdf-url="pdfPath"
      :completed="completed"
      :completing="completing"
      @complete="$emit('complete')"
    />

    <LessonViewQuiz
      v-else-if="lesson.kind === 'quiz'"
      :lesson-id="lesson.id"
      :completion-policy="completionPolicy"
      @next="$emit('next')"
      @quiz-passed="$emit('complete')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import LessonViewText from './LessonViewText.vue'
import LessonViewVideo from './LessonViewVideo.vue'
import LessonViewPdf from './LessonViewPdf.vue'
import LessonViewQuiz from './LessonViewQuiz.vue'
import type { Lesson, CompletionPolicy } from '@/entities/course'

const props = defineProps<{
  lesson: Lesson
  completed?: boolean
  completing?: boolean
  completionPolicy?: CompletionPolicy
}>()

defineEmits<{
  complete: []
  next: []
}>()

// Extract typed content fields from the polymorphic content object
const textMarkdown = computed<string | null>(() => {
  const c = props.lesson.content as Record<string, unknown> | null
  return (c && 'markdown' in c ? (c.markdown as string) : null) ?? null
})

const videoUrl = computed<string | null>(() => {
  const c = props.lesson.content as Record<string, unknown> | null
  return (c && 'url' in c ? (c.url as string) : null) ?? null
})

const pdfPath = computed<string | null>(() => {
  const c = props.lesson.content as Record<string, unknown> | null
  return (c && 'path' in c ? (c.path as string) : null) ?? null
})
</script>
