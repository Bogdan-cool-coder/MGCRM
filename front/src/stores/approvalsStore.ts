/**
 * Approvals Pinia store — client state only.
 * Server-state (lists) is in page composables via useAsyncResource.
 * This store holds the pending count for the sidebar badge.
 */
import { ref } from 'vue'
import { defineStore } from 'pinia'
import { approvalsApi } from '@/api/approvals'

export const useApprovalsStore = defineStore('approvals', () => {
  // ─── Pending count (sidebar badge) ───────────────────────────────────────
  const pendingCount = ref<number>(0)

  // ─── Actions ──────────────────────────────────────────────────────────────

  async function fetchPendingCount(): Promise<void> {
    try {
      pendingCount.value = await approvalsApi.getMyPendingCount()
    } catch {
      // non-critical
    }
  }

  function decrementPending(): void {
    if (pendingCount.value > 0) {
      pendingCount.value -= 1
    }
  }

  function incrementPending(): void {
    pendingCount.value += 1
  }

  return {
    pendingCount,
    fetchPendingCount,
    decrementPending,
    incrementPending,
  }
})
