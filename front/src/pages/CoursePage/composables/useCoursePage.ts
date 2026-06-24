import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { useToast } from 'primevue/usetoast'
import { useI18n } from 'vue-i18n'
import { onboardingStudentApi } from '@/api/onboardingStudent'
import { useUserStore } from '@/stores/user'
import type { CourseAssignment } from '@/entities/assignment'
import type { CourseModule, Lesson } from '@/entities/course'
import type { Certificate } from '@/entities/certificate'

export function useCoursePage(assignmentId: number) {
  const toast = useToast()
  const { t } = useI18n()
  const userStore = useUserStore()

  const assignment = useAsyncResource<CourseAssignment | null>(null)
  const modules = ref<CourseModule[]>([])
  const currentLessonId = ref<number | null>(null)
  const completingLesson = useMutation<void>()

  // Completion dialog
  const showCompleteDialog = ref(false)
  const certificate = ref<Certificate | null>(null)
  let certPollInterval: ReturnType<typeof setInterval> | null = null

  const isOwner = computed(() => {
    const a = assignment.data.value
    if (!a) return false
    return a.user_id === userStore.getUser?.id
  })

  const currentLesson = computed<Lesson | null>(() => {
    if (!currentLessonId.value) return null
    for (const mod of modules.value) {
      const found = (mod.lessons ?? []).find((l) => l.id === currentLessonId.value)
      if (found) return found
    }
    return null
  })

  // Flat ordered list of lessons
  const allLessons = computed<Lesson[]>(() =>
    modules.value.flatMap((m) => m.lessons ?? []),
  )

  const currentLessonIndex = computed(() =>
    allLessons.value.findIndex((l) => l.id === currentLessonId.value),
  )

  const hasPrev = computed(() => currentLessonIndex.value > 0)
  const hasNext = computed(() => currentLessonIndex.value < allLessons.value.length - 1)

  // Lesson completion tracking (local set for UI)
  const completedLessonIds = ref<Set<number>>(new Set())

  function isLessonCompleted(lessonId: number): boolean {
    return completedLessonIds.value.has(lessonId)
  }

  async function load(): Promise<void> {
    await assignment.run(async () => {
      const a = await onboardingStudentApi.getAssignment(assignmentId)
      // Hydrate completed lesson ids from backend response (BUG-LESSON-TREE)
      if (Array.isArray(a.completed_lesson_ids) && a.completed_lesson_ids.length > 0) {
        completedLessonIds.value = new Set(a.completed_lesson_ids)
      }
      // modules with lessons are eager-loaded in AssignmentDetailResource (backend loads course.modules.lessons)
      const courseModules: CourseModule[] = a.course?.modules ?? []
      modules.value = courseModules
      // set first incomplete lesson as current
      const firstLesson = courseModules.flatMap((m) => m.lessons ?? [])[0]
      if (firstLesson) {
        currentLessonId.value = firstLesson.id
      }
      return a
    })
  }

  function navigateToLesson(lessonId: number): void {
    currentLessonId.value = lessonId
  }

  function navigatePrev(): void {
    const idx = currentLessonIndex.value
    if (idx > 0) {
      const prev = allLessons.value[idx - 1]
      if (prev) currentLessonId.value = prev.id
    }
  }

  function navigateNext(): void {
    const idx = currentLessonIndex.value
    if (idx < allLessons.value.length - 1) {
      const next = allLessons.value[idx + 1]
      if (next) currentLessonId.value = next.id
    }
  }

  async function completeCurrentLesson(timeSpentSeconds?: number): Promise<void> {
    if (!currentLessonId.value) return
    const lessonId = currentLessonId.value
    const isQuiz = currentLesson.value?.kind === 'quiz'
    await completingLesson.run(
      async () => {
        // Quiz lessons are completed via quiz-attempt submit on the backend;
        // calling /complete on them returns 403 — skip the call entirely.
        if (!isQuiz) {
          await onboardingStudentApi.completeLesson(lessonId, timeSpentSeconds)
        }
        completedLessonIds.value = new Set([...completedLessonIds.value, lessonId])
        if (!isQuiz) {
          toast.add({ severity: 'success', summary: t('onboarding.coursePage.lessonDone'), life: 3000 })
        }
        // Refresh assignment to check if course completed
        const updated = await onboardingStudentApi.getAssignment(assignmentId)
        assignment.data.value = updated
        if (updated.status === 'completed') {
          showCompleteDialog.value = true
          startCertificatePoll()
        }
      },
      {
        onError: () => {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        },
      },
    )
  }

  function startCertificatePoll(): void {
    let elapsed = 0
    const maxMs = 30000
    const intervalMs = 3000
    certPollInterval = setInterval(async () => {
      elapsed += intervalMs
      try {
        const cert = await onboardingStudentApi.getCertificate(assignmentId)
        certificate.value = cert
        stopCertificatePoll()
      } catch {
        // 404 — keep polling
      }
      if (elapsed >= maxMs) {
        stopCertificatePoll()
      }
    }, intervalMs)
  }

  function stopCertificatePoll(): void {
    if (certPollInterval !== null) {
      clearInterval(certPollInterval)
      certPollInterval = null
    }
  }

  async function downloadCertificate(): Promise<void> {
    const a = assignment.data.value
    if (!a) return
    try {
      const blob = await onboardingStudentApi.downloadCertificate(a.id)
      const url = URL.createObjectURL(blob)
      const link = document.createElement('a')
      link.href = url
      link.download = `certificate-${a.id}.pdf`
      link.click()
      URL.revokeObjectURL(url)
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    }
  }

  function cleanup(): void {
    stopCertificatePoll()
  }

  return {
    assignment: assignment.data,
    loading: assignment.loading,
    error: assignment.error,
    modules,
    currentLesson,
    currentLessonId,
    allLessons,
    hasPrev,
    hasNext,
    isOwner,
    completedLessonIds,
    isLessonCompleted,
    completingLesson: completingLesson.isPending,
    showCompleteDialog,
    certificate,
    load,
    navigateToLesson,
    navigatePrev,
    navigateNext,
    completeCurrentLesson,
    downloadCertificate,
    cleanup,
  }
}
