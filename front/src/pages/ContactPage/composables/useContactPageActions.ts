import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useRouter } from 'vue-router'
import { useMutation } from '@/composables/async/useMutation'
import { contactsApi } from '@/api/crm/contacts'
import { companiesApi } from '@/api/crm/companies'
import { getApiErrorMessage } from '@/utils/errors'
import type { ContactExtended, ContactCompanyLink, ContactRelation, ContactChannel, Company } from '@/entities/crm'

export const useContactPageActions = (opts: {
  contactId: { value: number }
  contact: { value: ContactExtended | null }
  companies: { value: ContactCompanyLink[] }
  relations: { value: ContactRelation[] }
  loadCompanies: () => Promise<void>
  loadRelations: () => Promise<void>
}) => {
  const { t } = useI18n()
  const toast = useToast()
  const router = useRouter()

  const patchMutation = useMutation<ContactExtended>()
  const linkMutation = useMutation<ContactCompanyLink>()

  // ── Inline field save ─────────────────────────────────────────────────────

  async function patchField(fieldKey: string, value: unknown) {
    if (!opts.contactId.value) return
    const previous = opts.contact.value ? { ...opts.contact.value } : null

    if (opts.contact.value) {
      ;(opts.contact.value as unknown as Record<string, unknown>)[fieldKey] = value
    }

    await patchMutation.run(
      () => contactsApi.update(opts.contactId.value, { [fieldKey]: value }) as Promise<ContactExtended>,
      {
        onSuccess(updated) {
          if (opts.contact.value) Object.assign(opts.contact.value, updated)
          toast.add({ severity: 'success', summary: t('company.page.inlineEdit.saved'), life: 3000 })
        },
        onError(err) {
          if (previous && opts.contact.value) Object.assign(opts.contact.value, previous)
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

  async function saveExtraField(code: string, value: unknown) {
    if (!opts.contact.value) return
    const extra = { ...(opts.contact.value.extra_fields ?? {}), [code]: value }
    await patchField('extra_fields', extra)
  }

  // ── Attach company dialog ─────────────────────────────────────────────────

  const attachCompanyOpen = ref(false)
  const attachCompanySearch = ref<string | Company>('')
  const attachCompanyId = ref<number | null>(null)
  const attachCompanyPosition = ref('')
  const attachCompanyStatus = ref<'works' | 'left'>('works')
  const attachCompanySuggestions = ref<Company[]>([])
  let attachCompanyTimer: ReturnType<typeof setTimeout> | null = null

  function openAttachCompany() {
    attachCompanyOpen.value = true
    attachCompanySearch.value = ''
    attachCompanyId.value = null
    attachCompanyPosition.value = ''
    attachCompanyStatus.value = 'works'
    attachCompanySuggestions.value = []
  }

  function closeAttachCompany() {
    attachCompanyOpen.value = false
  }

  function searchAttachCompany(query: string) {
    if (attachCompanyTimer) clearTimeout(attachCompanyTimer)
    if (!query || query.length < 2) {
      attachCompanySuggestions.value = []
      return
    }
    attachCompanyTimer = setTimeout(async () => {
      try {
        const result = await companiesApi.list({ search: query, per_page: 10 })
        attachCompanySuggestions.value = result.data
      } catch {
        attachCompanySuggestions.value = []
      }
    }, 300)
  }

  function onAttachCompanySelect(company: Company) {
    attachCompanyId.value = company.id
  }

  async function submitAttachCompany(isPrimary = false) {
    if (!attachCompanyId.value || !opts.contactId.value) return
    await linkMutation.run(
      () =>
        contactsApi.attachCompany(opts.contactId.value, {
          company_id: attachCompanyId.value!,
          position: attachCompanyPosition.value || undefined,
          employment_status: attachCompanyStatus.value,
          is_primary: isPrimary || undefined,
        }),
      {
        onSuccess() {
          attachCompanyOpen.value = false
          void opts.loadCompanies()
          toast.add({ severity: 'success', summary: t('contact.page.companies.add'), life: 3000 })
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

  async function setPrimaryCompany(companyId: number) {
    await contactsApi.setPrimaryCompany(opts.contactId.value, companyId)
    await opts.loadCompanies()
    toast.add({ severity: 'success', summary: t('contact.page.companies.actions.setPrimary'), life: 3000 })
  }

  // ── Detach company — local dialog (NOT ConfirmService) ───────────────────
  // Reason: ConfirmService leaves phantom dialogs on route-leave (PrimeVue bug).
  const detachCompanyDialogOpen = ref(false)
  const detachCompanyLoading = ref(false)
  const pendingDetachCompanyId = ref<number | null>(null)

  function confirmDetachCompany(companyId: number) {
    pendingDetachCompanyId.value = companyId
    detachCompanyDialogOpen.value = true
  }

  async function executeDetachCompany() {
    if (!pendingDetachCompanyId.value) return
    detachCompanyLoading.value = true
    try {
      await contactsApi.detachCompany(opts.contactId.value, pendingDetachCompanyId.value)
      opts.companies.value = opts.companies.value.filter((c) => c.company_id !== pendingDetachCompanyId.value)
      detachCompanyDialogOpen.value = false
      toast.add({ severity: 'success', summary: t('contacts.page.delete.success'), life: 3000 })
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      detachCompanyLoading.value = false
      pendingDetachCompanyId.value = null
    }
  }

  // ── Channels (inline mutations from child component) ──────────────────────

  function onChannelsUpdated(updated: ContactChannel[]) {
    if (opts.contact.value) {
      opts.contact.value.channels = updated
    }
  }

  // ── Relations (inline mutations from child component) ─────────────────────

  function onRelationsUpdated(updated: ContactRelation[]) {
    opts.relations.value = updated
  }

  // ── Delete contact — local dialog (NOT ConfirmService) ───────────────────
  // ConfirmService persists its reactive state across navigation: the confirm
  // popup would appear as a phantom on the /contacts list after redirect.
  const deleteContactDialogOpen = ref(false)
  const deleteContactLoading = ref(false)

  function confirmDeleteContact() {
    deleteContactDialogOpen.value = true
  }

  async function executeDeleteContact() {
    deleteContactLoading.value = true
    try {
      await contactsApi.remove(opts.contactId.value)
      // Close dialog first, then navigate — no phantom on destination.
      deleteContactDialogOpen.value = false
      toast.add({ severity: 'success', summary: t('crm.contact.deleted'), life: 3000 })
      void router.push('/contacts')
    } catch (err) {
      toast.add({
        severity: 'error',
        summary: t('errors.server_error'),
        detail: getApiErrorMessage(err, t('errors.server_error')),
        life: 4000,
      })
    } finally {
      deleteContactLoading.value = false
    }
  }

  // ── Copy link ─────────────────────────────────────────────────────────────

  function copyLink() {
    const url = window.location.href
    void navigator.clipboard.writeText(url)
    toast.add({ severity: 'success', summary: t('common.linkCopied'), life: 2000 })
  }

  return {
    patchField,
    saveExtraField,
    isSaving: patchMutation.isPending,
    attachCompanyOpen,
    attachCompanySearch,
    attachCompanyId,
    attachCompanyPosition,
    attachCompanyStatus,
    attachCompanySuggestions,
    isAttaching: linkMutation.isPending,
    openAttachCompany,
    closeAttachCompany,
    searchAttachCompany,
    onAttachCompanySelect,
    submitAttachCompany,
    setPrimaryCompany,
    // Detach company dialog state
    detachCompanyDialogOpen,
    detachCompanyLoading,
    confirmDetachCompany,
    executeDetachCompany,
    // Delete contact dialog state
    deleteContactDialogOpen,
    deleteContactLoading,
    confirmDeleteContact,
    executeDeleteContact,
    onChannelsUpdated,
    onRelationsUpdated,
    copyLink,
  }
}
