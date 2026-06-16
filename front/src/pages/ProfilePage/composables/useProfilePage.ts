import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useUserStore } from '@/stores/user'
import { useMutation } from '@/composables/async/useMutation'
import { authApi } from '@/api/auth'
import { getApiErrorMessage, getValidationErrors } from '@/utils/errors'
import { useToast } from 'primevue/usetoast'

export type ProfileTab =
  | 'profile'
  | 'security'
  | 'notifications'
  | 'locale'
  | 'theme'
  | 'calendar'
  | 'signature'
  | 'segments'
  | 'telegram'
  | 'appearance'
  | 'quickActions'

const VALID_TABS: ProfileTab[] = [
  'profile',
  'security',
  'notifications',
  'locale',
  'theme',
  'calendar',
  'signature',
  'segments',
  'telegram',
  'appearance',
  'quickActions',
]

export const useProfilePage = () => {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()
  const toast = useToast()

  // Active tab — from ?tab= query param
  const activeTab = computed<ProfileTab>(() => {
    const tab = route.query['tab'] as string
    return VALID_TABS.includes(tab as ProfileTab) ? (tab as ProfileTab) : 'profile'
  })

  const setTab = (tab: ProfileTab) => {
    router.replace({ query: { tab } })
  }

  // 2FA Setup flow
  const totpSetupSecret = ref('')
  const totpSetupUri = ref('')
  const totpSetupCode = ref('')
  const totpSetupError = ref('')
  const backupCodes = ref<string[]>([])
  const showBackupCodes = ref(false)

  const setupMutation = useMutation<void>()
  const verifySetupMutation = useMutation<void>()

  const startTotpSetup = async () => {
    totpSetupError.value = ''
    await setupMutation.run(
      async () => {
        const response = await authApi.setupTwoFactor()
        totpSetupSecret.value = response.data.secret
        totpSetupUri.value = response.data.otpauth_uri
      },
      {
        onError: (error) => {
          totpSetupError.value = getApiErrorMessage(error, t('errors.server_error'))
        },
      },
    )
  }

  const verifyTotpSetup = async () => {
    if (!totpSetupCode.value.trim()) {
      totpSetupError.value = t('auth.two_factor.required_code')
      return
    }
    totpSetupError.value = ''

    await verifySetupMutation.run(
      async () => {
        const response = await authApi.verifySetup({
          secret: totpSetupSecret.value,
          totp_code: totpSetupCode.value.trim(),
        })
        backupCodes.value = response.backup_codes
        showBackupCodes.value = true
        // Обновляем пользователя в store
        if (userStore.currentUser) {
          userStore.setCurrentUser({ ...userStore.currentUser, totp_enabled: true })
        }
        totpSetupSecret.value = ''
        totpSetupUri.value = ''
        totpSetupCode.value = ''
      },
      {
        onError: (error) => {
          const validationErrs = getValidationErrors(error)
          if (validationErrs?.['totp_code']) {
            totpSetupError.value = validationErrs['totp_code']
          } else {
            totpSetupError.value = getApiErrorMessage(error, t('auth.two_factor.error'))
          }
          totpSetupCode.value = ''
        },
      },
    )
  }

  const cancelTotpSetup = () => {
    totpSetupSecret.value = ''
    totpSetupUri.value = ''
    totpSetupCode.value = ''
    totpSetupError.value = ''
  }

  // ─── Telegram binding ────────────────────────────────────────────────────────
  const telegramLinking = ref(false)
  const telegramUnlinking = ref(false)
  let telegramPollTimer: ReturnType<typeof setInterval> | null = null
  let telegramPollCount = 0

  const telegramLinked = computed(() => !!userStore.getUser?.telegram_user_id)
  const telegramUsername = computed(() => userStore.getUser?.telegram_user_id ?? null)

  function stopTelegramPoll() {
    if (telegramPollTimer !== null) {
      clearInterval(telegramPollTimer)
      telegramPollTimer = null
      telegramPollCount = 0
    }
  }

  async function refreshUser() {
    try {
      const me = await authApi.me()
      userStore.setCurrentUser(me.data)
    } catch {
      // non-critical
    }
  }

  function startTelegramPoll() {
    stopTelegramPoll()
    telegramPollCount = 0
    telegramPollTimer = setInterval(async () => {
      telegramPollCount++
      await refreshUser()
      if (userStore.getUser?.telegram_user_id) {
        stopTelegramPoll()
        toast.add({
          severity: 'success',
          summary: t('profile.telegram.linkSuccess'),
          life: 4000,
        })
      } else if (telegramPollCount >= 12) {
        // max 60s (12 * 5s)
        stopTelegramPoll()
      }
    }, 5000)
  }

  async function linkTelegram() {
    telegramLinking.value = true
    try {
      const res = await authApi.telegramLink()
      window.open(res.link_url, '_blank')
      toast.add({
        severity: 'info',
        summary: t('profile.telegram.linkHint'),
        life: 6000,
      })
      startTelegramPoll()
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    } finally {
      telegramLinking.value = false
    }
  }

  async function unlinkTelegram() {
    telegramUnlinking.value = true
    try {
      await authApi.telegramUnlink()
      await refreshUser()
      toast.add({ severity: 'info', summary: t('common.saved', 'Сохранено'), life: 2000 })
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    } finally {
      telegramUnlinking.value = false
    }
  }

  return {
    // Tab management
    activeTab,
    setTab,
    tabs: VALID_TABS,

    // User state
    user: computed(() => userStore.getUser),

    // 2FA setup
    totpSetupSecret,
    totpSetupUri,
    totpSetupCode,
    totpSetupError,
    backupCodes,
    showBackupCodes,
    isSettingUpTotp: computed(
      () => setupMutation.isPending.value || verifySetupMutation.isPending.value,
    ),
    isTotpSetupStarted: computed(() => !!totpSetupSecret.value),
    startTotpSetup,
    verifyTotpSetup,
    cancelTotpSetup,

    // Telegram
    telegramLinked,
    telegramUsername,
    telegramLinking,
    telegramUnlinking,
    linkTelegram,
    unlinkTelegram,
  }
}
