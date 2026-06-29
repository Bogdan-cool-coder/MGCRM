import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import { sentryVitePlugin } from '@sentry/vite-plugin'

// Polling is enabled when running inside Docker on macOS with virtiofs/colima
// where inotify events from bind-mounts are unreliable.
// Set CHOKIDAR_USEPOLLING=1 (or VITE_USE_POLLING=1) in the container env to activate.
const usePolling =
  process.env.CHOKIDAR_USEPOLLING === '1' || process.env.VITE_USE_POLLING === '1'

// Sentry source-map upload: активируется только когда все три переменные заданы.
// Без SENTRY_AUTH_TOKEN билд не падает и source-maps НЕ загружаются в Sentry.
const sentryAuthToken = process.env.SENTRY_AUTH_TOKEN
const sentryOrg = process.env.SENTRY_ORG
const sentryProject = process.env.SENTRY_PROJECT
const sentryUploadEnabled = Boolean(sentryAuthToken && sentryOrg && sentryProject)

// https://vite.dev/config/
export default defineConfig(() => ({
  plugins: [
    vue(),
    // Source-maps upload — только на прод-билде с полным набором Sentry-env.
    // Локальный dev и билд без токена проходят без попытки загрузки.
    ...(sentryUploadEnabled
      ? [
          sentryVitePlugin({
            org: sentryOrg!,
            project: sentryProject!,
            authToken: sentryAuthToken!,
            release: { name: process.env.VITE_SENTRY_RELEASE },
            sourcemaps: {
              // После загрузки source-maps в Sentry убираем их из публичного dist,
              // чтобы не светить исходники пользователям.
              filesToDeleteAfterUpload: ['./dist/**/*.map'],
            },
            telemetry: false,
          }),
        ]
      : []),
  ],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
    extensions: ['.js', '.ts', '.vue', '.json'],
  },
  server: {
    host: '0.0.0.0',
    allowedHosts: ['localhost', 'mgcrm.local', '127.0.0.1'],
    watch: usePolling
      ? {
          usePolling: true,
          interval: 300,
        }
      : undefined,
    proxy: {
      '/api': {
        target: 'http://nginx:80',
        changeOrigin: true,
      },
    },
  },
  build: {
    // Source-maps нужны Sentry для читаемых stack-трейсов.
    // Генерируем всегда при сборке; если SENTRY_AUTH_TOKEN задан —
    // sentryVitePlugin загрузит их и удалит из dist (см. выше).
    // Если токена нет — map-файлы остаются в dist, но это ок для стейджинга;
    // для прод-деплоя без Sentry можно форсить false через env VITE_SOURCEMAP.
    sourcemap: process.env.VITE_SOURCEMAP !== 'false',
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) return

          // Sentry SDK — isolated so it doesn't bloat the app entry chunk
          if (id.includes('/@sentry/')) {
            return 'sentry'
          }

          // PrimeVue (components + icons + utils) — typically the largest chunk
          if (
            id.includes('/primevue/') ||
            id.includes('/@primevue/') ||
            id.includes('/@primeuix/')
          ) {
            return 'primevue'
          }

          // ECharts + vue-echarts adapter — heavy data-viz bundle
          if (id.includes('/echarts/') || id.includes('/vue-echarts/') || id.includes('/zrender/')) {
            return 'echarts'
          }

          // Vue-Flow (canvas automation) — heavy but rarely loaded
          if (
            id.includes('/@vue-flow/') ||
            id.includes('/d3-') ||
            id.includes('/dagre/')
          ) {
            return 'vue-flow'
          }

          // Core Vue runtime — kept small, always loaded
          if (
            id.includes('/vue/') ||
            id.includes('/pinia/') ||
            id.includes('/vue-router/') ||
            id.includes('/vue-i18n/') ||
            id.includes('/@vue/') ||
            id.includes('/@vueuse/')
          ) {
            return 'vue-core'
          }

          // Markdown parser (documents / feed composer)
          if (id.includes('/marked/')) {
            return 'vendor-markdown'
          }

          // Drag-and-drop + other utility libs
          if (
            id.includes('/vuedraggable/') ||
            id.includes('/sortablejs/') ||
            id.includes('/axios/') ||
            id.includes('/decimal.js/')
          ) {
            return 'vendor'
          }
        },
      },
    },
  },
  css: {
    preprocessorOptions: {
      scss: {
        additionalData: '@use "@/theme/scss/index" as *;',
      },
    },
  },
}))
