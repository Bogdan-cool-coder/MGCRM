/**
 * Assignments page composable — S3.8.
 */
import { ref, reactive } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useI18n } from 'vue-i18n'
import { onboardingAdminApi } from '@/api/onboardingAdmin'
import type { CourseAssignment, AssignmentListParams } from '@/entities/assignment'

export function useAssignmentsPage() {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const assignments = ref<CourseAssignment[]>([])
  const loading = ref(false)
  const totalRecords = ref(0)

  const filters = reactive<AssignmentListParams>({
    course_id: null,
    user_id: null,
    status: '',
    page: 1,
    per_page: 25,
  })

  const showAssignDrawer = ref(false)

  // Deadline edit dialog
  const deadlineDialogVisible = ref(false)
  const editingAssignment = ref<CourseAssignment | null>(null)

  async function loadAssignments(): Promise<void> {
    loading.value = true
    try {
      const result = await onboardingAdminApi.getAssignments({
        ...filters,
        course_id: filters.course_id ?? undefined,
        user_id: filters.user_id ?? undefined,
        status: filters.status || undefined,
      })
      assignments.value = result.data
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
    void loadAssignments()
  }

  function applyFilters(): void {
    filters.page = 1
    void loadAssignments()
  }

  function resetFilters(): void {
    filters.course_id = null
    filters.user_id = null
    filters.status = ''
    filters.page = 1
    void loadAssignments()
  }

  function openEditDeadline(assignment: CourseAssignment): void {
    editingAssignment.value = assignment
    deadlineDialogVisible.value = true
  }

  async function saveDeadline(dueDate: string | null): Promise<void> {
    if (!editingAssignment.value) return
    try {
      const updated = await onboardingAdminApi.patchAssignment(editingAssignment.value.id, { due_date: dueDate })
      patchLocal(updated)
      deadlineDialogVisible.value = false
      toast.add({ severity: 'success', summary: t('common.saved'), life: 3000 })
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    }
  }

  function archiveAssignment(assignment: CourseAssignment): void {
    confirm.require({
      message: t('onboarding.assignments.archiveConfirm'),
      header: t('onboarding.assignments.archive'),
      icon: 'pi pi-box',
      accept: async () => {
        try {
          const updated = await onboardingAdminApi.archiveAssignment(assignment.id)
          patchLocal(updated)
          toast.add({ severity: 'success', summary: t('common.saved'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        }
      },
    })
  }

  function deleteAssignmentConfirm(assignment: CourseAssignment): void {
    confirm.require({
      message: t('onboarding.assignments.deleteConfirm'),
      header: t('common.delete'),
      icon: 'pi pi-trash',
      accept: async () => {
        try {
          await onboardingAdminApi.deleteAssignment(assignment.id)
          assignments.value = assignments.value.filter((a) => a.id !== assignment.id)
          totalRecords.value = Math.max(0, totalRecords.value - 1)
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 3000 })
        } catch {
          toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
        }
      },
    })
  }

  function patchLocal(updated: CourseAssignment): void {
    const idx = assignments.value.findIndex((a) => a.id === updated.id)
    if (idx !== -1) assignments.value[idx] = updated
  }

  return {
    assignments,
    loading,
    totalRecords,
    filters,
    showAssignDrawer,
    deadlineDialogVisible,
    editingAssignment,
    loadAssignments,
    onPage,
    applyFilters,
    resetFilters,
    openEditDeadline,
    saveDeadline,
    archiveAssignment,
    deleteAssignmentConfirm,
  }
}
