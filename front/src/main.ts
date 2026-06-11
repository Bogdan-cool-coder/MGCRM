import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { persistPlugin } from '@/plugins/persist'
import { i18n } from '@/plugins/i18n'

import 'bootstrap/dist/css/bootstrap-grid.min.css'
import 'primeicons/primeicons.css'
import '@/assets/styles/main.scss'

import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'

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

const app = createApp(App)
const pinia = createPinia()
pinia.use(persistPlugin)

app.use(pinia)
app.use(i18n)

const userStore = useUserStore(pinia)

// Начальная локаль
const initialLocale = localeManager.getInitialLocale()
localeManager.setLocaleLocal(initialLocale)

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
