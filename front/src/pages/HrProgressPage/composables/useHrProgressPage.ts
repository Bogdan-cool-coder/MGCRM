/**
 * HR Progress page composable — S3.8.
 */
import { ref, reactive } from 'vue'
import { useToast } from 'primevue/usetoast'
import { useI18n } from 'vue-i18n'
import { onboardingAdminApi, type HrProgressSummary, type HrProgressRow } from '@/api/onboardingAdmin'

export function useHrProgressPage() {
  const { t } = useI18n()
  const toast = useToast()

  const summary = ref<HrProgressSummary | null>(null)
  const loadingSummary = ref(false)

  const progressRows = ref<HrProgressRow[]>([])
  const loadingRows = ref(false)
  const totalRows = ref(0)

  const filters = reactive({
    user_id: null as number | null,
    course_id: null as number | null,
    status: '',
    page: 1,
    per_page: 25,
  })

  async function loadSummary(): Promise<void> {
    loadingSummary.value = true
    try {
      summary.value = await onboardingAdminApi.getHrProgressSummary()
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    } finally {
      loadingSummary.value = false
    }
  }

  async function loadProgress(): Promise<void> {
    loadingRows.value = true
    try {
      const result = await onboardingAdminApi.getHrProgress({
        user_id: filters.user_id ?? undefined,
        course_id: filters.course_id ?? undefined,
        status: filters.status || undefined,
        page: filters.page,
        per_page: filters.per_page,
      })
      progressRows.value = result.data
      totalRows.value = result.meta.total
    } catch {
      toast.add({ severity: 'error', summary: t('common.error'), life: 4000 })
    } finally {
      loadingRows.value = false
    }
  }

  function onPage(event: { page: number; rows: number }): void {
    filters.page = event.page + 1
    filters.per_page = event.rows
    void loadProgress()
  }

  function applyFilters(): void {
    filters.page = 1
    void loadProgress()
  }

  function resetFilters(): void {
    filters.user_id = null
    filters.course_id = null
    filters.status = ''
    filters.page = 1
    void loadProgress()
  }

  return {
    summary,
    loadingSummary,
    progressRows,
    loadingRows,
    totalRows,
    filters,
    loadSummary,
    loadProgress,
    onPage,
    applyFilters,
    resetFilters,
  }
}
