import { ref, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useUserStore } from '@/stores/user'
import { useMutation } from '@/composables/async/useMutation'
import { authApi } from '@/api/auth'
import { profileApi } from '@/api/profile'
import { localeManager } from '@/application/locale'
import type { AvailableLocales } from '@/plugins/i18n'
import { getApiErrorMessage, getValidationErrors } from '@/utils/errors'
import { useToast } from 'primevue/usetoast'

export type ProfileTab =
  | 'profile'
  | 'security'
  | 'notifications'
  | 'locale'
  | 'calendar'
  | 'signature'
  | 'segments'
  | 'telegram'
  | 'appearance'
  | 'quickActions'
  | 'system'

const VALID_TABS: ProfileTab[] = [
  'profile',
  'security',
  'notifications',
  'locale',
  'calendar',
  'signature',
  'segments',
  'telegram',
  'appearance',
  'quickActions',
  'system',
]

export const useProfilePage = () => {
  const { t } = useI18n()
  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()
  const toast = useToast()

  // 'system' is admin-only — non-admins requesting ?tab=system fall back to the
  // hub so the danger card never renders for them (the BE Gate already blocks
  // the reset request; this also hides the trigger UI).
  const isAdmin = computed(() => userStore.getUserRole === 'admin')

  // Active tab — from ?tab= query param; no ?tab → 'hub' (card grid)
  const activeTab = computed<ProfileTab | 'hub'>(() => {
    const tab = route.query['tab'] as string
    if (!tab) return 'hub'
    if (tab === 'system' && !isAdmin.value) return 'hub'
    return VALID_TABS.includes(tab as ProfileTab) ? (tab as ProfileTab) : 'hub'
  })

  const setTab = (tab: ProfileTab | 'hub') => {
    if (tab === 'hub') {
      router.replace({ path: '/profile', query: {} })
    } else {
      router.replace({ query: { tab } })
    }
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

  // 2FA management (disable / regenerate backup codes) — both require a fresh
  // TOTP code to confirm the second factor (anti session-hijack).
  type TotpManageAction = 'disable' | 'regenerate'
  const totpManageAction = ref<TotpManageAction | null>(null)
  const totpManageCode = ref('')
  const totpManageError = ref('')
  const disableMutation = useMutation<void>()
  const regenerateMutation = useMutation<void>()

  const startTotpManage = (action: TotpManageAction) => {
    totpManageAction.value = action
    totpManageCode.value = ''
    totpManageError.value = ''
  }

  const cancelTotpManage = () => {
    totpManageAction.value = null
    totpManageCode.value = ''
    totpManageError.value = ''
  }

  const confirmDisableTotp = async () => {
    const code = totpManageCode.value.trim()
    if (!code) {
      totpManageError.value = t('auth.two_factor.required_code')
      return
    }
    totpManageError.value = ''

    await disableMutation.run(
      async () => {
        await authApi.disableTwoFactor({ totp_code: code })
        if (userStore.currentUser) {
          userStore.setCurrentUser({ ...userStore.currentUser, totp_enabled: false })
        }
        cancelTotpManage()
        toast.add({
          severity: 'success',
          summary: t('profile.security.totp_disabled_done'),
          life: 3000,
        })
      },
      {
        onError: (error) => {
          const validationErrs = getValidationErrors(error)
          totpManageError.value =
            validationErrs?.['totp_code'] ?? getApiErrorMessage(error, t('auth.two_factor.error'))
          totpManageCode.value = ''
        },
      },
    )
  }

  const confirmRegenerateCodes = async () => {
    const code = totpManageCode.value.trim()
    if (!code) {
      totpManageError.value = t('auth.two_factor.required_code')
      return
    }
    totpManageError.value = ''

    await regenerateMutation.run(
      async () => {
        const response = await authApi.regenerateBackupCodes({ totp_code: code })
        backupCodes.value = response.backup_codes
        showBackupCodes.value = true
        cancelTotpManage()
      },
      {
        onError: (error) => {
          const validationErrs = getValidationErrors(error)
          totpManageError.value =
            validationErrs?.['totp_code'] ?? getApiErrorMessage(error, t('auth.two_factor.error'))
          totpManageCode.value = ''
        },
      },
    )
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
      window.open(res.deeplink, '_blank')
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

  // ─── Profile edit (full_name) ─────────────────────────────────────────────────
  const savingProfile = ref(false)

  async function saveFullName(fullName: string): Promise<boolean> {
    const trimmed = fullName.trim()
    if (!trimmed) {
      toast.add({ severity: 'warn', summary: t('common.required'), life: 2500 })
      return false
    }
    savingProfile.value = true
    try {
      const res = await profileApi.updateProfile({ full_name: trimmed })
      userStore.setCurrentUser(res.data)
      toast.add({ severity: 'success', summary: t('settings.profile.saved', 'Профиль сохранён'), life: 2000 })
      return true
    } catch (error) {
      toast.add({
        severity: 'error',
        summary: getApiErrorMessage(error, t('errors.unknown', 'Ошибка')),
        life: 3000,
      })
      return false
    } finally {
      savingProfile.value = false
    }
  }

  // ─── Locale (account-level persisted + device i18n) ────────────────────────────
  const savingLocale = ref(false)

  async function changeLocale(locale: AvailableLocales) {
    // Apply locally first so the UI flips instantly.
    localeManager.changeLocale(locale)
    savingLocale.value = true
    try {
      const res = await profileApi.updateProfile({ locale })
      userStore.setCurrentUser(res.data)
    } catch {
      // Device locale already applied; account persistence is best-effort.
    } finally {
      savingLocale.value = false
    }
  }

  // ─── Avatar ───────────────────────────────────────────────────────────────────
  const avatarUploading = ref(false)
  const avatarPath = computed(() => userStore.getUser?.avatar_path ?? null)

  async function uploadAvatar(file: File) {
    avatarUploading.value = true
    try {
      const res = await profileApi.uploadAvatar(file)
      userStore.setCurrentUser(res.data)
      toast.add({ severity: 'success', summary: t('common.saved', 'Сохранено'), life: 2000 })
    } catch (error) {
      toast.add({
        severity: 'error',
        summary: getApiErrorMessage(error, t('profile.avatar.uploadError')),
        life: 3000,
      })
    } finally {
      avatarUploading.value = false
    }
  }

  async function removeAvatar() {
    avatarUploading.value = true
    try {
      const res = await profileApi.removeAvatar()
      userStore.setCurrentUser(res.data)
      toast.add({ severity: 'info', summary: t('common.saved', 'Сохранено'), life: 2000 })
    } catch {
      toast.add({ severity: 'error', summary: t('errors.unknown', 'Ошибка'), life: 3000 })
    } finally {
      avatarUploading.value = false
    }
  }

  return {
    // Tab management (activeTab can be 'hub' when no ?tab query param)
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

    // 2FA management (disable / regenerate backup codes)
    totpManageAction,
    totpManageCode,
    totpManageError,
    isManagingTotp: computed(
      () => disableMutation.isPending.value || regenerateMutation.isPending.value,
    ),
    startTotpManage,
    cancelTotpManage,
    confirmDisableTotp,
    confirmRegenerateCodes,

    // Telegram
    telegramLinked,
    telegramUsername,
    telegramLinking,
    telegramUnlinking,
    linkTelegram,
    unlinkTelegram,

    // Profile edit
    savingProfile,
    saveFullName,

    // Locale (account-level persisted)
    savingLocale,
    changeLocale,

    // Avatar
    avatarPath,
    avatarUploading,
    uploadAvatar,
    removeAvatar,
  }
}
