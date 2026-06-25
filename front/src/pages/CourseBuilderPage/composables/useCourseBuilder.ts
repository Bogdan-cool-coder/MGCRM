/**
 * Course builder composable — loads/saves the course and handles publish/unpublish.
 */
import { ref } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n } from 'vue-i18n'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import { getApiErrorMessage } from '@/utils/errors'
import type { Course, CoursePatchPayload } from '@/entities/course'

export function useCourseBuilder(courseId: number) {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const course = ref<Course | null>(null)
  const loading = ref(false)
  const saving = ref(false)
  const error = ref('')

  async function loadCourse(): Promise<void> {
    loading.value = true
    error.value = ''
    try {
      course.value = await onboardingAdminApi.getCourse(courseId)
    } catch {
      error.value = t('common.loadError')
    } finally {
      loading.value = false
    }
  }

  async function saveCourse(payload: CoursePatchPayload): Promise<void> {
    if (!course.value) return
    saving.value = true
    try {
      course.value = await onboardingAdminApi.patchCourse(courseId, payload)
      toast.add({ severity: 'success', summary: t('common.saved'), life: 3000 })
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    } finally {
      saving.value = false
    }
  }

  async function publishCourse(): Promise<void> {
    if (!course.value) return
    saving.value = true
    try {
      course.value = await onboardingAdminApi.publishCourse(courseId)
      toast.add({ severity: 'success', summary: t('onboarding.courses.publish'), life: 3000 })
    } catch (err) {
      // Surface 422 validation message (e.g. "Add at least one published lesson…")
      const detail = getApiErrorMessage(err, t('common.error'))
      toast.add({
        severity: 'error',
        summary: t('onboarding.courses.publishError'),
        detail,
        life: 6000,
      })
    } finally {
      saving.value = false
    }
  }

  function unpublishCourse(): void {
    confirm.require({
      message: t('onboarding.courses.unpublishConfirm'),
      header: t('onboarding.courses.unpublish'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        if (!course.value) return
        saving.value = true
        try {
          course.value = await onboardingAdminApi.unpublishCourse(courseId)
          toast.add({ severity: 'info', summary: t('onboarding.courses.unpublish'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        } finally {
          saving.value = false
        }
      },
    })
  }

  return {
    course,
    loading,
    saving,
    error,
    loadCourse,
    saveCourse,
    publishCourse,
    unpublishCourse,
  }
}
