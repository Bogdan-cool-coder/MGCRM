import { computed, ref } from 'vue'
import { useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'
import { canManageDocuments } from '@/shared/auth/capabilities'

export const useDocumentsPageActions = () => {
  const router = useRouter()
  const userStore = useUserStore()

  const canManage = computed(() => canManageDocuments(userStore.getUserRole))

  const openDocument = (id: number) => {
    void router.push(`/documents/${id}`)
  }

  // ─── Create-template dialog ─────────────────────────────────────────────
  const createDialogVisible = ref(false)

  const openCreateDialog = () => {
    createDialogVisible.value = true
  }

  const closeCreateDialog = () => {
    createDialogVisible.value = false
  }

  /**
   * After a template is created the dialog navigates straight to its editor
   * (`/documents/{id}`): for docx the user uploads the source + maps fields
   * (M6), for html the (currently empty) КП editor opens (M4).
   */
  const goToCreatedDocument = (id: number) => {
    closeCreateDialog()
    void router.push(`/documents/${id}`)
  }

  return {
    canManage,
    openDocument,
    createDialogVisible,
    openCreateDialog,
    closeCreateDialog,
    goToCreatedDocument,
  }
}
