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
      :external-url="pdfExternalUrl"
      :player-src="lesson.player_src ?? null"
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

// External (hosted) PDF URL, if the lesson was configured with content.url.
// Such a URL is safe to embed in the iframe directly (no Bearer needed).
// A disk-stored PDF (content.path) is served via the authenticated player_src
// streaming route instead — resolved to a blob inside LessonViewPdf.
const pdfExternalUrl = computed<string | null>(() => {
  const c = props.lesson.content as Record<string, unknown> | null
  return (c && 'url' in c ? (c.url as string) : null) ?? null
})
</script>
