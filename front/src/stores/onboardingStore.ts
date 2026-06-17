/**
 * Onboarding Pinia store — client state only.
 * Holds overdue assignment count for sidebar badge on «Мои курсы».
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { onboardingStudentApi } from '@/api/onboardingStudent'

export const useOnboardingStore = defineStore('onboarding', () => {
  const overdueCount = ref<number>(0)

  async function fetchOverdueCount(): Promise<void> {
    try {
      const assignments = await onboardingStudentApi.getMyCourses()
      overdueCount.value = assignments.filter((a) => a.status === 'overdue').length
    } catch {
      // non-critical
    }
  }

  function resetOverdueCount(): void {
    overdueCount.value = 0
  }

  return {
    overdueCount,
    fetchOverdueCount,
    resetOverdueCount,
  }
})
