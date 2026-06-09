import { computed, reactive, ref, watch, type Ref } from 'vue'
import type { UpdateCompanyRequest } from '@/api/types'
import type { Company } from '@/components/Company'
import type { CompanyFormData, CompanyFormErrors } from '@/components/Company'
import { CURRENCY_CODE_PATTERN } from '@/components/Company/constants'
import { useSessionMutation } from '@/composables/async/useSessionMutation'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import { useCompaniesStore } from '@/stores/companies'
import { useUserStore } from '@/stores/user'
import {
  getApiErrorMessage,
  getApiErrorStatus,
  getApiValidationErrors,
} from '@/utils/errors'
import type { CompanyPageMessages } from './useCompanyPageData'

interface UseCompanySettingsActionsOptions {
  company: Ref<Company | null>
  messages: CompanyPageMessages
  refreshScopedData: () => Promise<void>
}

const emptyCompanyForm = (): CompanyFormData => ({
  id: 0,
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

/**
 * Build the PUT /api/companies/{id} payload. Backend RBAC (set in
 * CompanyController) is the canonical filter, but we whitelist on the
 * frontend too so we never send fields an admin can't actually change —
 * this avoids accidental 422s and keeps the wire-payload small.
 *
 *  - `superadmin`: all fields, including MacroData credentials.
 *  - `admin`: `crm_url`, `currency_code`, `timezone` only (matches backend).
 *  - other roles: route guard already blocks them — defensively returns an
 *    empty payload so a malformed call is harmless.
 */
const toCompanyUpdatePayload = (
  formData: CompanyFormData,
  role: string,
): UpdateCompanyRequest => {
  const trimmedCrmUrl = formData.crm_url.trim()
  const trimmedCurrency = formData.currency_code.trim().toUpperCase()
  const trimmedTimezone = formData.timezone.trim()

  // Admin payload — strictly matches the backend whitelist for non-system
  // edits. Empty string → null, telling the backend to clear the value
  // (formatter then falls back to RUB / UTC).
  if (role === 'admin') {
    return {
      crm_url: trimmedCrmUrl || null,
      currency_code: trimmedCurrency || null,
      timezone: trimmedTimezone || null,
    }
  }

  // Superadmin (or any future role granted full edit) — everything.
  const payload: UpdateCompanyRequest = {
    name: formData.name.trim(),
    crm_url: trimmedCrmUrl || null,
    currency_code: trimmedCurrency || null,
    timezone: trimmedTimezone || null,
  }

  if (formData.macrodata_host) payload.macrodata_host = formData.macrodata_host
  if (formData.macrodata_port) payload.macrodata_port = parseInt(formData.macrodata_port, 10)
  if (formData.macrodata_database) payload.macrodata_database = formData.macrodata_database
  if (formData.macrodata_username) payload.macrodata_username = formData.macrodata_username
  if (formData.macrodata_password) payload.macrodata_password = formData.macrodata_password

  return payload
}

/**
 * Map Laravel validation error bag (`{field: string[]}`) into the form's
 * inline error slots. Backend currently validates `name`, `currency_code`
 * and `timezone` — anything else is ignored here and surfaces through the
 * generic form-level error message.
 */
const applyValidationErrors = (
  bag: Record<string, string[]> | undefined,
  errors: CompanyFormErrors,
) => {
  if (!bag) return
  if (bag.name?.length) errors.name = bag.name[0]
  if (bag.currency_code?.length) errors.currency_code = bag.currency_code[0]
  if (bag.timezone?.length) errors.timezone = bag.timezone[0]
}

export const useCompanySettingsActions = (
  options: UseCompanySettingsActionsOptions,
) => {
  const companiesStore = useCompaniesStore()
  const userStore = useUserStore()
  const { companyService } = useServices()
  const { notifySuccess, notifyError } = useNotifications()
  const companyMutation = useSessionMutation<void>()

  // Reactive role lookup — drives which fields end up in the payload and
  // which inputs are disabled in the modal. Stored as a computed so it
  // stays in sync if the user is re-hydrated (e.g. after profile refresh).
  const userRole = computed(() => userStore.getUserRole)
  const canEditAllFields = computed(() => userRole.value === 'superadmin')

  const companyFormVisible = ref(false)
  const companySaving = companyMutation.isPending
  const companyFormError = ref('')
  const companyFormData = reactive<CompanyFormData>(emptyCompanyForm())
  const companyFormErrors = reactive<CompanyFormErrors>({})

  watch(
    options.company,
    (company) => {
      Object.assign(
        companyFormData,
        company
          ? {
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
            }
          : emptyCompanyForm(),
      )
    },
    { immediate: true },
  )

  const clearErrors = () => {
    companyFormErrors.name = undefined
    companyFormErrors.currency_code = undefined
    companyFormErrors.timezone = undefined
  }

  const openCompanySettings = () => {
    clearErrors()
    companyFormError.value = ''
    companyFormVisible.value = true
  }

  const closeCompanyForm = () => {
    companyFormVisible.value = false
    companyFormError.value = ''
    clearErrors()
  }

  /**
   * Client-side guard — we still rely on the backend regex as the canonical
   * validator, but catching obvious typos here saves a 422 round-trip and
   * gives the user a familiar inline-error UX.
   */
  const runClientValidation = (): boolean => {
    clearErrors()
    let ok = true

    if (!companyFormData.name.trim()) {
      companyFormErrors.name = options.messages.commonError
      ok = false
    }

    if (
      companyFormData.currency_code &&
      !CURRENCY_CODE_PATTERN.test(companyFormData.currency_code.trim().toUpperCase())
    ) {
      companyFormErrors.currency_code = options.messages.currencyInvalid
      ok = false
    }

    return ok
  }

  const submitCompanyForm = async () => {
    companyFormError.value = ''
    clearErrors()

    if (!runClientValidation()) {
      return
    }

    try {
      await companyMutation.run(async () => {
        if (!companyFormData.id) {
          throw new Error('Company id is required for update')
        }

        const updated = await companyService.updateCompanyById(
          companyFormData.id,
          toCompanyUpdatePayload(companyFormData, userRole.value),
        )
        // upsertCompany rewrites the store entry by id — since `useFormatter`
        // reads `companiesStore.getCurrentCompany` reactively, any open
        // report's money/date columns will re-render with the new
        // currency/timezone on the next tick.
        companiesStore.upsertCompany(updated)
      }, {
        // 'company' sync triggers refreshScopedData → re-fetches active
        // company + users; ensures CompanySwitcher label and other
        // company-scoped surfaces pick up the new name/settings too.
        sync: 'company',
        refreshScopedData: options.refreshScopedData,
        onSuccess: () => {
          companyFormVisible.value = false
          notifySuccess(options.messages.companyUpdatedSuccess, options.messages.successSummary)
        },
      })
    } catch (error: unknown) {
      const status = getApiErrorStatus(error)

      if (status === 403) {
        // RBAC failure — the route allows admin, but the backend can still
        // refuse on a per-field basis (e.g. trying to PUT a field outside the
        // admin whitelist). Surface as a toast, leave the form open so the
        // user can retry / cancel.
        notifyError(
          options.messages.forbiddenError,
          options.messages.successSummary,
        )
        return
      }

      if (status === 422) {
        applyValidationErrors(getApiValidationErrors(error), companyFormErrors)
        companyFormError.value = getApiErrorMessage(error, options.messages.commonError)
        return
      }

      // Network / 5xx — show the message inline; toast happens via
      // notifyApiError pattern in other places, but here we keep the error
      // visible inside the modal so the user doesn't lose their inputs.
      companyFormError.value = getApiErrorMessage(error, options.messages.commonError)
    }
  }

  return {
    companyFormVisible,
    companySaving,
    companyFormError,
    companyFormData,
    companyFormErrors,
    /**
     * True only for superadmin. Drives input-disabled state in the modal
     * (name + MacroData credentials are admin-read-only). Backend stays
     * the canonical authority — this flag is UX guidance only.
     */
    canEditAllFields,
    openCompanySettings,
    closeCompanyForm,
    submitCompanyForm,
  }
}
