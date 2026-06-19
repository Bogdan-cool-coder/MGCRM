import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useConfirm } from 'primevue/useconfirm'
import { useRouter } from 'vue-router'
import { useMutation } from '@/composables/async/useMutation'
import { contactsApi } from '@/api/crm/contacts'
import { getApiErrorMessage } from '@/utils/errors'
import type { ContactExtended, ContactCompanyLink, ContactRelation, ContactChannel } from '@/entities/crm'

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
  const confirm = useConfirm()
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
  const attachCompanySearch = ref('')
  const attachCompanyId = ref<number | null>(null)
  const attachCompanyPosition = ref('')
  const attachCompanyStatus = ref<'works' | 'left'>('works')

  function openAttachCompany() {
    attachCompanyOpen.value = true
    attachCompanySearch.value = ''
    attachCompanyId.value = null
    attachCompanyPosition.value = ''
    attachCompanyStatus.value = 'works'
  }

  function closeAttachCompany() {
    attachCompanyOpen.value = false
  }

  async function submitAttachCompany() {
    if (!attachCompanyId.value || !opts.contactId.value) return
    await linkMutation.run(
      () =>
        contactsApi.attachCompany(opts.contactId.value, {
          company_id: attachCompanyId.value!,
          position: attachCompanyPosition.value || undefined,
          employment_status: attachCompanyStatus.value,
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

  function confirmDetachCompany(companyId: number) {
    confirm.require({
      message: t('contact.page.companies.unlinkConfirm'),
      header: t('common.confirm'),
      icon: 'pi pi-exclamation-triangle',
      acceptClass: 'p-button-danger',
      accept: async () => {
        await contactsApi.detachCompany(opts.contactId.value, companyId)
        opts.companies.value = opts.companies.value.filter((c) => c.company_id !== companyId)
        toast.add({ severity: 'success', summary: t('contacts.page.delete.success'), life: 3000 })
      },
    })
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

  // ── Delete contact ────────────────────────────────────────────────────────

  function confirmDeleteContact() {
    confirm.require({
      message: t('crm.contact.deleteConfirm'),
      header: t('common.confirm'),
      icon: 'pi pi-exclamation-triangle',
      acceptClass: 'p-button-danger',
      accept: async () => {
        try {
          await contactsApi.remove(opts.contactId.value)
          toast.add({ severity: 'success', summary: t('crm.contact.deleted'), life: 3000 })
          void router.push('/contacts')
        } catch (err) {
          toast.add({
            severity: 'error',
            summary: t('errors.server_error'),
            detail: getApiErrorMessage(err, t('errors.server_error')),
            life: 4000,
          })
        }
      },
    })
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
    isAttaching: linkMutation.isPending,
    openAttachCompany,
    closeAttachCompany,
    submitAttachCompany,
    setPrimaryCompany,
    confirmDetachCompany,
    onChannelsUpdated,
    onRelationsUpdated,
    confirmDeleteContact,
    copyLink,
  }
}
