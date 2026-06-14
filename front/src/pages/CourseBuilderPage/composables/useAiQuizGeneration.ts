/**
 * AI quiz generation polling composable — S3.8.
 * POST generate-questions → polls quiz until ai_generation_status = 'completed'.
 */
import { ref, onUnmounted } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useI18n } from 'vue-i18n'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { Quiz } from '@/entities/quiz'

export function useAiQuizGeneration() {
  const { t } = useI18n()
  const toast = useToast()

  const generating = ref(false)
  const pollInterval = ref<ReturnType<typeof setInterval> | null>(null)

  function stopPolling(): void {
    if (pollInterval.value !== null) {
      clearInterval(pollInterval.value)
      pollInterval.value = null
    }
  }

  async function generateQuestions(lessonId: number, quizId: number): Promise<Quiz | null> {
    generating.value = true
    stopPolling()

    try {
      await onboardingAdminApi.generateQuizQuestions(lessonId)
      toast.add({
        severity: 'info',
        summary: t('onboarding.builder.quiz.generating'),
        detail: t('onboarding.builder.quiz.generatingHint'),
        life: 5000,
      })
    } catch {
      generating.value = false
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
      return null
    }

    return new Promise((resolve) => {
      pollInterval.value = setInterval(async () => {
        try {
          const quiz = await onboardingAdminApi.getQuiz(quizId)
          if (quiz.ai_generation_status === 'completed') {
            stopPolling()
            generating.value = false
            resolve(quiz)
          } else if (quiz.ai_generation_status === 'failed') {
            stopPolling()
            generating.value = false
            toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
            resolve(null)
          }
        } catch {
          stopPolling()
          generating.value = false
          resolve(null)
        }
      }, 3000)
    })
  }

  onUnmounted(() => {
    stopPolling()
  })

  return {
    generating,
    generateQuestions,
    stopPolling,
  }
}
