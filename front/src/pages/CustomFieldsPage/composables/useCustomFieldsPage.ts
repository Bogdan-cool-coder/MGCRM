import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { customFieldsApi } from '@/api/crm/customFields'
import type { CreateCustomFieldPayload, UpdateCustomFieldPayload } from '@/api/crm/customFields'
import { useUserStore } from '@/stores/user'
import type { CustomFieldDef, CustomFieldScope } from '@/entities/crm'

export type ScopeFilter = 'all' | CustomFieldScope

export interface CustomFieldFormPayload {
  label: string
  code: string
  entity_scope: CustomFieldScope
  field_type: string
  options: string[]
  help_text: string | null
  sort_order: number
  is_required: boolean
  is_active: boolean
}

export function useCustomFieldsPage() {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()
  const userStore = useUserStore()

  // ─── Gate ─────────────────────────────────────────────────────────────────
  const role = userStore.getUserRole
  const canManage = role === 'admin' || role === 'director'

  // ─── Scope filter ─────────────────────────────────────────────────────────
  const activeScope = ref<ScopeFilter>('all')

  // ─── Data ─────────────────────────────────────────────────────────────────
  const allFields = ref<CustomFieldDef[]>([])
  const loading = ref(false)

  const filteredFields = computed(() => {
    if (activeScope.value === 'all') return allFields.value
    return allFields.value.filter((f) => f.entity_scope === activeScope.value)
  })

  async function fetchAll() {
    loading.value = true
    try {
      allFields.value = await customFieldsApi.getAll()
    } catch {
      toast.add({ severity: 'error', summary: t('common.loadError'), life: 3000 })
    } finally {
      loading.value = false
    }
  }

  void fetchAll()

  // ─── Dialog state ─────────────────────────────────────────────────────────
  const dialogVisible = ref(false)
  const editingField = ref<CustomFieldDef | null>(null)

  function openCreate() {
    editingField.value = null
    dialogVisible.value = true
  }

  function openEdit(field: CustomFieldDef) {
    editingField.value = field
    dialogVisible.value = true
  }

  // ─── Save ─────────────────────────────────────────────────────────────────
  const saveMutation = useMutation<CustomFieldDef>()

  async function save(payload: CustomFieldFormPayload) {
    await saveMutation.run(
      async () => {
        if (editingField.value) {
          const updatePayload: UpdateCustomFieldPayload = {
            label: payload.label,
            help_text: payload.help_text,
            field_type: payload.field_type,
            options: payload.options,
            sort_order: payload.sort_order,
            required: payload.is_required,
            is_active: payload.is_active,
          }
          return await customFieldsApi.update(editingField.value.id, updatePayload)
        }
        const createPayload: CreateCustomFieldPayload = {
          label: payload.label,
          code: payload.code,
          entity_scope: payload.entity_scope,
          field_type: payload.field_type,
          options: payload.options,
          help_text: payload.help_text,
          sort_order: payload.sort_order,
          required: payload.is_required,
          is_active: payload.is_active,
        }
        return await customFieldsApi.create(createPayload)
      },
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchAll()
          toast.add({ severity: 'success', summary: t('customFields.saved'), life: 2000 })
        },
        onError: (err: unknown) => {
          const axiosErr = err as { response?: { status?: number; data?: { errors?: Record<string, string[]> } } }
          if (axiosErr?.response?.status === 422) {
            const codeError = axiosErr.response?.data?.errors?.['code']
            if (codeError?.length) {
              toast.add({
                severity: 'error',
                summary: t('customFields.errors.codeConflict'),
                life: 5000,
              })
              return
            }
          }
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  // ─── Toggle active ────────────────────────────────────────────────────────
  async function toggleActive(field: CustomFieldDef) {
    try {
      const updated = await customFieldsApi.update(field.id, { is_active: !field.is_active })
      const idx = allFields.value.findIndex((f) => f.id === field.id)
      if (idx >= 0) allFields.value[idx] = updated
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Delete ───────────────────────────────────────────────────────────────
  function deleteField(field: CustomFieldDef) {
    confirm.require({
      message: t('customFields.deleteConfirm', { label: field.label }),
      header: t('common.delete'),
      icon: 'pi pi-exclamation-triangle',
      accept: async () => {
        try {
          await customFieldsApi.remove(field.id)
          allFields.value = allFields.value.filter((f) => f.id !== field.id)
          toast.add({ severity: 'success', summary: t('customFields.deleted'), life: 2000 })
        } catch {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        }
      },
    })
  }

  // ─── Reorder ──────────────────────────────────────────────────────────────
  async function reorder(rows: CustomFieldDef[]) {
    if (activeScope.value === 'all') return

    const items = rows.map((row, idx) => ({ id: row.id, sort_order: idx }))
    try {
      await customFieldsApi.reorder(activeScope.value as CustomFieldScope, items)
      // Apply locally — optimistic update already done by DataTable row-reorder
      items.forEach(({ id, sort_order }) => {
        const f = allFields.value.find((x) => x.id === id)
        if (f) f.sort_order = sort_order
      })
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
      // Reload to restore true order
      void fetchAll()
    }
  }

  return {
    allFields,
    filteredFields,
    loading,
    activeScope,
    dialogVisible,
    editingField,
    canManage,
    saveMutation,
    openCreate,
    openEdit,
    save,
    toggleActive,
    deleteField,
    reorder,
    fetchAll,
  }
}
