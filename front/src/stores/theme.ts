import { ref } from 'vue'
import { defineStore } from 'pinia'

export type Theme = 'light' | 'dark'

export const useThemeStore = defineStore(
  'theme',
  () => {
    // ─── State ────────────────────────────────────────────────────────────
    const theme = ref<Theme>('light')

    // ─── Actions ──────────────────────────────────────────────────────────
    function setTheme(value: Theme): void {
      theme.value = value
      if (value === 'dark') {
        document.documentElement.classList.add('app-dark')
      } else {
        document.documentElement.classList.remove('app-dark')
      }
    }

    function toggleTheme(): void {
      setTheme(theme.value === 'light' ? 'dark' : 'light')
    }

    return {
      theme,
      setTheme,
      toggleTheme,
    }
  },
  {
    persist: {
      pick: ['theme'],
      key: 'mgcrm_theme',
    },
  },
)
