import { onMounted, onUnmounted } from 'vue'
import { setLocale } from '@/application/locale'
import { getI18nLocale, isValidLocale, type AvailableLocales } from '@/plugins/i18n'
import { getActivePinia } from 'pinia'
import { useUserStore } from '@/stores/user'

const LOCALE_STORAGE_KEY = 'vizion_locale'

export const useLocaleSync = () => {
  let handler: ((e: StorageEvent) => void) | null = null

  onMounted(() => {
    if (handler) return

    handler = (e: StorageEvent) => {
      if (e.storageArea !== localStorage) return

      if (e.key === LOCALE_STORAGE_KEY && e.newValue) {
        if (getI18nLocale() === e.newValue) return

        if (!isValidLocale(e.newValue)) return

        const pinia = getActivePinia()
        if (pinia) {
          const userStore = useUserStore(pinia)
          if (!userStore.getIsAuthenticated) return
        }

        setLocale(e.newValue as AvailableLocales)
      }
    }

    window.addEventListener('storage', handler)
  })

  onUnmounted(() => {
    if (handler) {
      window.removeEventListener('storage', handler)
      handler = null
    }
  })
}
