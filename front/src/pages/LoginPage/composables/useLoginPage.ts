import { ref, computed } from 'vue'
import { useRouter, useRoute } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useMutation } from '@/composables/async/useMutation'
import { useUserStore } from '@/stores/user'
import { authApi } from '@/api/auth'
import { mapUser } from '@/entities/user'
import { getApiErrorMessage, getValidationErrors } from '@/utils/errors'
import { initEcho } from '@/composables/realtime/echo'

// State machine для 2FA flow
type AuthStep = 'awaitingPassword' | 'awaitingTOTP' | 'done'

export const useLoginPage = () => {
  const { t } = useI18n()
  const router = useRouter()
  const route = useRoute()
  const userStore = useUserStore()

  // State
  const step = ref<AuthStep>('awaitingPassword')
  const email = ref('')
  const password = ref('')
  const totpCode = ref('')
  const backupCode = ref('')
  const useBackupCode = ref(false)
  const tempToken = ref<string | null>(null)
  const fieldErrors = ref<Record<string, string>>({})
  const generalError = ref('')

  const loginMutation = useMutation<void>()
  const twoFactorMutation = useMutation<void>()

  const isLoading = computed(
    () => loginMutation.isPending.value || twoFactorMutation.isPending.value,
  )
  const isAwaitingPassword = computed(() => step.value === 'awaitingPassword')
  const isAwaitingTOTP = computed(() => step.value === 'awaitingTOTP')

  // Validation (inline, без VeeValidate/Zod)
  function validateLoginForm(): boolean {
    fieldErrors.value = {}

    if (!email.value.trim()) {
      fieldErrors.value['email'] = t('auth.login.required_email')
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email.value.trim())) {
      fieldErrors.value['email'] = t('auth.login.invalid_email')
    }

    if (!password.value) {
      fieldErrors.value['password'] = t('auth.login.required_password')
    }

    return Object.keys(fieldErrors.value).length === 0
  }

  function validateTotpForm(): boolean {
    fieldErrors.value = {}

    if (useBackupCode.value) {
      if (!backupCode.value.trim()) {
        fieldErrors.value['backup_code'] = t('auth.two_factor.required_code')
      }
    } else {
      if (!totpCode.value.trim()) {
        fieldErrors.value['totp_code'] = t('auth.two_factor.required_code')
      }
    }

    return Object.keys(fieldErrors.value).length === 0
  }

  // Redirect after successful login
  const afterLoginRedirect = () => {
    const redirect = route.query['redirect']
    const target = typeof redirect === 'string' && redirect ? redirect : '/dashboard'
    step.value = 'done'
    router.replace(target)
  }

  // Step 1: password login
  const handleLogin = async () => {
    if (isLoading.value || !validateLoginForm()) return
    generalError.value = ''

    await loginMutation.run(
      async () => {
        const response = await authApi.login({
          email: email.value.trim(),
          password: password.value,
        })

        if (response.two_factor_required) {
          // 2FA включена — сохраняем temp-токен, переходим к шагу TOTP.
          // Temp-токен кратковременно попадает в persist (через userStore.$patch → token),
          // пока идёт шаг 2FA. При успехе заменяется полным токеном, при отмене — сбрасывается.
          tempToken.value = response.temp_token
          // Временно устанавливаем temp-токен в store для axios (для /api/2fa/validate)
          userStore.setCurrentUser(response.data)
          // Заменяем токен на temp для следующего запроса
          userStore.$patch({ token: response.temp_token })
          step.value = 'awaitingTOTP'
        } else {
          // Полный токен — завершаем логин
          userStore.setAuthenticatedUserState({
            token: response.token,
            user: mapUser(response.data),
          })
          // Инициализируем Echo WebSocket после успешного логина
          initEcho(response.token)
          afterLoginRedirect()
        }
      },
      {
        onError: (error) => {
          const validationErrs = getValidationErrors(error)
          if (validationErrs) {
            fieldErrors.value = validationErrs
          } else {
            generalError.value = getApiErrorMessage(error, t('auth.login.error'))
          }
        },
      },
    )
  }

  // Step 2: TOTP validate
  const handleTotpValidate = async () => {
    if (isLoading.value || !validateTotpForm()) return
    generalError.value = ''

    await twoFactorMutation.run(
      async () => {
        const payload = useBackupCode.value
          ? { backup_code: backupCode.value.trim() }
          : { totp_code: totpCode.value.trim() }

        const response = await authApi.validateTwoFactor(payload)

        // Успех: заменяем temp-токен на полный
        userStore.setAuthenticatedUserState({
          token: response.token,
          user: mapUser(response.data),
        })
        tempToken.value = null
        // Инициализируем Echo WebSocket после успешной верификации 2FA
        initEcho(response.token)
        afterLoginRedirect()
      },
      {
        onError: (error) => {
          const validationErrs = getValidationErrors(error)
          if (validationErrs) {
            fieldErrors.value = validationErrs
          } else {
            generalError.value = getApiErrorMessage(error, t('auth.two_factor.error'))
          }
          // Сбрасываем код при ошибке
          totpCode.value = ''
          backupCode.value = ''
        },
      },
    )
  }

  const backToLogin = () => {
    // Отмена 2FA — очищаем temp-токен и возвращаемся на форму пароля
    userStore.clearAuthenticatedUserState()
    tempToken.value = null
    step.value = 'awaitingPassword'
    totpCode.value = ''
    backupCode.value = ''
    generalError.value = ''
    fieldErrors.value = {}
  }

  const toggleBackupCode = () => {
    useBackupCode.value = !useBackupCode.value
    totpCode.value = ''
    backupCode.value = ''
    fieldErrors.value = {}
    generalError.value = ''
  }

  return {
    // State
    step,
    email,
    password,
    totpCode,
    backupCode,
    useBackupCode,
    fieldErrors,
    generalError,
    isLoading,
    isAwaitingPassword,
    isAwaitingTOTP,
    // Actions
    handleLogin,
    handleTotpValidate,
    backToLogin,
    toggleBackupCode,
  }
}
