import { ref, onMounted, provide } from 'vue'
import { useRoute, useRouter } from 'vue-router'

export const SETTINGS_MARK_DIRTY_KEY = 'settingsMarkDirty'
export const SETTINGS_MARK_CLEAN_KEY = 'settingsMarkClean'

/** Разделы Ф1, которые существуют и доступны */
const PHASE1_KEYS = ['profile', 'security', 'appearance', 'language', 'channels'] as const

// No-op callbacks provided to child sections.
// Children call these to sync their local dirty state upward;
// the route-leave guard has been removed (Ф1 — phantom dialog issue),
// so these are kept as stubs so child inject sites remain valid.
const noop = () => {}

export function useSettings() {
  const route = useRoute()
  const router = useRouter()

  const activeSection = ref<string>('profile')

  // Provide no-op tokens — child sections inject and call these to signal
  // dirty state; without a route guard the signals are harmless.
  provide(SETTINGS_MARK_DIRTY_KEY, noop)
  provide(SETTINGS_MARK_CLEAN_KEY, noop)

  function resolveSection(key: string | undefined): string {
    if (key && (PHASE1_KEYS as readonly string[]).includes(key)) return key
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
  }
}
