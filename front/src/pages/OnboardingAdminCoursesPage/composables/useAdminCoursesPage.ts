/**
 * Admin Courses page composable — S3.8.
 * Orchestrates: load, filters, create, publish/unpublish, delete.
 */
import { ref, reactive } from 'vue'
import { useRouter } from 'vue-router'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n } from 'vue-i18n'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { Course, CourseListParams, CourseCreatePayload, CompletionPolicy } from '@/entities/course'

export function useAdminCoursesPage() {
  const { t } = useI18n()
  const router = useRouter()
  const toast = useToast()
  const confirm = useConfirm()

  // ─── Data ──────────────────────────────────────────────────────────────────
  const courses = ref<Course[]>([])
  const loading = ref(false)
  const totalRecords = ref(0)

  // ─── Filters ───────────────────────────────────────────────────────────────
  const filters = reactive<CourseListParams>({
    status: '',
    completion_policy: '',
    search: '',
    page: 1,
    per_page: 25,
  })

  // ─── Create dialog ─────────────────────────────────────────────────────────
  const showCreateDialog = ref(false)

  // ─── Load ──────────────────────────────────────────────────────────────────
  async function loadCourses(): Promise<void> {
    loading.value = true
    try {
      const result = await onboardingAdminApi.getCourses({
        ...filters,
        status: filters.status || undefined,
        completion_policy: filters.completion_policy || undefined,
        search: filters.search || undefined,
      })
      courses.value = result.data
      totalRecords.value = result.meta.total
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    } finally {
      loading.value = false
    }
  }

  function onPage(event: { page: number; rows: number }): void {
    filters.page = event.page + 1
    filters.per_page = event.rows
    void loadCourses()
  }

  function applyFilters(): void {
    filters.page = 1
    void loadCourses()
  }

  function resetFilters(): void {
    filters.status = ''
    filters.completion_policy = ''
    filters.search = ''
    filters.page = 1
    void loadCourses()
  }

  // ─── Create ────────────────────────────────────────────────────────────────
  async function onCreate(payload: CourseCreatePayload): Promise<void> {
    const course = await onboardingAdminApi.createCourse(payload)
    showCreateDialog.value = false
    await router.push({ name: 'CourseBuilder', params: { id: course.id } })
  }

  // ─── Publish / Unpublish ───────────────────────────────────────────────────
  async function onPublish(course: Course): Promise<void> {
    try {
      const updated = await onboardingAdminApi.publishCourse(course.id)
      patchLocal(updated)
      toast.add({ severity: 'success', summary: t('onboarding.courses.publish'), life: 3000 })
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    }
  }

  function onUnpublish(course: Course): void {
    confirm.require({
      message: t('onboarding.courses.unpublishConfirm'),
      header: t('onboarding.courses.unpublish'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        try {
          const updated = await onboardingAdminApi.unpublishCourse(course.id)
          patchLocal(updated)
          toast.add({ severity: 'info', summary: t('onboarding.courses.unpublish'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        }
      },
    })
  }

  // ─── Delete ────────────────────────────────────────────────────────────────
  function onDelete(course: Course): void {
    confirm.require({
      message: t('onboarding.courses.deleteConfirm'),
      header: t('common.delete'),
      icon: 'pi pi-trash',
      accept: async () => {
        try {
          await onboardingAdminApi.deleteCourse(course.id)
          courses.value = courses.value.filter((c) => c.id !== course.id)
          totalRecords.value = Math.max(0, totalRecords.value - 1)
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        }
      },
    })
  }

  // ─── Helpers ───────────────────────────────────────────────────────────────
  function patchLocal(updated: Course): void {
    const idx = courses.value.findIndex((c) => c.id === updated.id)
    if (idx !== -1) courses.value[idx] = updated
  }

  function policyLabel(policy: CompletionPolicy): string {
    return t(`onboarding.courses.policy.${policy}`)
  }

  return {
    courses,
    loading,
    totalRecords,
    filters,
    showCreateDialog,
    loadCourses,
    onPage,
    applyFilters,
    resetFilters,
    onCreate,
    onPublish,
    onUnpublish,
    onDelete,
    policyLabel,
  }
}
