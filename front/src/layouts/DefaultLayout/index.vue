<template>
  <div class="layout d-flex h-100" :class="{ 'layout--dark': layoutStore.isDarkMode }">
    <!-- Sidebar — показываем только для авторизованных (не на /login) -->
    <AppSidebar
      v-if="showLayout"
      :collapsed="layoutStore.isSidebarCollapsed"
      @toggle="layoutStore.toggleSidebar()"
    />

    <!-- Основная область -->
    <div class="layout__main d-flex flex-column flex-grow-1 overflow-hidden">
      <!-- Topbar — только для авторизованных -->
      <AppTopbar v-if="showLayout" />

      <!-- Контент страницы -->
      <main class="layout__content flex-grow-1 overflow-auto">
        <router-view />
      </main>
    </div>
  </div>

  <!-- Global Toast -->
  <Toast position="top-right" />
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRoute } from 'vue-router'
import Toast from 'primevue/toast'
import { AppSidebar, AppTopbar } from '@/components/AppShell'
import { useLayoutStore } from '@/stores/layout'

const route = useRoute()
const layoutStore = useLayoutStore()

const showLayout = computed(() => route.name !== 'Login')
</script>

<style lang="scss" scoped>
.layout {
  width: 100%;
  height: 100%;
}

.layout__main {
  min-width: 0;
  min-height: 0;
}

.layout__content {
  background-color: $surface-100;
  padding: $space-4 $space-6;
}

// Убрать padding на логин-странице (рендерит сам с нуля)
.layout--login .layout__content {
  padding: 0;
}
</style>
