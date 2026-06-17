/**
 * Deal activities composable — timeline for a deal.
 */
import { ref, computed } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { activityApi } from '@/api/activity'
import type { ActivityDto } from '@/entities/activity'

export function useDealActivities(dealId: () => number) {
  const PAGE_SIZE = 20

  const resource = useAsyncResource<ActivityDto[]>(() => [])
  const currentPage = ref(1)
  const totalRecords = ref(0)

  const activities = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  const hasMore = computed(
    () => activities.value.length < totalRecords.value,
  )

  const overdueCount = computed(
    () => activities.value.filter((a) => a.is_overdue && !a.is_closed).length,
  )

  const completeMutation = useMutation<ActivityDto>()
  const reopenMutation = useMutation<ActivityDto>()
  const deleteMutation = useMutation()

  async function load(page = 1) {
    currentPage.value = page
    await resource.run(async () => {
      const res = await activityApi.getActivities({
        target_type: 'deal',
        target_id: dealId(),
        per_page: PAGE_SIZE,
        page,
        sort: 'pinned_first',
      })
      totalRecords.value = res.meta.total
      if (page === 1) {
        return res.data
      } else {
        return [...resource.data.value, ...res.data]
      }
    })
  }

  async function loadMore() {
    if (!hasMore.value || loading.value) return
    await load(currentPage.value + 1)
  }

  async function complete(id: number): Promise<ActivityDto> {
    const updated = await completeMutation.run(() => activityApi.completeActivity(id))
    resource.data.value = resource.data.value.map((a) => (a.id === id ? updated : a))
    return updated
  }

  async function reopen(id: number): Promise<ActivityDto> {
    const updated = await reopenMutation.run(() => activityApi.reopenActivity(id))
    resource.data.value = resource.data.value.map((a) => (a.id === id ? updated : a))
    return updated
  }

  async function remove(id: number): Promise<void> {
    await deleteMutation.run(() => activityApi.deleteActivity(id))
    resource.data.value = resource.data.value.filter((a) => a.id !== id)
    totalRecords.value = Math.max(0, totalRecords.value - 1)
  }

  async function pin(id: number, isPinned: boolean): Promise<void> {
    const updated = await activityApi.updateActivity(id, { is_pinned: isPinned })
    resource.data.value = resource.data.value.map((a) => (a.id === id ? updated : a))
  }

  function updateLocal(updated: ActivityDto) {
    resource.data.value = resource.data.value.map((a) => (a.id === updated.id ? updated : a))
  }

  function addLocal(activity: ActivityDto) {
    resource.data.value = [activity, ...resource.data.value]
    totalRecords.value += 1
  }

  return {
    activities,
    loading,
    hasMore,
    overdueCount,
    load,
    loadMore,
    complete,
    reopen,
    remove,
    pin,
    updateLocal,
    addLocal,
  }
}
