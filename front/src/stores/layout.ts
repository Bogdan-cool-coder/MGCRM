import { ref, computed } from 'vue'
import { defineStore } from 'pinia'

export type NavMode = 'sidebar' | 'orbit'

export const useLayoutStore = defineStore(
  'layout',
  () => {
    // ─── State ────────────────────────────────────────────────────────────
    const sidebarCollapsed = ref<boolean>(false)
    /**
     * isDarkMode is kept for the one-time boot migration only.
     * On first launch after upgrade, main.ts reads this value and migrates it
     * to useThemeStore; after that it is never written again.
     * Theme is exclusively managed by useThemeStore (stores/theme.ts).
     * Read-only outside main.ts — do NOT write to this ref.
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
