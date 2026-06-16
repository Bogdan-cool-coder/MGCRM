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
      <main class="layout__content">
        <router-view />
      </main>
    </div>
  </div>

  <!-- Global Toast -->
  <Toast position="top-right" />

  <!-- Global ConfirmDialog -->
  <ConfirmDialog />

  <!-- Global ActivityFormDialog (for Kanban quick-add) -->
  <ActivityFormDialog
    v-if="activityStore.quickAddContext"
    v-model="quickAddOpen"
    :target-type="'deal'"
    :target-id="activityStore.quickAddContext.dealId"
    :allowed-kinds="activityStore.quickAddContext.allowedKinds"
    @created="activityStore.closeQuickAdd()"
    @update:model-value="(v) => { if (!v) activityStore.closeQuickAdd() }"
  />
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute } from 'vue-router'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import { AppSidebar, AppTopbar } from '@/components/AppShell'
import { useLayoutStore } from '@/stores/layout'
import { useActivityStore } from '@/stores/activityStore'
import ActivityFormDialog from '@/components/ActivityFormDialog.vue'

const route = useRoute()
const layoutStore = useLayoutStore()
const activityStore = useActivityStore()

const showLayout = computed(() => route.name !== 'Login')

const quickAddOpen = ref(false)
watch(
  () => activityStore.quickAddContext,
  (ctx) => {
    quickAddOpen.value = !!ctx
  },
)
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
  // Flex properties set explicitly — do NOT rely on Bootstrap overflow-auto utility
  // (Bootstrap grid utilities are loaded but overflow utilities are absent from bundle).
  //
  // display:flex + flex-direction:column is required so that full-height pages
  // (e.g. PipelineCanvas in canvas mode) can resolve height:100% / flex:1 on their
  // root element. Without flex context here, height:100% resolves to scroll-content
  // height (not the visible viewport), causing VueFlow to collapse to ~376px.
  display: flex;
  flex-direction: column;
  flex: 1 1 0;
  min-height: 0;
  overflow-y: auto;
  background-color: $surface-100;
  padding: $space-4 $space-6;
}

// Убрать padding на логин-странице (рендерит сам с нуля)
.layout--login .layout__content {
  padding: 0;
}
</style>
