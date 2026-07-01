import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { directoriesApi } from '@/api/crm/directories'
import { useUserStore } from '@/stores/user'
import { useDirectoriesStore } from '@/stores/directories'
import type { Tag, TagScope } from '@/entities/crm'

export interface TagFormPayload {
  name: string
  color: string | null
  scope: TagScope | null
  sort_order: number
  is_active: boolean
}

export const useTagsPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()
  const directoriesStore = useDirectoriesStore()

  // ─── Gate ─────────────────────────────────────────────────────────────────────
  const canManage = (() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })()

  // ─── Data ──────────────────────────────────────────────────────────────────────
  const tagsList = ref<Tag[]>([])
  const loading = ref(false)

  async function fetchTags() {
    loading.value = true
    try {
      // Fetch ALL tags (including inactive) for the admin table
      tagsList.value = await directoriesApi.getTags()
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 3000 })
    } finally {
      loading.value = false
    }
  }

  void fetchTags()

  // ─── Dialog ───────────────────────────────────────────────────────────────────
  const dialogVisible = ref(false)
  const editingTag = ref<Tag | null>(null)

  function openCreate() {
    editingTag.value = null
    dialogVisible.value = true
  }

  function openEdit(tag: Tag) {
    editingTag.value = tag
    dialogVisible.value = true
  }

  const saveMutation = useMutation<Tag>()

  async function save(payload: TagFormPayload) {
    await saveMutation.run(
      async () => {
        if (editingTag.value) {
          return await directoriesApi.updateTag(editingTag.value.id, payload)
        }
        return await directoriesApi.createTag(payload)
      },
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchTags()
          // Invalidate directories store so autocomplete suggestions refresh on next mount
          directoriesStore.loaded = false
          toast.add({ severity: 'success', summary: t('common.saved'), life: 2000 })
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  // ─── Toggle active ────────────────────────────────────────────────────────────
  async function toggleActive(tag: Tag) {
    try {
      const updated = await directoriesApi.updateTag(tag.id, { is_active: !tag.is_active })
      const idx = tagsList.value.findIndex((t) => t.id === tag.id)
      if (idx >= 0) tagsList.value[idx] = updated
      directoriesStore.loaded = false
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Delete ───────────────────────────────────────────────────────────────────
  function deleteTag(tag: Tag) {
    confirm.require({
      message: t('admin.tags.deleteConfirm', { name: tag.name }),
      header: t('common.delete'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        try {
          await directoriesApi.deleteTag(tag.id)
          tagsList.value = tagsList.value.filter((t) => t.id !== tag.id)
          directoriesStore.loaded = false
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 2000 })
        } catch (err: unknown) {
          const axiosErr = err as { response?: { status?: number } }
          if (axiosErr?.response?.status === 422) {
            toast.add({
              severity: 'warn',
              summary: t('admin.tags.deleteBlockedTitle'),
              detail: t('admin.tags.deleteBlockedDetail'),
              life: 6000,
            })
          } else {
            toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
          }
        }
      },
    })
  }

  return {
    tagsList,
    loading,
    dialogVisible,
    editingTag,
    canManage,
    saveMutation,
    openCreate,
    openEdit,
    save,
    toggleActive,
    deleteTag,
  }
}
