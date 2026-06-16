import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { persistPlugin } from '@/plugins/persist'
import { i18n } from '@/plugins/i18n'
import './plugins/echarts'

import 'bootstrap/dist/css/bootstrap-grid.min.css'
import 'primeicons/primeicons.css'
import '@/assets/styles/main.scss'

import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import ConfirmationService from 'primevue/confirmationservice'
import Tooltip from 'primevue/tooltip'

import { applyAppCssVariables, MgCrmPreset, primeVueZIndex } from '@/theme'
import { configureAxiosMiddleware } from '@/api/client'

import App from '@/App.vue'
import {
  bootstrapApp,
  setBootstrapSessionPromise,
  createUnauthorizedHandler,
  localeManager,
} from '@/application'
import { createAppRouter } from '@/router'
import { useUserStore } from '@/stores/user'
import { useLayoutStore } from '@/stores/layout'
import { useThemeStore } from '@/stores/theme'

const app = createApp(App)
const pinia = createPinia()
pinia.use(persistPlugin)

app.use(pinia)
app.use(i18n)

const userStore = useUserStore(pinia)

// Начальная локаль
const initialLocale = localeManager.getInitialLocale()
localeManager.setLocaleLocal(initialLocale)

// Гидратация dark mode из themeStore до первого рендера.
// pinia-plugin-persistedstate восстанавливает theme в store, но класс
// .app-dark на <html> не восстанавливается — нужно сделать это явно до монтирования.
const layoutStore = useLayoutStore(pinia)
const themeStore = useThemeStore(pinia)

// Apply theme from themeStore (primary source).
// Fall back to layoutStore.isDarkMode for backward compat with existing persisted state.
if (themeStore.theme === 'dark') {
  document.documentElement.classList.add('app-dark')
} else if (themeStore.theme === 'light' && layoutStore.isDarkMode) {
  // Migrate: old persisted dark mode → themeStore
  themeStore.setTheme('dark')
}

const router = createAppRouter(pinia)

configureAxiosMiddleware({
  getToken: () => userStore.getAuthCredential,
  onUnauthorized: createUnauthorizedHandler(pinia, router),
})

// CSS-переменные темы — применяем до монтирования
applyAppCssVariables()

app.use(PrimeVue, {
  theme: {
    preset: MgCrmPreset,
    options: {
      prefix: 'p',
      darkModeSelector: '.app-dark',
      cssLayer: true,
    },
  },
  zIndex: primeVueZIndex,
})
app.use(ToastService)
app.use(ConfirmationService)
app.directive('tooltip', Tooltip)
app.use(router)

// Bootstrap promise — запускает инициализацию сессии
// Используем .then() вместо top-level await: избегаем ES module deadlock
// (top-level await создаёт circular dep: main ждёт router.isReady → chunk
// импортирует из main → main не завершён → deadlock)
const bootstrapPromise = bootstrapApp(pinia, router)
setBootstrapSessionPromise(bootstrapPromise)

bootstrapPromise.then(async () => {
  await router.isReady()
  app.mount('#app')
})
