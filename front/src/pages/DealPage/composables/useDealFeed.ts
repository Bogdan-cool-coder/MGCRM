/**
 * useDealFeed — unified chronological timeline for a deal.
 *
 * Source: GET /api/deals/{deal}/feed — server already merges stage_change,
 * activity and field_change events. deal_created is synthesised on the front
 * from deal.created_at and always appended as the last item.
 *
 * Grouping by date and client-side filter/search run over the accumulated
 * (all pages loaded) dataset.
 */
import { ref, computed } from 'vue'
import { apiClient } from '@/api/client'
import { activityApi } from '@/api/activity'
import { useMutation } from '@/composables/async/useMutation'
import type { ActivityDto, ActivityKind } from '@/entities/activity'

// ─── Feed item types ──────────────────────────────────────────────────────────

export type FeedItemType =
  | 'stage_change'
  | 'field_change'
  | 'deal_created'
  | 'note'
  | 'task'
  | 'call'
  | 'meeting'

export interface FeedActor {
  id: number
  full_name: string | null
}

export interface StageRef {
  id: number
  name: string
}

export interface FieldChange {
  field: string
  old_value: string | null
  new_value: string | null
}

export interface FeedItem {
  /** Composite key from backend: stage_{id} / activity_{id} / audit_{id} / deal_created */
  id: string
  type: FeedItemType
  /** ISO timestamp for sorting */
  timestamp: string
  /** YYYY-MM-DD for grouping */
  date: string
  actor: FeedActor | null
  /** Populated for stage_change */
  fromStage?: StageRef | null
  toStage?: StageRef | null
  /** Populated for activity (note/task/call/meeting) */
  activity?: ActivityDto
  /** Populated for field_change */
  fieldChanges?: FieldChange[]
  /** True when this is the synthetic deal_created item */
  isDealCreated?: boolean
}

export interface FeedGroup {
  date: string
  items: FeedItem[]
  collapsed: boolean
}

// ─── Raw API shape (from DealFeedService) ─────────────────────────────────────

interface RawFeedActor {
  id: number
  full_name: string | null
}

interface RawFeedPayloadStage {
  from_stage: StageRef | null
  to_stage: StageRef | null
  from_stage_id: number | null
  to_stage_id: number | null
}

interface RawFeedPayloadActivity {
  activity_id: number
  kind: ActivityKind
  title: string
  body: string | null
  due_at: string | null
  completed_at: string | null
  is_closed: boolean
  responsible: RawFeedActor | null
}

interface RawFeedPayloadFieldChange {
  field: string
  old_value: string | null
  new_value: string | null
}

interface RawFeedItem {
  id: string
  type: 'stage_change' | 'activity' | 'field_change'
  occurred_at: string
  actor: RawFeedActor | null
  payload: RawFeedPayloadStage | RawFeedPayloadActivity | RawFeedPayloadFieldChange
}

interface FeedApiResponse {
  data: RawFeedItem[]
  meta: {
    total: number
    per_page: number
    current_page: number
  }
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function toDate(iso: string): string {
  return iso.slice(0, 10) // YYYY-MM-DD
}

function normaliseItem(raw: RawFeedItem): FeedItem | null {
  const timestamp = raw.occurred_at
  const date = toDate(timestamp)
  const actor = raw.actor ?? null

  if (raw.type === 'stage_change') {
    const p = raw.payload as RawFeedPayloadStage
    return {
      id: raw.id,
      type: 'stage_change',
      timestamp,
      date,
      actor,
      fromStage: p.from_stage,
      toStage: p.to_stage,
    }
  }

  if (raw.type === 'activity') {
    const p = raw.payload as RawFeedPayloadActivity
    const kind = p.kind as ActivityKind
    // Reconstruct a minimal ActivityDto for DealFeedItem rendering
    const activity: ActivityDto = {
      id: p.activity_id,
      kind,
      status: p.is_closed ? 'done' : 'new',
      priority: 'normal',
      title: p.title,
      body: p.body,
      result_text: null,
      due_at: p.due_at,
      is_closed: p.is_closed,
      is_pinned: false,
      is_overdue: !p.is_closed && !!p.due_at && new Date(p.due_at) < new Date(),
      target_type: 'deal',
      target_id: null,
      target_label: null,
      responsible: p.responsible
        ? { id: p.responsible.id, full_name: p.responsible.full_name ?? '', avatar_path: null }
        : null,
      creator: actor
        ? { id: actor.id, full_name: actor.full_name ?? '', avatar_path: null }
        : null,
      ftm_decision_maker_attended: false,
      ftm_presentation_shown: false,
      ftm_report_url: null,
      meeting_report_json: null,
      department_id: null,
      created_at: timestamp,
      updated_at: timestamp,
    }
    return {
      id: raw.id,
      type: kind,
      timestamp,
      date,
      actor,
      activity,
    }
  }

  if (raw.type === 'field_change') {
    const p = raw.payload as RawFeedPayloadFieldChange
    return {
      id: raw.id,
      type: 'field_change',
      timestamp,
      date,
      actor,
      fieldChanges: [
        {
          field: p.field,
          old_value: p.old_value,
          new_value: p.new_value,
        },
      ],
    }
  }

  return null
}

function groupByDate(items: FeedItem[], existingGroups: FeedGroup[]): FeedGroup[] {
  const groupMap = new Map<string, FeedGroup>()
  // Preserve existing collapsed state
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
  // Sort groups desc (most recent first)
  return Array.from(groupMap.values()).sort((a, b) => b.date.localeCompare(a.date))
}

// ─── Composable ───────────────────────────────────────────────────────────────

export function useDealFeed(dealId: () => number, dealCreatedAt: () => string | null) {
  const PER_PAGE = 30

  const allItems = ref<FeedItem[]>([])
  const loading = ref(false)
  const currentPage = ref(1)
  const total = ref(0)

  const hasMore = computed(() => allItems.value.length < total.value)

  const searchQuery = ref('')
  const filterType = ref<FeedItemType | ''>('')

  const createMutation = useMutation<ActivityDto>()
  const completeMutation = useMutation<ActivityDto>()
  const reopenMutation = useMutation<ActivityDto>()
  const deleteMutation = useMutation()

  // Filtered flat list (client-side)
  const filteredItems = computed((): FeedItem[] => {
    let items = allItems.value
    const q = searchQuery.value.trim().toLowerCase()
    const type = filterType.value

    if (type) {
      items = items.filter((i) => {
        if (type === 'stage_change') return i.type === 'stage_change'
        if (type === 'field_change') return i.type === 'field_change'
        if (type === 'deal_created') return i.type === 'deal_created'
        // activity kinds
        return i.type === type
      })
    }

    if (q) {
      items = items.filter((i) => {
        const actorName = i.actor?.full_name?.toLowerCase() ?? ''
        const title = i.activity?.title?.toLowerCase() ?? ''
        const body = i.activity?.body?.toLowerCase() ?? ''
        const toStage = i.toStage?.name?.toLowerCase() ?? ''
        const fromStage = i.fromStage?.name?.toLowerCase() ?? ''
        const fields = i.fieldChanges?.map((c) => `${c.field} ${c.old_value ?? ''} ${c.new_value ?? ''}`).join(' ').toLowerCase() ?? ''
        return (
          actorName.includes(q) ||
          title.includes(q) ||
          body.includes(q) ||
          toStage.includes(q) ||
          fromStage.includes(q) ||
          fields.includes(q)
        )
      })
    }

    return items
  })

  // Groups (with preserved collapse state)
  const groups = ref<FeedGroup[]>([])

  // Recompute groups whenever filteredItems changes
  function recomputeGroups() {
    groups.value = groupByDate(filteredItems.value, groups.value)
  }

  // Watch is not used here to keep it explicit — call after each load/mutation

  // ─── Synthetic deal_created item ─────────────────────────────────────────────

  function buildDealCreatedItem(): FeedItem | null {
    const ts = dealCreatedAt()
    if (!ts) return null
    return {
      id: 'deal_created',
      type: 'deal_created',
      timestamp: ts,
      date: toDate(ts),
      actor: null,
      isDealCreated: true,
    }
  }

  // ─── API load ─────────────────────────────────────────────────────────────────

  async function fetchPage(page: number): Promise<void> {
    loading.value = true
    try {
      const res = await apiClient.get<FeedApiResponse>(`/api/deals/${dealId()}/feed`, {
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
        // Add deal_created synthetic item
        const created = buildDealCreatedItem()
        if (created) allItems.value.push(created)
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

  // ─── Client-side filter/search ────────────────────────────────────────────────

  function setSearch(q: string) {
    searchQuery.value = q
    recomputeGroups()
  }

  function setFilterType(t: FeedItemType | '') {
    filterType.value = t
    recomputeGroups()
  }

  function applyFilter() {
    recomputeGroups()
  }

  function resetFilter() {
    searchQuery.value = ''
    filterType.value = ''
    recomputeGroups()
  }

  // ─── Collapse / expand ────────────────────────────────────────────────────────

  function collapseAll() {
    groups.value = groups.value.map((g) => ({ ...g, collapsed: true }))
  }

  function expandAll() {
    groups.value = groups.value.map((g) => ({ ...g, collapsed: false }))
  }

  function toggleGroup(date: string) {
    const idx = groups.value.findIndex((g) => g.date === date)
    if (idx !== -1) {
      const current = groups.value[idx]
      if (current) {
        groups.value[idx] = {
          date: current.date,
          items: current.items,
          collapsed: !current.collapsed,
        }
      }
    }
  }

  // ─── Local mutations (prepend to feed after creation) ─────────────────────────

  function prependLocal(activity: ActivityDto) {
    const kind = activity.kind as ActivityKind
    const ts = activity.created_at
    const item: FeedItem = {
      id: `activity_${activity.id}`,
      type: kind,
      timestamp: ts,
      date: toDate(ts),
      actor: activity.creator
        ? { id: activity.creator.id, full_name: activity.creator.full_name }
        : null,
      activity,
    }
    allItems.value = [item, ...allItems.value]
    total.value += 1
    recomputeGroups()
  }

  function updateActivityLocal(updated: ActivityDto) {
    allItems.value = allItems.value.map((item) => {
      if (item.type === 'deal_created') return item
      if (
        (item.type === 'note' ||
          item.type === 'task' ||
          item.type === 'call' ||
          item.type === 'meeting') &&
        item.activity?.id === updated.id
      ) {
        return { ...item, activity: updated }
      }
      return item
    })
    recomputeGroups()
  }

  function removeActivityLocal(id: number) {
    allItems.value = allItems.value.filter(
      (item) =>
        !(
          (item.type === 'note' ||
            item.type === 'task' ||
            item.type === 'call' ||
            item.type === 'meeting') &&
          item.activity?.id === id
        ),
    )
    total.value = Math.max(0, total.value - 1)
    recomputeGroups()
  }

  // ─── Activity action helpers (called from DealFeedItem) ───────────────────────

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
    // State
    groups,
    loading,
    hasMore,
    searchQuery,
    filterType,
    // Filter
    setSearch,
    setFilterType,
    applyFilter,
    resetFilter,
    // Load
    load,
    loadMore,
    // Collapse
    collapseAll,
    expandAll,
    toggleGroup,
    // Mutations
    createMutation,
    prependLocal,
    updateActivityLocal,
    removeActivityLocal,
    completeActivity,
    reopenActivity,
    deleteActivity,
    pinActivity,
  }
}
