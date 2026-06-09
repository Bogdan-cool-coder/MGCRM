import { ref, reactive, computed } from 'vue'
import { useCompaniesStore } from '@/stores/companies'
import type { CreateCompanyRequest } from '@/api/types'
import type { Company } from '@/entities/company'
import { requireEntity } from '@/shared/session/guards'
import type { CompanyFormData, CompanyFormErrors } from '@/components/Company'
import { getApiErrorMessage, getApiErrorStatus } from '@/utils/errors'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import { useSessionMutation } from '@/composables/async/useSessionMutation'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import en from '@/components/Company/locale/en.json'
import ru from '@/components/Company/locale/ru.json'

export function useCompanyManagementModal() {
  const { t } = useLocalI18n({ en, ru })
  const { notifyApiError, notifySuccess } = useNotifications()
  const companiesStore = useCompaniesStore()
  const { companyService } = useServices()
  const saveMutation = useSessionMutation<boolean>()
  const deleteMutation = useSessionMutation<boolean>()
  const companies = computed(() => companiesStore.getCompanies)
  const companiesResource = useAsyncResource<void>(undefined)
  const loading = companiesResource.loading
  const saving = saveMutation.isPending
  const deleting = deleteMutation.isPending

  const formVisible = ref(false)
  const isEditMode = ref(false)
  const errors = reactive<CompanyFormErrors>({})
  const formError = ref('')

  const formData: CompanyFormData = reactive({
    name: '',
    crm_url: '',
    currency_code: '',
    timezone: '',
    macrodata_host: '',
    macrodata_port: '',
    macrodata_database: '',
    macrodata_username: '',
    macrodata_password: '',
  })

  const companyToDelete = ref<Company | null>(null)
  const deleteConfirmVisible = ref(false)

  const fetchCompanies = async () => {
    try {
      await companiesResource.run(async () => {
        companiesStore.setCompanies(await companyService.fetchCompanies())
      })
    } catch (error: unknown) {
      console.error(t('loadError'), error)
    }
  }

  const resetFormData = () => {
    Object.assign(formData, {
      id: undefined,
      name: '',
      crm_url: '',
      currency_code: '',
      timezone: '',
      macrodata_host: '',
      macrodata_port: '',
      macrodata_database: '',
      macrodata_username: '',
      macrodata_password: '',
    })
    errors.name = ''
    formError.value = ''
  }

  const openCreateModal = () => {
    isEditMode.value = false
    resetFormData()
    formVisible.value = true
  }

  const openEditModal = (company: Company) => {
    isEditMode.value = true
    Object.assign(formData, {
      id: company.id,
      name: company.name,
      crm_url: company.crm_url || '',
      currency_code: company.currency_code || '',
      timezone: company.timezone || '',
      macrodata_host: company.macrodata_host || '',
      macrodata_port: company.macrodata_port ? String(company.macrodata_port) : '',
      macrodata_database: company.macrodata_database || '',
      macrodata_username: company.macrodata_username || '',
      macrodata_password: '',
    })
    errors.name = ''
    formError.value = ''
    formVisible.value = true
  }

  const closeFormModal = () => {
    formVisible.value = false
  }

  const validateForm = (): boolean => {
    errors.name = ''

    if (!formData.name.trim()) {
      errors.name = t('companyNameRequired')
      return false
    }

    return true
  }

  const buildPayload = (): CreateCompanyRequest => {
    const payload: CreateCompanyRequest = {
      name: formData.name.trim(),
    }

    const trimmedCrmUrl = formData.crm_url.trim()
    payload.crm_url = trimmedCrmUrl || null

    // Currency / timezone are optional both on create and on edit — backend
    // accepts null and the formatter falls back to RUB / UTC.
    const trimmedCurrency = formData.currency_code.trim().toUpperCase()
    payload.currency_code = trimmedCurrency || null

    const trimmedTimezone = formData.timezone.trim()
    payload.timezone = trimmedTimezone || null

    if (formData.macrodata_host) payload.macrodata_host = formData.macrodata_host
    if (formData.macrodata_port) payload.macrodata_port = parseInt(formData.macrodata_port, 10)
    if (formData.macrodata_database) payload.macrodata_database = formData.macrodata_database
    if (formData.macrodata_username) payload.macrodata_username = formData.macrodata_username
    if (formData.macrodata_password) payload.macrodata_password = formData.macrodata_password

    return payload
  }

  const submitForm = async (): Promise<boolean> => {
    if (!validateForm()) return false

    formError.value = ''

    try {
      await saveMutation.run(async () => {
        const payload = buildPayload()

        if (isEditMode.value && formData.id) {
          companiesStore.upsertCompany(await companyService.updateCompanyById(formData.id, payload))
          return true
        }

        companiesStore.upsertCompany(await companyService.createCompany(payload))
        companiesStore.reconcileActiveCompany()
        return true
      }, {
        sync: 'company',
        onSuccess: () => {
          formVisible.value = false
          notifySuccess(
            isEditMode.value ? t('companyUpdatedSuccess') : t('companyCreatedSuccess'),
            t('successSummary'),
          )
        },
      })
      return true
    } catch (error: unknown) {
      if (getApiErrorStatus(error) === 422) {
        formError.value = getApiErrorMessage(error, t('validationError'))
      } else {
        formError.value = getApiErrorMessage(error, t('saveError'))
      }
      return false
    }
  }

  const confirmDelete = (company: Company) => {
    companyToDelete.value = company
    deleteConfirmVisible.value = true
  }

  const cancelDelete = () => {
    deleteConfirmVisible.value = false
    companyToDelete.value = null
  }

  const deleteCompany = async (): Promise<boolean> => {
    const targetCompany = requireEntity(companyToDelete.value, 'Company to delete is required')

    try {
      await deleteMutation.run(async () => {
        await companyService.deleteCompanyById(targetCompany.id)
        companiesStore.removeCompany(targetCompany.id)
        return true
      }, {
        sync: 'company',
        onSuccess: () => {
          deleteConfirmVisible.value = false
          companyToDelete.value = null
          notifySuccess(t('companyDeletedSuccess'), t('successSummary'))
        },
      })
      return true
    } catch (error: unknown) {
      console.error(t('deleteError'), error)
      notifyApiError(error, t('deleteError'))
      return false
    }
  }

  return {
    companies,
    loading,
    saving,
    deleting,
    formVisible,
    isEditMode,
    errors,
    formError,
    formData,
    companyToDelete,
    deleteConfirmVisible,
    fetchCompanies,
    openCreateModal,
    openEditModal,
    closeFormModal,
    validateForm,
    submitForm,
    confirmDelete,
    cancelDelete,
    deleteCompany,
  }
}
