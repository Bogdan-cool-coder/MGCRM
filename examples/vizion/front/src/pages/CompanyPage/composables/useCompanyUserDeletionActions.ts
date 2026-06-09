import { ref, type Ref } from 'vue'
import type { User } from '@/entities/user'
import { requireEntity } from '@/shared/session/guards'
import { useUserStore } from '@/stores/user'
import { useSessionMutation } from '@/composables/async/useSessionMutation'
import { useNotifications } from '@/composables/useNotifications'
import { useServices } from '@/services'
import { canDeleteUser as canDeleteUserCapability } from '@/shared/auth/capabilities'
import type { CompanyPageMessages } from './useCompanyPageData'

interface UseCompanyUserDeletionActionsOptions {
  messages: CompanyPageMessages
  users: Ref<User[]>
  refreshScopedData: () => Promise<void>
}

export const useCompanyUserDeletionActions = (
  options: UseCompanyUserDeletionActionsOptions,
) => {
  const userStore = useUserStore()
  const { userService } = useServices()
  const { notifyApiError, notifySuccess } = useNotifications()
  const deleteUserMutation = useSessionMutation<{ affectsSession: boolean }>()

  const deleteConfirmVisible = ref(false)
  const userDeleting = deleteUserMutation.isPending
  const userToDelete = ref<User | null>(null)

  const canDeleteUser = (user: User) => {
    return canDeleteUserCapability(userStore.getUser, user)
  }

  const hasUserInList = (userId: number) => {
    return options.users.value.some((user) => user.id === userId)
  }

  const confirmDeleteUser = (user: User) => {
    if (!canDeleteUser(user)) return
    userToDelete.value = user
    deleteConfirmVisible.value = true
  }

  const cancelDeleteUser = () => {
    deleteConfirmVisible.value = false
    userToDelete.value = null
  }

  const deleteUser = async () => {
    const targetUser = requireEntity(userToDelete.value, 'User to delete is required')

    if (!hasUserInList(targetUser.id) || !canDeleteUser(targetUser)) {
      deleteConfirmVisible.value = false
      userToDelete.value = null
      return
    }

    try {
      await deleteUserMutation.run(async () => {
        await userService.deleteUserById(targetUser.id)
        return {
          affectsSession: targetUser.id === userStore.getUser?.id,
        }
      }, {
        sync: 'user',
        affectsSession: (result) => result.affectsSession,
        refreshScopedData: options.refreshScopedData,
        onSuccess: () => {
          deleteConfirmVisible.value = false
          userToDelete.value = null
          notifySuccess(options.messages.userDeletedSuccess, options.messages.successSummary)
        },
      })
    } catch (error: unknown) {
      console.error('Failed to delete user', error)
      notifyApiError(error, options.messages.networkError)
    }
  }

  return {
    deleteConfirmVisible,
    userDeleting,
    userToDelete,
    canDeleteUser,
    confirmDeleteUser,
    cancelDeleteUser,
    deleteUser,
  }
}
