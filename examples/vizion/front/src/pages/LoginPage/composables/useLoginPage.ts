import { computed, onMounted, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useApplicationServices } from '@/application'
import { useSessionMutation } from '@/composables/async/useSessionMutation'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { extractRedirect } from '@/router/redirect'
import { iframeTokenStorage } from '@/storage'
import { useUserStore } from '@/stores/user'
import { getApiErrorMessage } from '@/utils/errors'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

type LoginPayload = {
  email: string
  password: string
}

export const useLoginPage = () => {
  const { t } = useLocalI18n({ en, ru })
  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()
  const { userSessionService, sessionCoordinator } = useApplicationServices()
  const loginMutation = useSessionMutation<void>()
  const iframeLoginMutation = useSessionMutation<void>()

  const error = ref('')
  const loading = computed(
    () => loginMutation.isPending.value || iframeLoginMutation.isPending.value,
  )
  const hasIframeToken = ref(false)

  const afterLoginRedirect = async () => {
    await sessionCoordinator.initializeSession()

    const user = userStore.getUser
    if (!user) {
      throw new Error('User not loaded after login')
    }

    // A `?redirect=` target (deep-link login) is honoured verbatim. Otherwise
    // we send the user to the root path `/`, which `resolveNavigation`
    // resolves to their personal home page (default `/reports`).
    const redirect = extractRedirect(route.query)
    const targetRoute = redirect ?? '/'

    await router.replace(targetRoute)
  }

  const handleLogin = async ({ email, password }: LoginPayload) => {
    if (loading.value) return

    error.value = ''

    try {
      await loginMutation.run(() => userSessionService.login({ email, password }), {
        onSuccess: afterLoginRedirect,
      })
    } catch (loginError: unknown) {
      error.value = getApiErrorMessage(loginError, t('loginError'))
    }
  }

  const handleIframeReauth = async () => {
    if (loading.value) return

    const token = iframeTokenStorage.get()
    if (!token) return

    error.value = ''

    try {
      await iframeLoginMutation.run(() => userSessionService.loginWithIframeToken(token), {
        onSuccess: afterLoginRedirect,
      })
    } catch (loginError: unknown) {
      error.value = getApiErrorMessage(loginError, t('iframeReauthError'))
    }
  }

  onMounted(() => {
    hasIframeToken.value = Boolean(iframeTokenStorage.get())
  })

  return {
    t,
    error,
    loading,
    hasIframeToken,
    handleLogin,
    handleIframeReauth,
  }
}
