import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useMutation } from '@/composables/async/useMutation'
import { companiesApi } from '@/api/crm/companies'
import { contactsApi } from '@/api/crm/contacts'
import { getApiErrorMessage } from '@/utils/errors'
import type { Company, Contact, ContactCompanyLink, EmploymentStatus } from '@/entities/crm'

export const useCompanyPageActions = (opts: {
  companyId: { value: number }
  company: { value: Company | null }
  employees: { value: ContactCompanyLink[] }
  loadEmployees: () => Promise<void>
}) => {
  const { t } = useI18n()
  const toast = useToast()
  const confirm = useConfirm()

  const patchMutation = useMutation<Company>()
  const employeeMutation = useMutation<ContactCompanyLink>()

  // Add employee dialog
  const addEmployeeOpen = ref(false)
  const addEmployeeSearch = ref('')
  const addEmployeeContactId = ref<number | null>(null)
  const addEmployeePosition = ref('')
  const addEmployeeStatus = ref<EmploymentStatus>('works')
  const addEmployeeSuggestions = ref<Contact[]>([])
  let searchTimer: ReturnType<typeof setTimeout> | null = null

  async function patchField(fieldKey: string, value: unknown) {
    if (!opts.companyId.value) return

    const previous = opts.company.value ? { ...opts.company.value } : null

    // Optimistic update
    if (opts.company.value) {
      ;(opts.company.value as unknown as Record<string, unknown>)[fieldKey] = value
    }

    await patchMutation.run(
      () => companiesApi.update(opts.companyId.value, { [fieldKey]: value }),
      {
        onSuccess(updated) {
          if (opts.company.value) {
            Object.assign(opts.company.value, updated)
          }
          toast.add({
            severity: 'success',
            summary: t('company.page.inlineEdit.saved'),
            life: 3000,
          })
        },
        onError(err) {
          // Rollback
          if (previous && opts.company.value) {
            Object.assign(opts.company.value, previous)
          }
          toast.add({
            severity: 'error',
            summary: t('company.page.inlineEdit.error'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        },
      },
    )
  }

  function openAddEmployee() {
    addEmployeeOpen.value = true
    addEmployeeSearch.value = ''
    addEmployeeContactId.value = null
    addEmployeePosition.value = ''
    addEmployeeStatus.value = 'works'
    addEmployeeSuggestions.value = []
  }

  function closeAddEmployee() {
    addEmployeeOpen.value = false
  }

  function searchEmployeeContacts(query: string) {
    if (searchTimer) clearTimeout(searchTimer)
    if (!query || query.length < 2) {
      addEmployeeSuggestions.value = []
      return
    }
    searchTimer = setTimeout(async () => {
      try {
        const result = await contactsApi.list({ search: query, per_page: 10 })
        addEmployeeSuggestions.value = result.data
      } catch {
        addEmployeeSuggestions.value = []
      }
    }, 300)
  }

  function onEmployeeSelect(contact: Contact) {
    addEmployeeContactId.value = contact.id
  }

  async function submitAddEmployee() {
    if (!addEmployeeContactId.value || !opts.companyId.value) return

    await employeeMutation.run(
      () =>
        companiesApi.attachEmployee(opts.companyId.value, {
          contact_id: addEmployeeContactId.value!,
          position: addEmployeePosition.value || undefined,
          employment_status: addEmployeeStatus.value,
        }),
      {
        onSuccess() {
          addEmployeeOpen.value = false
          void opts.loadEmployees()
          toast.add({
            severity: 'success',
            summary: t('company.page.employees.addSuccess', 'Сотрудник добавлен'),
            life: 3000,
          })
        },
        onError(err) {
          toast.add({
            severity: 'error',
            summary: t('errors.server_error'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        },
      },
    )
  }

  async function setPrimaryEmployee(contactId: number) {
    await companiesApi.setPrimaryEmployee(contactId, opts.companyId.value)
    await opts.loadEmployees()
    toast.add({ severity: 'success', summary: t('company.page.employees.actions.setPrimary', 'Обновлено'), life: 3000 })
  }

  async function toggleEmployeeStatus(contactId: number, current: EmploymentStatus) {
    const next: EmploymentStatus = current === 'works' ? 'left' : 'works'
    await companiesApi.updateEmployeeLink(opts.companyId.value, contactId, {
      employment_status: next,
    })
    // Optimistic update
    const emp = opts.employees.value.find(
      (e) => e.contact_id === contactId,
    )
    if (emp) emp.employment_status = next
    toast.add({ severity: 'success', summary: t('company.page.employees.actions.changeStatus', 'Статус обновлён'), life: 3000 })
  }

  function confirmUnlinkEmployee(contactId: number) {
    confirm.require({
      message: t('company.page.employees.unlinkConfirm'),
      header: t('common.confirm'),
      icon: 'pi pi-exclamation-triangle',
      acceptClass: 'p-button-danger',
      accept: async () => {
        await companiesApi.detachEmployee(opts.companyId.value, contactId)
        opts.employees.value = opts.employees.value.filter((e) => e.contact_id !== contactId)
        toast.add({ severity: 'success', summary: t('contacts.page.delete.success'), life: 3000 })
      },
    })
  }

  return {
    patchField,
    isSaving: patchMutation.isPending,
    addEmployeeOpen,
    addEmployeeSearch,
    addEmployeeContactId,
    addEmployeePosition,
    addEmployeeStatus,
    addEmployeeSuggestions,
    isAddingEmployee: employeeMutation.isPending,
    openAddEmployee,
    closeAddEmployee,
    submitAddEmployee,
    searchEmployeeContacts,
    onEmployeeSelect,
    setPrimaryEmployee,
    toggleEmployeeStatus,
    confirmUnlinkEmployee,
  }
}
