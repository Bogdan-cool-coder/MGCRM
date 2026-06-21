<template>
  <div class="lesson-view-quiz">
    <!-- Loading quiz structure -->
    <div v-if="quizAttempt.isLoading.value" class="text-center py-5">
      <ProgressSpinner />
    </div>

    <!-- No quiz configured -->
    <Message v-else-if="!quizAttempt.quiz.value" severity="warn" :closable="false">
      Квиз не настроен для этого урока.
    </Message>

    <template v-else>
      <!-- Quiz header -->
      <div class="quiz-header mb-3">
        <h3 class="quiz-header__title">{{ quizAttempt.quiz.value.title }}</h3>
        <p class="quiz-header__meta">
          {{ t('onboarding.coursePage.quiz.passingScore') }}: {{ quizAttempt.quiz.value.pass_score_pct }}%
        </p>
        <p v-if="attemptNumber > 0" class="quiz-header__attempt">
          {{ t('onboarding.coursePage.quiz.attempt', { n: attemptNumber }) }}
        </p>
      </div>

      <!-- PHASE: Before start -->
      <div v-if="quizAttempt.phase.value === 'before'" class="text-center py-4">
        <Button
          :label="t('onboarding.coursePage.quiz.start')"
          icon="pi pi-play"
          size="large"
          :loading="quizAttempt.isLoading.value"
          @click="quizAttempt.startQuiz()"
        />
      </div>

      <!-- PHASE: In progress -->
      <div v-else-if="quizAttempt.phase.value === 'in_progress'">
        <!-- Timer -->
        <div v-if="quizAttempt.hasTimer.value" class="mb-3">
          <QuizTimer :seconds-left="quizAttempt.timeLeft.value" />
        </div>

        <!-- Questions -->
        <div class="mb-3">
          <div
            v-for="(question, idx) in quizAttempt.quiz.value.questions"
            :key="question.id"
          >
            <div class="quiz-question-header mb-1">
              <span class="quiz-question-header__num">
                {{ t('onboarding.coursePage.quiz.question', { current: idx + 1, total: quizAttempt.quiz.value.questions.length }) }}
              </span>
            </div>
            <QuizQuestion
              :question="question"
              :selected-ids="quizAttempt.answers.value.get(question.id) ?? []"
              @toggle="quizAttempt.toggleOption"
            />
          </div>
        </div>

        <!-- Submit -->
        <Button
          :label="t('onboarding.coursePage.quiz.submit')"
          icon="pi pi-send"
          :loading="quizAttempt.isSubmitting.value"
          :disabled="quizAttempt.timeLeft.value === 0 && quizAttempt.hasTimer.value"
          @click="quizAttempt.submitQuiz()"
        />
      </div>

      <!-- PHASE: Result -->
      <div v-else-if="quizAttempt.phase.value === 'result' && quizAttempt.result.value">
        <QuizResult
          :result="quizAttempt.result.value"
          :passing-score="quizAttempt.quiz.value.pass_score_pct"
          :can-proceed="completionPolicy === 'informational'"
          @retry="handleRetry"
          @next="$emit('next')"
        />
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import ProgressSpinner from 'primevue/progressspinner'
import QuizTimer from './QuizTimer.vue'
import QuizQuestion from './QuizQuestion.vue'
import QuizResult from './QuizResult.vue'
import { useQuizAttempt } from '../composables/useQuizAttempt'
import type { CompletionPolicy } from '@/entities/course'

const props = defineProps<{
  lessonId: number
  completionPolicy?: CompletionPolicy
}>()

const { t } = useI18n()
const emit = defineEmits<{
  next: []
  quizPassed: []
}>()

const quizAttempt = useQuizAttempt(props.lessonId, () => emit('quizPassed'))
const attemptNumber = ref(0)

onMounted(async () => {
  await quizAttempt.loadQuiz()
})

async function handleRetry() {
  attemptNumber.value++
  quizAttempt.resetAttempt()
  await quizAttempt.startQuiz()
}
</script>

<style lang="scss" scoped>
.quiz-header {
  &__title {
    font-size: $font-size-lg;
    font-weight: 700;
    margin: 0 0 0.25rem;
  }

  &__meta,
  &__attempt {
    font-size: $font-size-sm;
    color: var(--p-surface-500);
    margin: 0;
  }
}

.quiz-question-header {
  &__num {
    font-size: $font-size-xs; // snap from 13px (0.8125rem)
    font-weight: 600;
    color: var(--p-surface-500);
    text-transform: uppercase;
    letter-spacing: 0.04em;
  }
}
</style>
