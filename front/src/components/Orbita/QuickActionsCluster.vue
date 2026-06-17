<template>
  <!--
    QuickActionsCluster — renders the user's saved quick-actions
    as compact icon-buttons inside the Orbita actions slot.
    Shown only when the user has at least one configured action.
  -->
  <template v-if="resolvedActions.length > 0">
    <div
      v-for="action in resolvedActions"
      :key="action.key"
      class="quick-action-btn-wrap"
    >
      <button
        v-tooltip="tooltipOptions(t(action.labelKey))"
        class="quick-action-btn"
        :aria-label="t(action.labelKey)"
        @click="execute(action)"
      >
        <i :class="[action.icon, 'quick-action-btn__icon']" aria-hidden="true" />
      </button>
    </div>
  </template>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import Tooltip from 'primevue/tooltip'
import { useUserStore } from '@/stores/user'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { useUiTriggersStore } from '@/stores/uiTriggers'
import { resolveQuickActions, type QuickActionDef } from '@/shared/nav/quickActionRegistry'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'

interface Props {
  tooltipOptions: (value: string) => OrbitaTooltipOptions
}

defineProps<Props>()

const { t } = useI18n()
const vTooltip = Tooltip
const router = useRouter()
const userStore = useUserStore()
const themeStore = useThemeStore()
const layoutStore = useLayoutStore()
const uiTriggers = useUiTriggersStore()

const resolvedActions = computed<QuickActionDef[]>(() =>
  resolveQuickActions(userStore.getNavQuickActions),
)

/** Map drawer keys to their owning route so we navigate there first */
const DRAWER_ROUTES: Record<string, string> = {
  deal_create: '/deals',
  contact_create: '/contacts',
}

function execute(action: QuickActionDef): void {
  switch (action.actionType) {
    case 'drawer':
      if (action.drawerKey) {
        // Navigate to the owning page then fire the trigger.
        // The page watcher runs with { immediate: true } so it picks up the
        // trigger both on post-navigation mount and when already on the page.
        const route = DRAWER_ROUTES[action.drawerKey]
        if (route) {
          void router.push(route)
        }
        uiTriggers.triggerDrawer(action.drawerKey)
      }
      break
    case 'inline':
      if (action.key === 'toggle_theme') {
        themeStore.setTheme(themeStore.theme === 'light' ? 'dark' : 'light')
      } else if (action.key === 'open_search') {
        layoutStore.openCommandPalette()
      }
      break
    case 'route':
    default:
      if (action.route) {
        void router.push(action.route)
      }
      break
  }
}
</script>

<style lang="scss" scoped>
.quick-action-btn-wrap {
  display: inline-flex;
}

.quick-action-btn {
  width: 2.75rem;
  height: 2.75rem;
  border: 1px solid transparent;
  border-radius: $radius-md;
  background: transparent;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  padding: 0;
  transition:
    background-color $transition-fast,
    border-color $transition-fast;

  &:hover {
    background: $surface-100;
    border-color: rgba($surface-900, 0.08);
  }

  &:focus-visible {
    outline: 2px solid $primary;
    outline-offset: 2px;
  }

  &__icon {
    font-size: 1rem;
    color: $surface-700;
    line-height: 1;
  }
}

// Dark mode
:global(.app-dark) {
  .quick-action-btn {
    &:hover {
      background: $surface-800;
      border-color: rgba($surface-100, 0.1);
    }

    &__icon {
      color: $surface-300;
    }
  }
}
</style>
