import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { useReportGenerationModalStore } from '@/stores/reportGenerationModal'
import { canUseAi as hasAiCapability } from '@/shared/auth/capabilities'

export const useReportsPageActions = () => {
  const router = useRouter()
  const userStore = useUserStore()
  const modalStore = useReportGenerationModalStore()

  const canUseAi = computed(() => hasAiCapability(userStore.getUserRole))

  const openReport = (id: number) => {
    void router.push(`/reports/${id}`)
  }

  /**
   * Header `+` button → opens the report-generation modal in create-mode.
   * Generation happens in a global overlay (mounted in DefaultLayout); the
   * chat is created lazily on the first send inside the modal, so there is
   * nothing to create up-front here. `canUseAi` gates the entry point.
   */
  const openGenerationModal = () => {
    if (!canUseAi.value) {
      return
    }

    modalStore.open({ mode: 'create' })
  }

  /**
   * "Generate custom report" tile → same create-mode modal as the header `+`
   * button. The chat is created lazily on first send inside the modal, so
   * this is a plain `open(...)`.
   */
  const generateCustomReport = () => {
    if (!canUseAi.value) {
      return
    }

    modalStore.open({ mode: 'create' })
  }

  return {
    canUseAi,
    openReport,
    openGenerationModal,
    generateCustomReport,
  }
}
