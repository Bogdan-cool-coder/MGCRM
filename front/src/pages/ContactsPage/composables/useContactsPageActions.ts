import { ref } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useMutation } from '@/composables/async/useMutation'
import { contactsApi, type CreateContactPayload } from '@/api/crm/contacts'
import { companiesApi, type CreateCompanyPayload } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { EntityType } from './useContactsPageData'
import type { Contact, Company } from '@/entities/crm'

export interface QuickCreateContactForm {
  full_name: string
  phone: string
  email: string
  source: string | null
}

export interface QuickCreateCompanyForm {
  name: string
  legal_form: string
  tax_id: string
  company_type_id: number | null
  country_code: string | null
  source: string | null
  holding_id: number | null
  responsible_user_id: number | null
}

const DEFAULT_CONTACT_FORM: QuickCreateContactForm = {
  full_name: '',
  phone: '',
  email: '',
  source: null,
}

const DEFAULT_COMPANY_FORM: QuickCreateCompanyForm = {
  name: '',
  legal_form: '',
  tax_id: '',
  company_type_id: null,
  country_code: null,
  source: null,
  holding_id: null,
  responsible_user_id: null,
}

export const useContactsPageActions = (opts: {
  reload: () => Promise<void>
  entityType: { value: EntityType }
}) => {
  const { t } = useI18n()
  const toast = useToast()
  const router = useRouter()

  // Quick-create drawer
  const quickCreateOpen = ref(false)
  const quickCreateType = ref<EntityType>('contact')
  const contactForm = ref<QuickCreateContactForm>({ ...DEFAULT_CONTACT_FORM })
  const companyForm = ref<QuickCreateCompanyForm>({ ...DEFAULT_COMPANY_FORM })
  const formErrors = ref<Record<string, string>>({})

  // Dedup dialog
  const dedupOpen = ref(false)

  // ── Single-item delete dialog (NOT useConfirm — phantom-on-route-leave bug) ──
  const deleteOpen = ref(false)
  const deleteLoading = ref(false)
  const deleteTarget = ref<{ id: number; type: EntityType; label: string } | null>(null)

  const deleteHeader = ref('')
  const deleteMessage = ref('')

  function confirmDelete(item: { id: number; name?: string; full_name?: string }, type: EntityType) {
    const name = item.full_name ?? item.name ?? ''
    deleteTarget.value = { id: item.id, type, label: name }
    deleteHeader.value = t('contacts.page.delete.confirm') + (name ? ` "${name}"` : '')
    deleteMessage.value = t('contacts.page.delete.detail')
    deleteOpen.value = true
  }

  async function executeDelete() {
    if (!deleteTarget.value) return
    deleteLoading.value = true
    try {
      if (deleteTarget.value.type === 'company') {
        await companiesApi.remove(deleteTarget.value.id)
      } else {
        await contactsApi.remove(deleteTarget.value.id)
      }
      toast.add({
        severity: 'success',
        summary: t('contacts.page.delete.success'),
        life: 4000,
      })
      deleteTarget.value = null
      void opts.reload()
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('contacts.page.errors.delete'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      // Always close the dialog and reset loading — regardless of success or error.
      // Closing in finally (not try) guarantees the dialog dismisses even when the
      // API call throws. Loading is reset AFTER the close flag so PrimeVue Dialog
      // starts its leave-transition without a spinner-flash on the next frame.
      deleteOpen.value = false
      deleteLoading.value = false
    }
  }

  const createMutation = useMutation<Contact | Company>()

  function openQuickCreate() {
    quickCreateType.value = opts.entityType.value
    contactForm.value = { ...DEFAULT_CONTACT_FORM }
    companyForm.value = { ...DEFAULT_COMPANY_FORM }
    formErrors.value = {}
    quickCreateOpen.value = true
  }

  function closeQuickCreate() {
    quickCreateOpen.value = false
    formErrors.value = {}
  }

  function openDedup() {
    dedupOpen.value = true
  }

  function validateContactForm(): boolean {
    const errors: Record<string, string> = {}
    if (!contactForm.value.full_name.trim()) {
      errors['full_name'] = t('contacts.page.quickCreate.errors.nameRequired', 'Введите ФИО')
    }
    formErrors.value = errors
    return Object.keys(errors).length === 0
  }

  function validateCompanyForm(): boolean {
    const errors: Record<string, string> = {}
    if (!companyForm.value.name.trim()) {
      errors['name'] = t('contacts.page.quickCreate.errors.nameRequired', 'Введите название')
    }
    formErrors.value = errors
    return Object.keys(errors).length === 0
  }

  async function submitQuickCreate() {
    if (quickCreateType.value === 'contact') {
      if (!validateContactForm()) return
      const payload: CreateContactPayload = {
        full_name: contactForm.value.full_name.trim(),
        phone: contactForm.value.phone || undefined,
        email: contactForm.value.email || undefined,
        source: contactForm.value.source ?? undefined,
      }
      await createMutation.run(() => contactsApi.create(payload), {
        onSuccess() {
          toast.add({
            severity: 'success',
            summary: t('contacts.page.quickCreate.success', 'Контакт создан'),
            life: 4000,
          })
          quickCreateOpen.value = false
          void opts.reload()
        },
        onError(err) {
          toast.add({
            severity: 'error',
            summary: t('contacts.page.errors.create'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        },
      })
    } else {
      if (!validateCompanyForm()) return
      const payload: CreateCompanyPayload = {
        name: companyForm.value.name.trim(),
        legal_form: companyForm.value.legal_form || undefined,
        tax_id: companyForm.value.tax_id || undefined,
        company_type_id: companyForm.value.company_type_id ?? undefined,
        country_code: companyForm.value.country_code ?? undefined,
        source: companyForm.value.source ?? undefined,
        holding_id: companyForm.value.holding_id ?? undefined,
        responsible_user_id: companyForm.value.responsible_user_id ?? undefined,
      }
      await createMutation.run(() => companiesApi.create(payload), {
        onSuccess() {
          toast.add({
            severity: 'success',
            summary: t('contacts.page.quickCreate.success', 'Компания создана'),
            life: 4000,
          })
          quickCreateOpen.value = false
          void opts.reload()
        },
        onError(err) {
          toast.add({
            severity: 'error',
            summary: t('contacts.page.errors.create'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        },
      })
    }
  }

  function openCard(item: { id: number }, type: EntityType) {
    if (type === 'company') {
      void router.push(`/companies/${item.id}`)
    } else {
      void router.push(`/contacts/${item.id}`)
    }
  }

  return {
    quickCreateOpen,
    quickCreateType,
    contactForm,
    companyForm,
    formErrors,
    dedupOpen,
    isCreating: createMutation.isPending,
    // single-item delete dialog
    deleteOpen,
    deleteLoading,
    deleteHeader,
    deleteMessage,
    openQuickCreate,
    closeQuickCreate,
    openDedup,
    submitQuickCreate,
    openCard,
    confirmDelete,
    executeDelete,
  }
}
