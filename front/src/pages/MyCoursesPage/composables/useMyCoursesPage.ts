import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { onboardingStudentApi } from '@/api/onboardingStudent'
import type { CourseAssignment, AssignmentStatus } from '@/entities/assignment'

export type TabKey = 'active' | 'completed' | 'overdue'

export function useMyCoursesPage() {
  const assignments = useAsyncResource<CourseAssignment[]>([])

  const activeTab = ref<TabKey>('active')

  async function load(): Promise<void> {
    await assignments.run(() => onboardingStudentApi.getMyCourses())
  }

  const activeCount = computed(
    () => assignments.data.value.filter((a) => a.status === 'pending' || a.status === 'in_progress').length,
  )
  const completedCount = computed(
    () => assignments.data.value.filter((a) => a.status === 'completed').length,
  )
  const overdueCount = computed(
    () => assignments.data.value.filter((a) => a.status === 'overdue').length,
  )

  // Total across ALL tabs — used to show the global empty state
  const allCount = computed(() => assignments.data.value.length)

  const filteredAssignments = computed<CourseAssignment[]>(() => {
    const all = assignments.data.value
    const tab = activeTab.value
    if (tab === 'active') {
      return all.filter((a: CourseAssignment) => {
        const s: AssignmentStatus = a.status
        return s === 'pending' || s === 'in_progress'
      })
    }
    if (tab === 'completed') {
      return all.filter((a: CourseAssignment) => a.status === 'completed')
    }
    return all.filter((a: CourseAssignment) => a.status === 'overdue')
  })

  return {
    loading: assignments.loading,
    error: assignments.error,
    activeTab,
    filteredAssignments,
    activeCount,
    completedCount,
    overdueCount,
    allCount,
    load,
  }
}
