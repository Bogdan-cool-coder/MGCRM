import { ref, onMounted, provide, computed } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useUserStore } from '@/stores/user'

export const SETTINGS_MARK_DIRTY_KEY = 'settingsMarkDirty'
export const SETTINGS_MARK_CLEAN_KEY = 'settingsMarkClean'

/** Разделы Ф1 (аккаунт + интеграции) */
const ACCOUNT_KEYS = ['profile', 'security', 'appearance', 'language', 'channels'] as const

/** Разделы Ф2 — Справочники (admin/director only) */
export const DIRECTORIES_KEYS = [
  'countries',
  'acq-channels',
  'disc-reasons',
  'catalog',
  'exchange-rates',
] as const

/** Разделы Ф3 — Система (admin/director; system-reset — только admin) */
export const SYSTEM_KEYS = [
  'users',
  'access-control',
  'automation-runs',
  'system-reset',
] as const

/** Ключи системы, доступные только admin (не director) */
const ADMIN_ONLY_KEYS = ['system-reset'] as const

/** Все валидные ключи разделов (Ф1 + Ф2 + Ф3 активные) */
const VALID_KEYS = [...ACCOUNT_KEYS, ...DIRECTORIES_KEYS, ...SYSTEM_KEYS] as const
type ValidKey = (typeof VALID_KEYS)[number]

// No-op callbacks provided to child sections.
const noop = () => {}

export function useSettings() {
  const route = useRoute()
  const router = useRouter()
  const userStore = useUserStore()

  const activeSection = ref<string>('profile')

  const isAdminOrDirector = computed(() => {
    const role = userStore.getUserRole
    return role === 'admin' || role === 'director'
  })

  // Provide no-op tokens — child sections inject and call these to signal
  // dirty state; without a route guard the signals are harmless.
  provide(SETTINGS_MARK_DIRTY_KEY, noop)
  provide(SETTINGS_MARK_CLEAN_KEY, noop)

  const isAdmin = computed(() => userStore.getUserRole === 'admin')

  function resolveSection(key: string | undefined): string {
    if (!key) return 'profile'
    // Directories + most System sections require admin/director
    if ((DIRECTORIES_KEYS as readonly string[]).includes(key) && !isAdminOrDirector.value) {
      return 'profile'
    }
    if ((SYSTEM_KEYS as readonly string[]).includes(key) && !isAdminOrDirector.value) {
      return 'profile'
    }
    // system-reset is admin-only (director redirects to 'profile' on direct deep-link)
    if ((ADMIN_ONLY_KEYS as readonly string[]).includes(key) && !isAdmin.value) {
      return 'profile'
    }
    if ((VALID_KEYS as readonly string[]).includes(key as ValidKey)) return key
    return 'profile'
  }

  onMounted(() => {
    const fromQuery = route.query['section'] as string | undefined
    activeSection.value = resolveSection(fromQuery)
  })

  /** Navigate to a new section immediately — no confirm-on-leave guard. */
  function setSection(key: string) {
    if (key === activeSection.value) return
    activeSection.value = key
    void router.replace({ path: '/settings', query: { section: key } })
  }

  return {
    activeSection,
    setSection,
    isAdminOrDirector,
    isAdmin,
  }
}
