import { ref, computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useMutation } from '@/composables/async/useMutation'
import { templateVariablesApi } from '@/api/templateVariables'
import { useUserStore } from '@/stores/user'
import { isAxiosError } from 'axios'
import type {
  TemplateVariableDto,
  TemplateVariableType,
  CreateTemplateVariablePayload,
} from '@/entities/templateVariable'

export const useTemplateVariablesPage = () => {
  const { t } = useI18n()
  const toast = useToast()
  const userStore = useUserStore()

  const searchFilter = ref('')
  const typeFilter = ref<TemplateVariableType | null>(null)
  const onlyActive = ref(true)

  const resource = useAsyncResource<TemplateVariableDto[]>(() => [])
  const variables = computed(() => resource.data.value)
  const loading = computed(() => resource.loading.value)

  async function fetchVariables() {
    await resource.run(() =>
      templateVariablesApi.getTemplateVariables({
        var_type: typeFilter.value ?? undefined,
        is_active: onlyActive.value ? true : undefined,
        search: searchFilter.value || undefined,
      }),
    )
  }

  watch([searchFilter, typeFilter, onlyActive], () => void fetchVariables(), { immediate: true })

  // ─── CRUD dialog ──────────────────────────────────────────────────────────
  const dialogVisible = ref(false)
  const editingVariable = ref<TemplateVariableDto | null>(null)

  function openCreate() {
    editingVariable.value = null
    dialogVisible.value = true
  }

  function openEdit(v: TemplateVariableDto) {
    editingVariable.value = v
    dialogVisible.value = true
  }

  const saveMutation = useMutation<TemplateVariableDto>()

  async function save(payload: CreateTemplateVariablePayload) {
    await saveMutation.run(
      async () => {
        if (editingVariable.value) {
          return await templateVariablesApi.patchTemplateVariable(
            editingVariable.value.id,
            payload,
          )
        }
        return await templateVariablesApi.createTemplateVariable(payload)
      },
      {
        onSuccess: () => {
          dialogVisible.value = false
          void fetchVariables()
          toast.add({ severity: 'success', summary: t('common.save'), life: 2000 })
        },
        onError: () => {
          toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
        },
      },
    )
  }

  // ─── Toggle active ─────────────────────────────────────────────────────────
  async function toggleActive(v: TemplateVariableDto) {
    try {
      const updated = await templateVariablesApi.patchTemplateVariable(v.id, { is_active: !v.is_active })
      const idx = resource.data.value.findIndex((x) => x.id === v.id)
      if (idx >= 0) resource.data.value[idx] = updated
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    }
  }

  // ─── Delete variable ──────────────────────────────────────────────────────
  async function deleteVariable(v: TemplateVariableDto) {
    try {
      await templateVariablesApi.deleteTemplateVariable(v.id)
      void fetchVariables()
      toast.add({ severity: 'success', summary: t('common.delete', 'Удалено'), life: 2000 })
    } catch (err) {
      if (isAxiosError(err) && err.response?.status === 409) {
        toast.add({
          severity: 'warn',
          summary: t('templateVariables.deleteInUse', 'Переменная используется в документах'),
          life: 4000,
        })
      } else {
        toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
      }
    }
  }

  // ─── Copy key ─────────────────────────────────────────────────────────────
  async function copyKey(key: string) {
    try {
      await navigator.clipboard.writeText(`{{${key}}}`)
      toast.add({ severity: 'success', summary: t('templateVariables.copied'), life: 1000 })
    } catch {
      // ignore
    }
  }

  const canManage = computed(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'lawyer'
  })

  const typeOptions = computed(() => [
    { label: t('templateVariables.types.text'), value: 'text' as TemplateVariableType },
    { label: t('templateVariables.types.textarea'), value: 'textarea' as TemplateVariableType },
    { label: t('templateVariables.types.number'), value: 'number' as TemplateVariableType },
    { label: t('templateVariables.types.date'), value: 'date' as TemplateVariableType },
    { label: t('templateVariables.types.select'), value: 'select' as TemplateVariableType },
    { label: t('templateVariables.types.checkbox'), value: 'checkbox' as TemplateVariableType },
  ])

  return {
    t,
    searchFilter,
    typeFilter,
    onlyActive,
    variables,
    loading,
    dialogVisible,
    editingVariable,
    openCreate,
    openEdit,
    save,
    saveMutation,
    toggleActive,
    deleteVariable,
    copyKey,
    canManage,
    typeOptions,
  }
}
