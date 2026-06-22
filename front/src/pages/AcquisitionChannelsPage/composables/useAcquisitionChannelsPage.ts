import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { directoriesApi } from '@/api/crm/directories'
import { useUserStore } from '@/stores/user'
import type { AcquisitionChannel } from '@/entities/crm'

export interface ChannelFormPayload {
  name: string
  sort_order: number
  is_active: boolean
}

export const useAcquisitionChannelsPage = () => {
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
  const channels = ref<AcquisitionChannel[]>([])
  const loading = ref(false)

  async function fetchChannels() {
    loading.value = true
    try {
      channels.value = await directoriesApi.getAcquisitionChannels()
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 3000 })
    } finally {
      loading.value = false
    }
  }

  void fetchChannels()

  // ─── Dialog ───────────────────────────────────────────────────────────────────
  const dialogVisible = ref(false)
  const editingChannel = ref<AcquisitionChannel | null>(null)

  function openCreate() {
    editingChannel.value = null
    dialogVisible.value = true
  }

  function openEdit(channel: AcquisitionChannel) {
    editingChannel.value = channel
    dialogVisible.value = true
  }

  const saveMutation = useMutation<AcquisitionChannel>()

  async function save(payload: ChannelFormPayload) {
    await saveMutation.run(
      async () => {
        if (editingChannel.value) {
          return await directoriesApi.updateAcquisitionChannel(editingChannel.value.id, payload)
        }
        return await directoriesApi.createAcquisitionChannel(payload)
      },
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchChannels()
          toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  // ─── Toggle active ────────────────────────────────────────────────────────────
  async function toggleActive(channel: AcquisitionChannel) {
    try {
      const updated = await directoriesApi.updateAcquisitionChannel(channel.id, {
        is_active: !channel.is_active,
      })
      const idx = channels.value.findIndex((c) => c.id === channel.id)
      if (idx >= 0) channels.value[idx] = updated
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Delete ───────────────────────────────────────────────────────────────────
  function deleteChannel(channel: AcquisitionChannel) {
    confirm.require({
      message: t('admin.acquisitionChannels.deleteConfirm'),
      header: t('common.delete'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        try {
          await directoriesApi.deleteAcquisitionChannel(channel.id)
          channels.value = channels.value.filter((c) => c.id !== channel.id)
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 2000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        }
      },
    })
  }

  return {
    channels,
    loading,
    dialogVisible,
    editingChannel,
    canManage,
    saveMutation,
    openCreate,
    openEdit,
    save,
    toggleActive,
    deleteChannel,
  }
}
