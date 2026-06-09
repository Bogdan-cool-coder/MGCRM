import { computed, reactive, ref } from 'vue'
import type { CreateUserRequest, UpdateUserRequest } from '@/api/types'
import type { User } from '@/entities/user'
import { requireActiveCompanyId } from '@/shared/session/guards'
import { useCompaniesStore } from '@/stores/companies'
import { useUserStore } from '@/stores/user'
import { useMutation } from '@/composables/async/useMutation'
import { useSessionMutation } from '@/composables/async/useSessionMutation'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import { canManageUserIframe as canManageUserIframeCapability } from '@/shared/auth/capabilities'
import { getApiErrorMessage, getApiErrorStatus, getApiValidationErrors } from '@/utils/errors'
import type { CompanyPageMessages } from './useCompanyPageData'

interface UseCompanyUserFormActionsOptions {
  messages: CompanyPageMessages
  refreshScopedData: () => Promise<void>
}

type UserFormData = {
  id: number
  name: string
  email: string
  password: string
  role: string
  company_id: number
  locale: string
}

export const useCompanyUserFormActions = (
  options: UseCompanyUserFormActionsOptions,
) => {
  const companiesStore = useCompaniesStore()
  const userStore = useUserStore()
  const { userService } = useServices()
  const { notifyError, notifyInfo, notifySuccess } = useNotifications()
  const userMutation = useSessionMutation<{ mode: 'create' | 'update'; affectsSession: boolean }>()
  const iframeMutation = useMutation<string>()

  const userFormVisible = ref(false)
  const userFormEditMode = ref(false)
  const userSaving = userMutation.isPending
  const userFormError = ref('')
  const userIframeUrl = ref('')
  const iframeRegenerating = ref(false)
  const userFormData = reactive<UserFormData>({
    id: 0,
    name: '',
    email: '',
    password: '',
    role: '',
    company_id: 0,
    locale: 'ru',
  })
  const userFormErrors = reactive<{ name?: string; email?: string }>({})

  const currentUserRole = computed(() => userStore.getUser?.role ?? null)
  const canManageUserIframe = computed(() => {
    return (
      !!userFormData.id &&
      canManageUserIframeCapability(currentUserRole.value, userFormData.role)
    )
  })

  const applyUserToForm = (
    user: Pick<User, 'id' | 'name' | 'email' | 'role' | 'company_id' | 'locale'>,
  ) => {
    Object.assign(userFormData, {
      id: user.id,
      name: user.name,
      email: user.email,
      password: '',
      role: user.role,
      company_id: user.company_id,
      locale: user.locale || 'ru',
    })
  }

  const clearIframeState = () => {
    userIframeUrl.value = ''
    iframeRegenerating.value = false
  }

  const resetUserFormState = () => {
    clearIframeState()
    userFormErrors.name = ''
    userFormErrors.email = ''
    userFormError.value = ''
  }

  const loadUserIframeLink = async (userId: number) => {
    try {
      const iframeUrl = await iframeMutation.run(async () => {
        const response = await userService.fetchIframeLink(userId)
        return response.iframe_url ?? ''
      }, {
        onSuccess: (nextUrl) => {
          userIframeUrl.value = nextUrl
        },
      })

      return iframeUrl
    } catch {
      return ''
    }
  }

  const openCreateUserModal = () => {
    const activeCompanyId = requireActiveCompanyId(companiesStore.getActiveCompanyId)

    userFormEditMode.value = false
    Object.assign(userFormData, {
      id: 0,
      name: '',
      email: '',
      password: '',
      role: 'analyst',
      company_id: activeCompanyId,
      locale: 'ru',
    })
    resetUserFormState()
    userFormVisible.value = true
  }

  const openEditUserModal = (user: User) => {
    userFormEditMode.value = true
    applyUserToForm(user)
    resetUserFormState()
    userFormVisible.value = true

    if (currentUserRole.value === 'superadmin' && user.role !== 'superadmin') {
      void loadUserIframeLink(user.id).catch((error: unknown) => {
        userFormError.value = getApiErrorMessage(error, options.messages.commonError)
      })
    }
  }

  const closeUserForm = () => {
    userFormVisible.value = false
    userFormError.value = ''
    clearIframeState()
    iframeMutation.reset()
    Object.assign(userFormErrors, {})
  }

  const submitUserForm = async () => {
    userFormError.value = ''

    try {
      await userMutation.run(async () => {
        const payload: UpdateUserRequest = {
          name: userFormData.name.trim(),
          email: userFormData.email.trim(),
          role: userFormData.role,
          company_id: userFormData.company_id,
          locale: userFormData.locale,
        }

        if (userFormData.password) {
          payload.password = userFormData.password
        }

        if (userFormEditMode.value && userFormData.id) {
          const updatedUser = await userService.updateUserById(userFormData.id, payload)
          applyUserToForm(updatedUser)
          if (canManageUserIframe.value) {
            await loadUserIframeLink(userFormData.id)
          }
          return {
            mode: 'update' as const,
            affectsSession: updatedUser.id === userStore.getUser?.id,
          }
        }

        const createPayload: CreateUserRequest = {
          name: userFormData.name.trim(),
          email: userFormData.email.trim(),
          role: userFormData.role,
          company_id: userFormData.company_id,
          locale: userFormData.locale,
          password: userFormData.password || 'TempPass123!',
        }
        const createdUser = await userService.createUser(createPayload)
        applyUserToForm(createdUser)
        userFormEditMode.value = true
        await options.refreshScopedData()

        if (currentUserRole.value === 'superadmin' && createdUser.role !== 'superadmin') {
          await loadUserIframeLink(createdUser.id)
          notifyInfo(options.messages.userIframeReady, options.messages.successSummary)
        }

        return {
          mode: 'create' as const,
          affectsSession: false,
        }
      }, {
        sync: 'user',
        affectsSession: (result) => result.affectsSession,
        refreshScopedData: options.refreshScopedData,
        onSuccess: (result) => {
          if (result.mode === 'update') {
            userFormVisible.value = false
            notifySuccess(options.messages.userUpdatedSuccess, options.messages.successSummary)
            return
          }

          notifySuccess(options.messages.userCreatedSuccess, options.messages.successSummary)
        },
      })
    } catch (error: unknown) {
      if (getApiErrorStatus(error) === 422) {
        const errors = getApiValidationErrors(error) || {}
        if (errors.name) userFormErrors.name = errors.name[0]
        if (errors.email) userFormErrors.email = errors.email[0]
      }

      userFormError.value = getApiErrorMessage(error, options.messages.commonError)
    }
  }

  const copyUserIframeLink = async () => {
    if (!userFormData.id || !canManageUserIframe.value) return

    try {
      const iframeUrl = userIframeUrl.value || (await loadUserIframeLink(userFormData.id))

      if (!iframeUrl) {
        notifyError(options.messages.userIframeUnavailable, options.messages.commonError)
        return
      }

      await navigator.clipboard.writeText(iframeUrl)
      notifySuccess(options.messages.userIframeCopiedSuccess, options.messages.successSummary)
    } catch (error: unknown) {
      userFormError.value = getApiErrorMessage(error, options.messages.commonError)
    }
  }

  const regenerateUserIframeLink = async () => {
    if (!userFormData.id || !canManageUserIframe.value) return

    const confirmed = window.confirm(options.messages.userIframeRegenerateConfirm)
    if (!confirmed) return

    iframeRegenerating.value = true

    try {
      const response = await userService.regenerateIframeLink(userFormData.id)
      userIframeUrl.value = response.iframe_url ?? ''
      notifySuccess(
        options.messages.userIframeRegeneratedSuccess,
        options.messages.successSummary,
      )
    } catch (error: unknown) {
      userFormError.value = getApiErrorMessage(error, options.messages.commonError)
    } finally {
      iframeRegenerating.value = false
    }
  }

  return {
    userFormVisible,
    userFormEditMode,
    userSaving,
    userFormError,
    userFormData,
    userFormErrors,
    userIframeUrl,
    iframeLoading: iframeMutation.isPending,
    iframeRegenerating,
    canManageUserIframe,
    openCreateUserModal,
    openEditUserModal,
    closeUserForm,
    submitUserForm,
    copyUserIframeLink,
    regenerateUserIframeLink,
  }
}
