import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { directoriesApi } from '@/api/crm/directories'
import { useUserStore } from '@/stores/user'
import type { DisconnectReason } from '@/entities/crm'

export interface ReasonFormPayload {
  name: string
  sort_order: number
  is_active: boolean
}

export const useDisconnectReasonsPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()

  // ─── Gate ────────────────────────────────────────────────────────────────────
  const canManage = (() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })()

  // ─── Data ─────────────────────────────────────────────────────────────────────
  const reasons = ref<DisconnectReason[]>([])
  const loading = ref(false)

  async function fetchReasons() {
    loading.value = true
    try {
      reasons.value = await directoriesApi.getDisconnectReasons()
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 3000 })
    } finally {
      loading.value = false
    }
  }

  void fetchReasons()

  // ─── Dialog ───────────────────────────────────────────────────────────────────
  const dialogVisible = ref(false)
  const editingReason = ref<DisconnectReason | null>(null)

  function openCreate() {
    editingReason.value = null
    dialogVisible.value = true
  }

  function openEdit(reason: DisconnectReason) {
    editingReason.value = reason
    dialogVisible.value = true
  }

  const saveMutation = useMutation<DisconnectReason>()

  async function save(payload: ReasonFormPayload) {
    await saveMutation.run(
      async () => {
        if (editingReason.value) {
          return await directoriesApi.updateDisconnectReason(editingReason.value.id, payload)
        }
        return await directoriesApi.createDisconnectReason(payload)
      },
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchReasons()
          toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  // ─── Toggle active ────────────────────────────────────────────────────────────
  async function toggleActive(reason: DisconnectReason) {
    try {
      const updated = await directoriesApi.updateDisconnectReason(reason.id, {
        is_active: !reason.is_active,
      })
      const idx = reasons.value.findIndex((r) => r.id === reason.id)
      if (idx >= 0) reasons.value[idx] = updated
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Delete ───────────────────────────────────────────────────────────────────
  function deleteReason(reason: DisconnectReason) {
    confirm.require({
      message: t('admin.disconnectReasons.deleteConfirm'),
      header: t('common.delete'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        try {
          await directoriesApi.deleteDisconnectReason(reason.id)
          reasons.value = reasons.value.filter((r) => r.id !== reason.id)
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 2000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        }
      },
    })
  }

  return {
    reasons,
    loading,
    dialogVisible,
    editingReason,
    canManage,
    saveMutation,
    openCreate,
    openEdit,
    save,
    toggleActive,
    deleteReason,
  }
}
