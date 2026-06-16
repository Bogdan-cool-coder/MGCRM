<template>
  <div class="layout d-flex h-100">
    <!-- Sidebar — shown only for authenticated routes in sidebar mode -->
    <AppSidebar
      v-if="showLayout && layoutStore.navMode === 'sidebar'"
      :collapsed="layoutStore.isSidebarCollapsed"
      @toggle="layoutStore.toggleSidebar()"
    />

    <!-- Orbita — floating nav in orbit mode -->
    <Orbita v-if="showLayout && layoutStore.navMode === 'orbit'" />

    <!-- Main content area — full-width in orbit mode -->
    <div
      :class="[
        'layout__main d-flex flex-column flex-grow-1 overflow-hidden',
        { 'layout__main--full': layoutStore.navMode === 'orbit' },
      ]"
    >
      <!-- AppTopbar removed — all its features (theme, locale, profile, logout)
           moved to AccountMenu inside AppSidebar footer (Срез 1) -->

      <!-- Page content -->
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

  <!-- Command Palette — global Ctrl/Cmd+K -->
  <CommandPalette v-if="showLayout" />

  <!-- Hotkeys Cheatsheet — global ? -->
  <HotkeysCheatsheet v-if="showLayout" />
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import Toast from 'primevue/toast'
import ConfirmDialog from 'primevue/confirmdialog'
import { AppSidebar } from '@/components/AppShell'
import {
  Orbita,
  CommandPalette,
  HotkeysCheatsheet,
  useNavHotkeys,
  openCheatsheet,
} from '@/components/Orbita'
import { useLayoutStore } from '@/stores/layout'
import { useActivityStore } from '@/stores/activityStore'
import ActivityFormDialog from '@/components/ActivityFormDialog.vue'

const route = useRoute()
const router = useRouter()
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

// ─── Track recent routes ──────────────────────────────────────────────────────
// Only track authenticated pages (not login) and only when layout is shown
router.afterEach((to) => {
  if (to.name === 'Login' || to.name === 'Root') return
  if (!to.meta?.requiresAuth) return
  // Push the route path (strip trailing slash for consistency)
  const path = to.path.replace(/\/$/, '') || '/'
  layoutStore.pushRecentRoute(path)
})

// ─── Global hotkeys ───────────────────────────────────────────────────────────
useNavHotkeys({
  onOpenCommandPalette: () => layoutStore.openCommandPalette(),
  onOpenCheatsheet: () => openCheatsheet(),
})
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
  // No padding-top needed — AppTopbar removed; content starts at top of viewport
  padding: $space-4 $space-6;
}

// Orbit mode: main occupies full viewport width (no sidebar offset)
.layout__main--full {
  width: 100%;
}
</style>
