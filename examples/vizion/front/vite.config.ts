import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'
import vueDevTools from 'vite-plugin-vue-devtools'

// https://vite.dev/config/
export default defineConfig(({ command }) => ({
  plugins: [vue(), ...(command === 'serve' ? [vueDevTools()] : [])],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
    extensions: ['.js', '.ts', '.vue', '.json'],
  },
  server: {
    host: '0.0.0.0',
    allowedHosts: ['vizion.macroglobal.tech', 'devizion.macroglobal.tech', 'vizion.lazarewww.ru', 'localhost'],
    proxy: {
      '/api': {
        target: 'http://nginx:80',
        changeOrigin: true,
      },
    },
  },
  build: {
    rollupOptions: {
      output: {
        manualChunks(id) {
          if (!id.includes('node_modules')) return

          if (id.includes('primevue') || id.includes('@primevue')) {
            return 'primevue'
          }

          if (id.includes('vue') || id.includes('pinia') || id.includes('vue-router') || id.includes('vue-i18n')) {
            return 'vue-core'
          }

          if (id.includes('chart.js')) {
            return 'charts'
          }

          if (id.includes('axios') || id.includes('lodash') || id.includes('decimal.js')) {
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
