<template>
  <div class="quiz-result">
    <!-- Score summary -->
    <Message
      :severity="result.passed ? 'success' : 'error'"
      :closable="false"
      class="mb-4"
    >
      <span v-if="result.passed">
        {{ t('onboarding.coursePage.quiz.result.passed', { score: Math.round(result.score_pct) }) }}
      </span>
      <span v-else>
        {{
          t('onboarding.coursePage.quiz.result.failed', {
            score: Math.round(result.score_pct),
            min: passingScore,
          })
        }}
      </span>
    </Message>

    <!-- Review section -->
    <h4 class="quiz-result__review-title mb-3">
      {{ t('onboarding.coursePage.quiz.result.review') }}
    </h4>

    <div v-for="answer in result.answers" :key="answer.question_id" class="quiz-result__answer mb-3">
      <div class="d-flex align-items-start gap-2 mb-1">
        <Tag
          :severity="answer.is_correct ? 'success' : 'danger'"
          :icon="answer.is_correct ? 'pi pi-check' : 'pi pi-times'"
          :value="answer.is_correct ? t('onboarding.coursePage.quiz.result.correct') : t('onboarding.coursePage.quiz.result.incorrect')"
        />
        <p class="quiz-result__question-text mb-0">{{ answer.question_text }}</p>
      </div>

      <div v-if="!answer.is_correct" class="quiz-result__correct-answer">
        <span class="quiz-result__correct-label">{{ t('onboarding.coursePage.quiz.result.answer') }}: </span>
        <span>{{ correctAnswerText(answer) }}</span>
      </div>

      <div v-if="answer.explanation" class="quiz-result__explanation mt-1">
        <span class="quiz-result__explanation-label">{{ t('onboarding.coursePage.quiz.result.explanation') }}: </span>
        <span>{{ answer.explanation }}</span>
      </div>
    </div>

    <!-- Actions -->
    <div class="d-flex gap-2 mt-4 flex-wrap">
      <Button
        v-if="!result.passed"
        :label="t('onboarding.coursePage.quiz.result.retry')"
        severity="secondary"
        icon="pi pi-refresh"
        @click="$emit('retry')"
      />
      <Button
        v-if="result.passed || canProceed"
        :label="t('onboarding.coursePage.quiz.result.nextLesson')"
        icon="pi pi-chevron-right"
        icon-pos="right"
        @click="$emit('next')"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import Message from 'primevue/message'
import type { QuizAttemptResult, QuizAnswerResult } from '@/entities/quiz'

defineProps<{
  result: QuizAttemptResult
  passingScore: number
  canProceed?: boolean
}>()

defineEmits<{
  retry: []
  next: []
}>()

const { t } = useI18n()

function correctAnswerText(answer: QuizAnswerResult): string {
  // We don't have option texts in the result, only IDs
  // Display IDs as a fallback — in practice backend should return texts
  return (answer.correct_option_ids ?? []).join(', ')
}
</script>

<style lang="scss" scoped>
.quiz-result {
  &__review-title {
    font-size: $font-size-md;
    font-weight: $font-weight-bold;
  }

  &__answer {
    padding: $space-3;
    background: var(--p-surface-50);
    border-radius: $radius-md;
    border: 1px solid var(--p-surface-200);
  }

  &__question-text {
    font-size: $font-size-sm; // snap from 15px
    font-weight: $font-weight-semibold;
  }

  &__correct-answer,
  &__explanation {
    font-size: $font-size-sm;
    color: var(--p-surface-600);
    padding-left: $space-1;
  }

  &__correct-label,
  &__explanation-label {
    font-weight: 600;
  }
}
</style>
