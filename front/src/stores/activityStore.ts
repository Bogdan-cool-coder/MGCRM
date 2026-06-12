/**
 * Activity Pinia store — client state only.
 * Server-state (lists) is in page/tab composables via useAsyncResource.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { activityApi } from '@/api/activity'
import type { ActivityCountsDto, ActivityKind } from '@/entities/activity'

export interface QuickAddContext {
  dealId: number
  allowedKinds?: ActivityKind[]
}

export interface EditContext {
  activityId: number
}

export const useActivityStore = defineStore('activity', () => {
  // ─── Counts cache (updated on MyTasksPage mount) ──────────────────────────
  const countsCache = ref<ActivityCountsDto | null>(null)

  // ─── My open count (shown as nav badge) ──────────────────────────────────
  const myOpenCount = ref<number>(0)

  // ─── Quick-add context (Kanban card → ActivityFormDialog) ─────────────────
  const quickAddContext = ref<QuickAddContext | null>(null)

  // ─── Edit context (open form for existing activity) ───────────────────────
  const editContext = ref<EditContext | null>(null)

  // ─── Create context (standalone, no target) ──────────────────────────────
  const createContext = ref<{ kind?: ActivityKind; targetType?: string; targetId?: number } | null>(null)

  // ─── Actions ──────────────────────────────────────────────────────────────

  function openQuickAdd(dealId: number, allowedKinds?: ActivityKind[]) {
    quickAddContext.value = { dealId, allowedKinds }
  }

  function closeQuickAdd() {
    quickAddContext.value = null
  }

  function openEdit(activityId: number) {
    editContext.value = { activityId }
  }

  function closeEdit() {
    editContext.value = null
  }

  function openCreate(opts?: { kind?: ActivityKind; targetType?: string; targetId?: number }) {
    createContext.value = opts ?? {}
  }

  function closeCreate() {
    createContext.value = null
  }

  async function fetchCounts(): Promise<void> {
    try {
      countsCache.value = await activityApi.getCountsByPreset()
    } catch {
      // non-critical
    }
  }

  async function fetchMyOpenCount(): Promise<void> {
    try {
      myOpenCount.value = await activityApi.getMyOpenCount()
    } catch {
      // non-critical
    }
  }

  return {
    countsCache,
    myOpenCount,
    quickAddContext,
    editContext,
    createContext,
    openQuickAdd,
    closeQuickAdd,
    openEdit,
    closeEdit,
    openCreate,
    closeCreate,
    fetchCounts,
    fetchMyOpenCount,
  }
})
