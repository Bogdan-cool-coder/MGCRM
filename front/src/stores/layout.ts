import { ref, computed } from 'vue'
import { defineStore } from 'pinia'

export type NavMode = 'sidebar' | 'orbit'

export const useLayoutStore = defineStore(
  'layout',
  () => {
    // ─── State ────────────────────────────────────────────────────────────
    const sidebarCollapsed = ref<boolean>(false)
    /**
     * isDarkMode kept for backward-compat only.
     * Theme is now managed by useThemeStore (stores/theme.ts).
     * This field no longer drives the .app-dark class; themeStore does.
     * @deprecated use useThemeStore instead
     */
    const isDarkMode = ref<boolean>(false)

    /** Active nav mode: sidebar (default) or orbit */
    const navMode = ref<NavMode>('sidebar')

    // Orbit position & state (Срез 2+)
    const orbitPos = ref<{ top: number; left: number } | null>(null)
    const orbitOrientation = ref<'horizontal' | 'vertical'>('horizontal')
    const orbitCollapsed = ref<boolean>(false)

    // Command palette
    const commandPaletteOpen = ref<boolean>(false)
    const recentRoutes = ref<string[]>([])

    // ─── Getters ──────────────────────────────────────────────────────────
    const isSidebarCollapsed = computed<boolean>(() => sidebarCollapsed.value)

    // ─── Actions ──────────────────────────────────────────────────────────
    function toggleSidebar(): void {
      sidebarCollapsed.value = !sidebarCollapsed.value
    }

    function setSidebarCollapsed(value: boolean): void {
      sidebarCollapsed.value = value
    }

    /** @deprecated use useThemeStore.setTheme instead */
    function toggleDarkMode(): void {
      isDarkMode.value = !isDarkMode.value
      if (isDarkMode.value) {
        document.documentElement.classList.add('app-dark')
      } else {
        document.documentElement.classList.remove('app-dark')
      }
    }

    /** @deprecated use useThemeStore.setTheme instead */
    function setDarkMode(value: boolean): void {
      isDarkMode.value = value
      if (value) {
        document.documentElement.classList.add('app-dark')
      } else {
        document.documentElement.classList.remove('app-dark')
      }
    }

    function setNavMode(mode: NavMode): void {
      navMode.value = mode
    }

    function setOrbitPos(pos: { top: number; left: number } | null): void {
      orbitPos.value = pos
    }

    function setOrbitOrientation(o: 'horizontal' | 'vertical'): void {
      orbitOrientation.value = o
    }

    function toggleOrbitCollapsed(): void {
      orbitCollapsed.value = !orbitCollapsed.value
    }

    function openCommandPalette(): void {
      commandPaletteOpen.value = true
    }

    function closeCommandPalette(): void {
      commandPaletteOpen.value = false
    }

    function pushRecentRoute(route: string): void {
      const current = recentRoutes.value.filter((r) => r !== route)
      recentRoutes.value = [route, ...current].slice(0, 5)
    }

    return {
      // State
      sidebarCollapsed,
      isDarkMode,
      navMode,
      orbitPos,
      orbitOrientation,
      orbitCollapsed,
      commandPaletteOpen,
      recentRoutes,
      // Getters
      isSidebarCollapsed,
      // Actions
      toggleSidebar,
      setSidebarCollapsed,
      toggleDarkMode,
      setDarkMode,
      setNavMode,
      setOrbitPos,
      setOrbitOrientation,
      toggleOrbitCollapsed,
      openCommandPalette,
      closeCommandPalette,
      pushRecentRoute,
    }
  },
  {
    persist: {
      pick: [
        'sidebarCollapsed',
        'isDarkMode',
        'navMode',
        'orbitPos',
        'orbitOrientation',
        'orbitCollapsed',
        'recentRoutes',
      ],
    },
  },
)
