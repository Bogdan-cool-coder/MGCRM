import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { directoriesApi } from '@/api/crm/directories'
import { useUserStore } from '@/stores/user'
import { useDirectoriesStore } from '@/stores/directories'
import type { Country } from '@/entities/crm'

export interface CountryFormPayload {
  code: string
  name: string
  name_en: string
  phone_prefix: string
  sort_order: number
  is_active: boolean
}

export const useCountriesPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()
  const directoriesStore = useDirectoriesStore()

  // ─── Gate ────────────────────────────────────────────────────────────────────
  const canManage = (() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })()

  // ─── Data ─────────────────────────────────────────────────────────────────────
  const countries = ref<Country[]>([])
  const loading = ref(false)

  async function fetchCountries() {
    loading.value = true
    try {
      // Fetch ALL countries (including inactive) for the admin table
      countries.value = await directoriesApi.getCountries()
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 3000 })
    } finally {
      loading.value = false
    }
  }

  void fetchCountries()

  // ─── Dialog ───────────────────────────────────────────────────────────────────
  const dialogVisible = ref(false)
  const editingCountry = ref<Country | null>(null)

  function openCreate() {
    editingCountry.value = null
    dialogVisible.value = true
  }

  function openEdit(country: Country) {
    editingCountry.value = country
    dialogVisible.value = true
  }

  const saveMutation = useMutation<Country>()

  async function save(payload: CountryFormPayload) {
    await saveMutation.run(
      async () => {
        if (editingCountry.value) {
          // code is immutable — only send editable fields (name, name_en, phone_prefix, sort_order, is_active)
          return await directoriesApi.updateCountry(editingCountry.value.id, {
            name: payload.name,
            name_en: payload.name_en,
            phone_prefix: payload.phone_prefix,
            sort_order: payload.sort_order,
            is_active: payload.is_active,
          })
        }
        return await directoriesApi.createCountry(payload)
      },
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchCountries()
          // Invalidate the directories store so active selects refresh on next mount
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
  async function toggleActive(country: Country) {
    try {
      const updated = await directoriesApi.updateCountry(country.id, {
        is_active: !country.is_active,
      })
      const idx = countries.value.findIndex((c) => c.id === country.id)
      if (idx >= 0) countries.value[idx] = updated
      // Invalidate so active selects refresh
      directoriesStore.loaded = false
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Delete ───────────────────────────────────────────────────────────────────
  function deleteCountry(country: Country) {
    confirm.require({
      message: t('admin.countries.deleteConfirm', { name: country.name }),
      header: t('common.delete'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        try {
          await directoriesApi.deleteCountry(country.id)
          countries.value = countries.value.filter((c) => c.id !== country.id)
          directoriesStore.loaded = false
          toast.add({ severity: 'success', summary: t('common.deleted'), life: 2000 })
        } catch (err: unknown) {
          // Backend returns 422 when the country is referenced by companies / cities / requisites
          const axiosErr = err as { response?: { status?: number; data?: { message?: string } } }
          if (axiosErr?.response?.status === 422) {
            toast.add({
              severity: 'warn',
              summary: t('admin.countries.deleteBlockedTitle'),
              detail: axiosErr.response.data?.message ?? t('admin.countries.deleteBlockedDetail'),
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
    countries,
    loading,
    dialogVisible,
    editingCountry,
    canManage,
    saveMutation,
    openCreate,
    openEdit,
    save,
    toggleActive,
    deleteCountry,
  }
}
