/**
 * Document approval polling composable.
 * Fetches ApprovalSummary; polls while status is in_review or submitted.
 */
import { computed, watch, onUnmounted } from 'vue'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { documentsApi } from '@/api/documents'
import type { ApprovalSummaryDto } from '@/entities/document'
import type { Ref } from 'vue'

const POLL_INTERVAL_MS = 3000

export const useDocumentApproval = (docId: Ref<number>, statusRef: Ref<string | undefined>) => {
  const approvalResource = useAsyncResource<ApprovalSummaryDto | null>(() => null)
  const approval = computed(() => approvalResource.data.value)
  const loadingApproval = computed(() => approvalResource.loading.value)

  let pollTimer: ReturnType<typeof setInterval> | null = null

  function stopPolling() {
    if (pollTimer) {
      clearInterval(pollTimer)
      pollTimer = null
    }
  }

  async function fetchApproval() {
    if (!docId.value) return
    try {
      await approvalResource.run(() => documentsApi.getApprovalSummary(docId.value))
    } catch {
      // non-critical for polling
    }
  }

  function startPolling() {
    stopPolling()
    void fetchApproval()
    pollTimer = setInterval(() => {
      void fetchApproval()
    }, POLL_INTERVAL_MS)
  }

  // Poll when document is under review
  const shouldPoll = computed(() => {
    const s = statusRef.value
    return s === 'in_review' || s === 'submitted'
  })

  watch(
    shouldPoll,
    (active) => {
      if (active) {
        startPolling()
      } else {
        stopPolling()
        // Still fetch once to get final state
        void fetchApproval()
      }
    },
    { immediate: true },
  )

  watch(docId, () => {
    stopPolling()
    approvalResource.reset()
    if (shouldPoll.value) {
      startPolling()
    } else {
      void fetchApproval()
    }
  })

  onUnmounted(() => {
    stopPolling()
  })

  return {
    approval,
    loadingApproval,
    fetchApproval,
  }
}
