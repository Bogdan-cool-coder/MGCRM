/**
 * useEntityFeed — adapts the DealFeed timeline for company/contact contexts.
 *
 * Company: GET /api/companies/{id}/feed
 * Contact: GET /api/contacts/{id}/feed
 *
 * Mirrors the interface of useDealFeed (groups, loading, hasMore, setSearch, etc.)
 * so it can be passed directly to <DealFeed> via the `feed` prop.
 */
import { ref, computed } from 'vue'
import { apiClient } from '@/api/client'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

// ─── Re-export the same FeedItem/FeedGroup types as useDealFeed ───────────────

export type FeedItemType =
  | 'field_change'
  | 'note'
  | 'task'
  | 'call'
  | 'meeting'
  | 'follow_up'
  | 'entity_created'

export interface FeedActor {
  id: number
  full_name: string | null
}

export interface FeedItem {
  id: string
  type: FeedItemType
  timestamp: string
  date: string
  actor: FeedActor | null
  activity?: ActivityDto
  fieldChanges?: Array<{ field: string; old_value: string | null; new_value: string | null }>
  isEntityCreated?: boolean
}

export interface FeedGroup {
  date: string
  items: FeedItem[]
  collapsed: boolean
}

// ─── Raw API shape ────────────────────────────────────────────────────────────

interface RawFeedActor {
  id: number
  full_name: string | null
}

interface RawFeedItem {
  id: string
  type: 'activity' | 'field_change'
  occurred_at: string
  actor: RawFeedActor | null
  payload: Record<string, unknown>
}

interface FeedApiResponse {
  data: RawFeedItem[]
  meta: { total: number; per_page: number; current_page: number }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function toDate(iso: string): string {
  return iso.slice(0, 10)
}

function normaliseItem(raw: RawFeedItem): FeedItem | null {
  const timestamp = raw.occurred_at
  const date = toDate(timestamp)
  const actor = raw.actor ?? null

  if (raw.type === 'activity') {
    const p = raw.payload
    const kind = (p['kind'] as ActivityKind) ?? 'note'
    const activity: ActivityDto = {
      id: (p['activity_id'] as number) ?? 0,
      kind,
      status: p['is_closed'] ? 'done' : 'new',
      priority: 'normal',
      title: (p['title'] as string) ?? '',
      body: (p['body'] as string | null) ?? null,
      result_text: null,
      due_at: (p['due_at'] as string | null) ?? null,
      is_closed: (p['is_closed'] as boolean) ?? false,
      is_pinned: false,
      is_overdue:
        !(p['is_closed'] as boolean) &&
        !!(p['due_at'] as string | null) &&
        new Date((p['due_at'] as string) ?? '') < new Date(),
      target_type: (p['target_type'] as 'deal' | 'company') ?? 'company',
      target_id: null,
      target_label: null,
      responsible: null,
      creator: actor ? { id: actor.id, full_name: actor.full_name ?? '', avatar_path: null } : null,
      is_first_time_meeting: false,
      ftm_decision_maker_attended: false,
      ftm_presentation_shown: false,
      ftm_report_url: null,
      meeting_report_json: null,
      department_id: null,
      created_at: timestamp,
      updated_at: timestamp,
    }
    return { id: raw.id, type: kind as FeedItemType, timestamp, date, actor, activity }
  }

  if (raw.type === 'field_change') {
    const p = raw.payload
    return {
      id: raw.id,
      type: 'field_change',
      timestamp,
      date,
      actor,
      fieldChanges: [
        {
          field: (p['field'] as string) ?? '',
          old_value: (p['old_value'] as string | null) ?? null,
          new_value: (p['new_value'] as string | null) ?? null,
        },
      ],
    }
  }

  return null
}


function groupByDateEntity(items: FeedItem[], existingGroups: FeedGroup[]): FeedGroup[] {
  const groupMap = new Map<string, FeedGroup>()
  for (const g of existingGroups) {
    groupMap.set(g.date, { ...g, items: [] })
  }
  for (const item of items) {
    const g = groupMap.get(item.date)
    if (g) {
      g.items.push(item)
    } else {
      groupMap.set(item.date, { date: item.date, items: [item], collapsed: false })
    }
  }
  // Sort asc (oldest first) — bottom-up feed layout
  return Array.from(groupMap.values()).sort((a, b) => a.date.localeCompare(b.date))
}

// ─── Composable ───────────────────────────────────────────────────────────────

export type EntityFeedType = 'company' | 'contact'

export function useEntityFeed(entityType: () => EntityFeedType, entityId: () => number) {
  const PER_PAGE = 30

  const allItems = ref<FeedItem[]>([])
  const loading = ref(false)
  const currentPage = ref(1)
  const total = ref(0)
  const groups = ref<FeedGroup[]>([])
  const searchQuery = ref('')
  const filterType = ref<FeedItemType | ''>('')

  const hasMore = computed(() => allItems.value.length < total.value)

  const completeMutation = useMutation<ActivityDto>()
  const reopenMutation = useMutation<ActivityDto>()
  const deleteMutation = useMutation()

  /** Open (non-closed) task/call/meeting/follow_up — shown above composer */
  const openTasks = computed((): ActivityDto[] => {
    const result: ActivityDto[] = []
    for (const item of allItems.value) {
      if (
        (item.type === 'task' || item.type === 'call' || item.type === 'meeting' || item.type === 'follow_up') &&
        item.activity &&
        !item.activity.is_closed
      ) {
        result.push(item.activity)
      }
    }
    return result.slice().sort((a, b) => {
      if (a.due_at && b.due_at) return a.due_at.localeCompare(b.due_at)
      if (a.due_at && !b.due_at) return -1
      if (!a.due_at && b.due_at) return 1
      return a.created_at.localeCompare(b.created_at)
    })
  })

  // ─── Filtered ───────────────────────────────────────────────────────────────

  const filteredItems = computed((): FeedItem[] => {
    // Exclude open tasks — they go to openTasks list above composer
    let items = allItems.value.filter((i) => {
      if (
        (i.type === 'task' || i.type === 'call' || i.type === 'meeting' || i.type === 'follow_up') &&
        i.activity &&
        !i.activity.is_closed
      ) {
        return false
      }
      return true
    })

    const q = searchQuery.value.trim().toLowerCase()
    const type = filterType.value

    if (type) {
      items = items.filter((i) => i.type === type)
    }

    if (q) {
      items = items.filter((i) => {
        const actorName = i.actor?.full_name?.toLowerCase() ?? ''
        const title = i.activity?.title?.toLowerCase() ?? ''
        const body = i.activity?.body?.toLowerCase() ?? ''
        const fields =
          i.fieldChanges
            ?.map((c) => `${c.field} ${c.old_value ?? ''} ${c.new_value ?? ''}`)
            .join(' ')
            .toLowerCase() ?? ''
        return actorName.includes(q) || title.includes(q) || body.includes(q) || fields.includes(q)
      })
    }

    return items
  })

  function recomputeGroups() {
    groups.value = groupByDateEntity(filteredItems.value, groups.value)
  }

  // ─── API load ────────────────────────────────────────────────────────────────

  function feedUrl(): string {
    const type = entityType()
    const id = entityId()
    if (type === 'company') return `/api/companies/${id}/feed`
    return `/api/contacts/${id}/feed`
  }

  async function fetchPage(page: number): Promise<void> {
    loading.value = true
    try {
      const res = await apiClient.get<FeedApiResponse>(feedUrl(), {
        params: { page, per_page: PER_PAGE },
      })
      const { data, meta } = res.data
      total.value = meta.total
      currentPage.value = meta.current_page

      const normalised: FeedItem[] = []
      for (const raw of data) {
        const item = normaliseItem(raw)
        if (item) normalised.push(item)
      }

      if (page === 1) {
        allItems.value = normalised
      } else {
        allItems.value = [...allItems.value, ...normalised]
      }
      recomputeGroups()
    } finally {
      loading.value = false
    }
  }

  async function load(): Promise<void> {
    await fetchPage(1)
  }

  async function loadMore(): Promise<void> {
    if (!hasMore.value || loading.value) return
    await fetchPage(currentPage.value + 1)
  }

  // ─── Client filter/search ─────────────────────────────────────────────────────

  function setSearch(q: string) {
    searchQuery.value = q
    recomputeGroups()
  }

  function setFilterType(t: FeedItemType | '') {
    filterType.value = t
    recomputeGroups()
  }

  function resetFilter() {
    searchQuery.value = ''
    filterType.value = ''
    recomputeGroups()
  }

  function applyFilter() {
    recomputeGroups()
  }

  // ─── Collapse / expand ────────────────────────────────────────────────────────

  function toggleGroup(date: string) {
    const idx = groups.value.findIndex((g) => g.date === date)
    if (idx !== -1) {
      const current = groups.value[idx]
      if (current) {
        groups.value[idx] = { date: current.date, items: current.items, collapsed: !current.collapsed }
      }
    }
  }

  function collapseAll() {
    groups.value = groups.value.map((g) => ({ ...g, collapsed: true }))
  }

  function expandAll() {
    groups.value = groups.value.map((g) => ({ ...g, collapsed: false }))
  }

  // ─── Local mutations ──────────────────────────────────────────────────────────

  function prependLocal(activity: ActivityDto) {
    const kind = activity.kind as ActivityKind
    const ts = activity.created_at
    const item: FeedItem = {
      id: `activity_${activity.id}`,
      type: kind as FeedItemType,
      timestamp: ts,
      date: toDate(ts),
      actor: activity.creator ? { id: activity.creator.id, full_name: activity.creator.full_name } : null,
      activity,
    }
    allItems.value = [item, ...allItems.value]
    total.value += 1
    recomputeGroups()
  }

  function updateActivityLocal(updated: ActivityDto) {
    allItems.value = allItems.value.map((item) => {
      if (item.activity?.id === updated.id) {
        return { ...item, activity: updated }
      }
      return item
    })
    recomputeGroups()
  }

  function removeActivityLocal(id: number) {
    allItems.value = allItems.value.filter((item) => item.activity?.id !== id)
    total.value = Math.max(0, total.value - 1)
    recomputeGroups()
  }

  async function completeActivity(id: number): Promise<ActivityDto> {
    const updated = await completeMutation.run(() => activityApi.completeActivity(id))
    updateActivityLocal(updated)
    return updated
  }

  async function reopenActivity(id: number): Promise<ActivityDto> {
    const updated = await reopenMutation.run(() => activityApi.reopenActivity(id))
    updateActivityLocal(updated)
    return updated
  }

  async function deleteActivity(id: number): Promise<void> {
    await deleteMutation.run(() => activityApi.deleteActivity(id))
    removeActivityLocal(id)
  }

  async function pinActivity(id: number, isPinned: boolean): Promise<ActivityDto> {
    const updated = await activityApi.updateActivity(id, { is_pinned: isPinned })
    updateActivityLocal(updated)
    return updated
  }

  return {
    groups,
    openTasks,
    loading,
    hasMore,
    searchQuery,
    filterType,
    setSearch,
    setFilterType,
    applyFilter,
    resetFilter,
    load,
    loadMore,
    collapseAll,
    expandAll,
    toggleGroup,
    createMutation: useMutation<ActivityDto>(),
    prependLocal,
    updateActivityLocal,
    removeActivityLocal,
    completeActivity,
    reopenActivity,
    deleteActivity,
    pinActivity,
  }
}
