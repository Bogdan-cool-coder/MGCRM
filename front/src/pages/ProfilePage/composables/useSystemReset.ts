import { ref, computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useToast } from 'primevue/usetoast'
import { useMutation } from '@/composables/async/useMutation'
import { systemApi, SYSTEM_RESET_CONFIRMATION } from '@/api/system'
import { useUserStore } from '@/stores/user'
import { getApiErrorMessage } from '@/utils/errors'

/**
 * Phrase the user must type verbatim to enable the reset button.
 * Must match exactly what the backend validates (Rule::in).
 * Exported for use in SystemResetDialog as a prop default.
 */
export const RESET_CONFIRM_PHRASE = SYSTEM_RESET_CONFIRMATION

export const useSystemReset = () => {
  const { t } = useI18n()
  const router = useRouter()
  const toast = useToast()
  const userStore = useUserStore()

  const dialogVisible = ref(false)
  const confirmInput = ref('')

  const isConfirmed = computed(
    () => confirmInput.value.trim() === RESET_CONFIRM_PHRASE,
  )

  const resetMutation = useMutation<void>()

  function openDialog() {
    confirmInput.value = ''
    dialogVisible.value = true
  }

  function closeDialog() {
    confirmInput.value = ''
    dialogVisible.value = false
  }

  async function executeReset() {
    if (!isConfirmed.value) return

    await resetMutation.run(
      async () => {
        const result = await systemApi.resetDatabase()

        toast.add({
          severity: 'success',
          summary: t('system.reset.success_title'),
          detail: t('system.reset.success_detail'),
          life: 5000,
        })

        closeDialog()

        if (result.requires_relogin) {
          // Учётки пересоздаются → нужно переloginiться
          userStore.clearAuthenticatedUserState()
          await router.push({
            path: '/login',
            query: { reason: 'reset' },
          })
        }
      },
      {
        onError: (error) => {
          toast.add({
            severity: 'error',
            summary: t('system.reset.error_title'),
            detail: getApiErrorMessage(error, t('errors.server_error')),
            life: 6000,
          })
        },
      },
    )
  }

  return {
    dialogVisible,
    confirmInput,
    isConfirmed,
    isPending: resetMutation.isPending,
    openDialog,
    closeDialog,
    executeReset,
    RESET_CONFIRM_PHRASE,
  }
}
