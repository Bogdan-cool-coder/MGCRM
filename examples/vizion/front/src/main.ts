// MSW — перехват API запросов для отладки без бэкенда
// Включить: VITE_MOCK_API=true в front/.env.local
if (import.meta.env.DEV && import.meta.env.VITE_MOCK_API === 'true') {
  const { worker } = await import('@/mocks/browser')
  await worker.start({ onUnhandledRequest: 'bypass' })
  console.info('[MSW] Mock API enabled')
}

import { createApp } from 'vue'
import { createPinia } from 'pinia'
import { useUserStore } from '@/stores/user'
import { createPersistPlugin } from '@/plugins/persist'
import { i18n } from '@/plugins/i18n'

import 'bootstrap/dist/css/bootstrap-grid.min.css'
import 'primeicons/primeicons.css'
import '@/assets/styles/main.scss'
import '@/plugins/echarts'
import PrimeVue from 'primevue/config'
import ToastService from 'primevue/toastservice'
import { applyAppCssVariables, primeVuePreset, primeVueZIndex } from '@/theme'
import { configureAxiosMiddleware } from '@/api/client'

import App from '@/App.vue'
import {
  APPLICATION_SERVICES_KEY,
  bootstrapApp,
  configureLocaleCoordinator,
  createApplicationServices,
  localeManager,
  setBootstrapSessionPromise,
  type ApplicationServices,
} from '@/application'
import { createAppRouter } from '@/router'
import { createServices, SERVICES_KEY, type Services } from '@/services'
import { registerStaleAssetPreloadRecovery } from '@/utils/staleAssetRecovery'

const app = createApp(App)
const pinia = createPinia()

pinia.use(createPersistPlugin())

app.use(pinia)
app.use(i18n)

const userStore = useUserStore(pinia)

const initialLocale = localeManager.getInitialLocale()

localeManager.setLocaleLocal(initialLocale)

const router = createAppRouter(pinia)
const services: Services = createServices()
const applicationServices: ApplicationServices = createApplicationServices({
  pinia,
  router,
  services,
})

registerStaleAssetPreloadRecovery()

configureAxiosMiddleware({
  getToken: () => userStore.getAuthCredential,
  onUnauthorized: applicationServices.unauthorizedHandler,
})

configureLocaleCoordinator({
  isAuthenticated: () => userStore.getIsAuthenticated,
  getUserLocale: () => userStore.getUserLocale,
  updateCurrentUserLocale: (locale) =>
    applicationServices.userSessionService.updateCurrentUserLocale(locale),
})

const bootstrapPromise = bootstrapApp(
  pinia,
  applicationServices,
  router,
  { initialLocale },
)
setBootstrapSessionPromise(bootstrapPromise)

app.use(router)
app.provide(SERVICES_KEY, services)
app.provide(APPLICATION_SERVICES_KEY, applicationServices)

applyAppCssVariables()

app.use(PrimeVue, {
  theme: {
    preset: primeVuePreset,
    options: {
      cssPrefix: 'p-',
      darkModeSelector: 'none',
    },
  },
  zIndex: primeVueZIndex,
})
app.use(ToastService)

// Use .then() instead of top-level await to avoid ES module deadlock.
// Top-level await in the entry module creates a circular dependency deadlock:
// main module awaits router.isReady() → router lazy-loads chunk →
// chunk statically imports from main module → waits for main to finish → DEADLOCK.
// With .then(), main module evaluates synchronously to completion,
// so its exports are available when the chunk evaluates.
bootstrapPromise.then(async () => {
  await router.isReady()
  app.mount('#app')
})
