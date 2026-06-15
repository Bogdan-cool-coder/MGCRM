/**
 * useAutomationRuns — server-state composable for the automation runs journal.
 *
 * Loads automations list for filter dropdown and runs with pagination.
 * Server-state via useAsyncResource; mutations via useMutation.
 * Pattern: api → composable → page component (ARCHITECTURE §1).
 */

import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { automationsApi, automationRunsApi } from '@/api/automation'
import type {
  AutomationDto,
  AutomationRunDto,
  RunStatus,
  ActionKind,
  AutomationRunListParams,
} from '@/entities/automation'

const PER_PAGE = 50

export function useAutomationRuns() {
  // ─── Automations list (for filter dropdown) ────────────────────────────────
  const automationsResource = useAsyncResource<AutomationDto[]>(() => [])

  async function fetchAutomations(): Promise<void> {
    await automationsResource.run(() => automationsApi.list())
  }

  // ─── Filters ───────────────────────────────────────────────────────────────
  const filterAutomationId = ref<number | null>(null)
  const filterStatus = ref<RunStatus | null>(null)
  const filterActionKind = ref<ActionKind | null>(null)
  const filterDateFrom = ref<Date | null>(null)
  const filterDateTo = ref<Date | null>(null)
  // DatePicker range-mode emits [Date, Date|null]
  const filterDateRange = ref<Date[] | null>(null)

  // ─── Runs state ────────────────────────────────────────────────────────────
  const runs = ref<AutomationRunDto[]>([])
  const loading = ref(false)
  const loadError = ref<string | null>(null)
  const hasMore = ref(false)
  const currentPage = ref(1)

  function buildParams(page: number): AutomationRunListParams {
    const params: AutomationRunListParams = { per_page: PER_PAGE, page }
    if (filterAutomationId.value) params.automation_id = filterAutomationId.value
    if (filterStatus.value) params.status = filterStatus.value
    if (filterActionKind.value) params.action_kind = filterActionKind.value
    const from = filterDateRange.value?.[0]
    const to = filterDateRange.value?.[1]
    if (from) params.from = formatDateParam(from)
    if (to) params.to = formatDateParam(to)
    return params
  }

  async function fetchRuns(): Promise<void> {
    runs.value = []
    currentPage.value = 1
    loading.value = true
    loadError.value = null
    try {
      const batch = await automationRunsApi.list(buildParams(1))
      runs.value = batch
      hasMore.value = batch.length === PER_PAGE
    } catch (e: unknown) {
      loadError.value = extractMsg(e)
    } finally {
      loading.value = false
    }
  }

  async function loadMore(): Promise<void> {
    const nextPage = currentPage.value + 1
    currentPage.value = nextPage
    loading.value = true
    loadError.value = null
    try {
      const batch = await automationRunsApi.list(buildParams(nextPage))
      runs.value = [...runs.value, ...batch]
      hasMore.value = batch.length === PER_PAGE
    } catch (e: unknown) {
      // Revert page on failure so the user can retry
      currentPage.value = nextPage - 1
      loadError.value = extractMsg(e)
    } finally {
      loading.value = false
    }
  }

  // ─── Selected automation (for dry-run) ────────────────────────────────────
  const selectedAutomation = computed<AutomationDto | null>(() => {
    if (!filterAutomationId.value) return null
    return automationsResource.data.value.find((a) => a.id === filterAutomationId.value) ?? null
  })

  // ─── Helpers ───────────────────────────────────────────────────────────────
  function formatDateParam(d: Date): string {
    const y = d.getFullYear()
    const m = String(d.getMonth() + 1).padStart(2, '0')
    const day = String(d.getDate()).padStart(2, '0')
    return `${y}-${m}-${day}`
  }

  return {
    // Automations (filter dropdown)
    automations: automationsResource.data,
    automationsLoading: automationsResource.loading,
    fetchAutomations,

    // Filters
    filterAutomationId,
    filterStatus,
    filterActionKind,
    filterDateRange,
    filterDateFrom,
    filterDateTo,

    // Runs
    runs,
    loading,
    loadError,
    hasMore,
    fetchRuns,
    loadMore,

    // Derived
    selectedAutomation,
  }
}

export type AutomationRunsComposable = ReturnType<typeof useAutomationRuns>

// ─── Shared helper (also used by DryRunDrawer) ────────────────────────────────
export function extractMsg(e: unknown): string {
  if (typeof e === 'object' && e !== null) {
    const err = e as Record<string, unknown>
    const resp = err.response as Record<string, unknown> | null
    const data = resp?.data as Record<string, unknown> | null
    if (data?.message && typeof data.message === 'string') return data.message
    if (typeof err.message === 'string') return err.message
  }
  return String(e)
}
