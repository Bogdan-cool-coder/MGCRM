import { fileURLToPath, URL } from 'node:url'

import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig(() => ({
  plugins: [vue()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
    extensions: ['.js', '.ts', '.vue', '.json'],
  },
  server: {
    host: '0.0.0.0',
    allowedHosts: ['localhost', 'mgcrm.local', '127.0.0.1'],
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

          if (id.includes('primevue') || id.includes('@primevue') || id.includes('@primeuix')) {
            return 'primevue'
          }

          if (
            id.includes('vue') ||
            id.includes('pinia') ||
            id.includes('vue-router') ||
            id.includes('vue-i18n')
          ) {
            return 'vue-core'
          }

          if (id.includes('axios') || id.includes('decimal.js')) {
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
