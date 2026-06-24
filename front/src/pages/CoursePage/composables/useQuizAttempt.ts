import { ref, computed, watch, onUnmounted } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useI18n } from 'vue-i18n'
import { onboardingStudentApi } from '@/api/onboardingStudent'
import type { Quiz, QuizAttempt, QuizAttemptResult } from '@/entities/quiz'

export function useQuizAttempt(lessonId: number, onPassed?: () => void) {
  const toast = useToast()
  const { t } = useI18n()

  const quiz = ref<Quiz | null>(null)
  const attempt = ref<QuizAttempt | null>(null)
  const result = ref<QuizAttemptResult | null>(null)

  const phase = ref<'before' | 'in_progress' | 'result'>('before')
  const isLoading = ref(false)
  const isSubmitting = ref(false)

  // answers: question_id → selected_option_ids[]
  const answers = ref<Map<number, number[]>>(new Map())

  // timer
  const timeLeft = ref(0)
  let timerInterval: ReturnType<typeof setInterval> | null = null

  const hasTimer = computed(() => (quiz.value?.time_limit_minutes ?? 0) > 0)

  function startTimer(minutes: number) {
    timeLeft.value = minutes * 60
    timerInterval = setInterval(() => {
      if (timeLeft.value > 0) {
        timeLeft.value--
      }
    }, 1000)
  }

  function stopTimer() {
    if (timerInterval !== null) {
      clearInterval(timerInterval)
      timerInterval = null
    }
  }

  // Watch for time expiry → auto-submit (O-3)
  watch(timeLeft, (v) => {
    if (v === 0 && phase.value === 'in_progress' && hasTimer.value) {
      handleTimeExpired()
    }
  })

  async function handleTimeExpired() {
    stopTimer()
    toast.add({
      severity: 'warn',
      summary: t('onboarding.coursePage.quiz.timer'),
      detail: t('onboarding.coursePage.quiz.submit'),
      life: 2500,
    })
    setTimeout(() => {
      void submitQuiz()
    }, 2000)
  }

  async function loadQuiz(): Promise<void> {
    isLoading.value = true
    try {
      quiz.value = await onboardingStudentApi.getStudentQuiz(lessonId)
    } finally {
      isLoading.value = false
    }
  }

  async function startQuiz(): Promise<void> {
    if (!quiz.value) return
    isLoading.value = true
    try {
      attempt.value = await onboardingStudentApi.startQuiz(lessonId)
      answers.value = new Map()
      phase.value = 'in_progress'
      if (hasTimer.value && quiz.value.time_limit_minutes > 0) {
        startTimer(quiz.value.time_limit_minutes)
      }
    } finally {
      isLoading.value = false
    }
  }

  async function submitQuiz(): Promise<void> {
    if (!attempt.value) return
    stopTimer()
    isSubmitting.value = true
    try {
      const payload = {
        answers: quiz.value?.questions.map((q) => ({
          question_id: q.id,
          selected_option_ids: answers.value.get(q.id) ?? [],
        })) ?? [],
      }
      result.value = await onboardingStudentApi.submitQuizAttempt(attempt.value.id, payload)
      phase.value = 'result'
      if (result.value.passed) {
        onPassed?.()
      }
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    } finally {
      isSubmitting.value = false
    }
  }

  function resetAttempt() {
    attempt.value = null
    result.value = null
    answers.value = new Map()
    phase.value = 'before'
    stopTimer()
  }

  function toggleOption(questionId: number, optionId: number, kind: string) {
    const current = answers.value.get(questionId) ?? []
    if (kind === 'single_choice') {
      answers.value.set(questionId, [optionId])
    } else {
      if (current.includes(optionId)) {
        answers.value.set(questionId, current.filter((id) => id !== optionId))
      } else {
        answers.value.set(questionId, [...current, optionId])
      }
    }
    // trigger reactivity
    answers.value = new Map(answers.value)
  }

  onUnmounted(() => {
    stopTimer()
  })

  return {
    quiz,
    attempt,
    result,
    phase,
    isLoading,
    isSubmitting,
    answers,
    timeLeft,
    hasTimer,
    loadQuiz,
    startQuiz,
    submitQuiz,
    resetAttempt,
    toggleOption,
  }
}
