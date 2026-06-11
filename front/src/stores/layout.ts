import { ref, computed } from 'vue'
import { defineStore } from 'pinia'

export const useLayoutStore = defineStore(
  'layout',
  () => {
    // ─── State ────────────────────────────────────────────────────────────
    const sidebarCollapsed = ref<boolean>(false)
    const isDarkMode = ref<boolean>(false)

    // ─── Getters ──────────────────────────────────────────────────────────
    const isSidebarCollapsed = computed<boolean>(() => sidebarCollapsed.value)

    // ─── Actions ──────────────────────────────────────────────────────────
    function toggleSidebar(): void {
      sidebarCollapsed.value = !sidebarCollapsed.value
    }

    function setSidebarCollapsed(value: boolean): void {
      sidebarCollapsed.value = value
    }

    function toggleDarkMode(): void {
      isDarkMode.value = !isDarkMode.value
      if (isDarkMode.value) {
        document.documentElement.classList.add('app-dark')
      } else {
        document.documentElement.classList.remove('app-dark')
      }
    }

    function setDarkMode(value: boolean): void {
      isDarkMode.value = value
      if (value) {
        document.documentElement.classList.add('app-dark')
      } else {
        document.documentElement.classList.remove('app-dark')
      }
    }

    return {
      // State
      sidebarCollapsed,
      isDarkMode,
      // Getters
      isSidebarCollapsed,
      // Actions
      toggleSidebar,
      setSidebarCollapsed,
      toggleDarkMode,
      setDarkMode,
    }
  },
  {
    persist: {
      pick: ['sidebarCollapsed', 'isDarkMode'],
    },
  },
)
