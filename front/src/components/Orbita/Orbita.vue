<template>
  <div
    ref="orbitaRef"
    :class="[
      'orbita',
      `orbita--${orientationClass}`,
      { 'is-collapsed': layoutStore.orbitCollapsed },
    ]"
    :data-orientation="layoutStore.orbitOrientation"
    :style="orbitaStyle"
    @pointerdown="startDrag"
  >
    <!-- Toggle button (anchor / +×) -->
    <OrbitaToggle
      :icon="collapseToggleIcon"
      :move-label="t('orbita.drag')"
      :toggle-label="layoutStore.orbitCollapsed ? t('orbita.expand') : t('orbita.collapse')"
      :orientation="layoutStore.orbitOrientation"
      :tooltip-options="tooltipOptions"
      @toggle="toggleCollapsed"
      @start-drag="startDrag"
    />

    <!-- Panel: nav + actions -->
    <OrbitaPanel
      ref="orbitaPanelRef"
      :collapsed="layoutStore.orbitCollapsed"
      :direction="panelDirection"
      :nav-items="resolvedNavItems"
      :orientation="layoutStore.orbitOrientation"
      :tooltip-options="tooltipOptions"
      @navigate="navigateTo"
      @toggle-orientation="toggleOrientation"
    >
      <template #actions>
        <NotificationsButton :tooltip-options="tooltipOptions" />
        <UserProfileButton :tooltip-options="tooltipOptions" />
      </template>
    </OrbitaPanel>
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useLayoutStore } from '@/stores/layout'
import { useUserStore } from '@/stores/user'
import { prototypeNavItems, filterNavByRole } from '@/shared/nav/navItems'
import OrbitaPanel from './OrbitaPanel.vue'
import OrbitaToggle from './OrbitaToggle.vue'
import NotificationsButton from './NotificationsButton.vue'
import UserProfileButton from './UserProfileButton.vue'
import { useOrbitaDrag } from './composables/useOrbitaDrag'
import { useOrbitaPanelDirection } from './composables/useOrbitaPanelDirection'
import { useOrbitaTooltip } from './composables/useOrbitaTooltip'
import type { OrbitaNavItem } from './types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const layoutStore = useLayoutStore()
const userStore = useUserStore()
const { tooltipOptions } = useOrbitaTooltip()

const orbitaRef = ref<HTMLElement | null>(null)
const orbitaPanelRef = ref<InstanceType<typeof OrbitaPanel> | null>(null)

// ─── Nav items resolved from shared source ────────────────────────────────────
const resolvedNavItems = computed<OrbitaNavItem[]>(() => {
  const role = userStore.getUserRole ?? null
  const filtered = filterNavByRole(prototypeNavItems, role)
  return filtered.map((item) => ({
    key: item.key,
    route: item.route,
    icon: item.icon,
    ariaLabel: t(item.labelKey),
    isActive: route.path === item.route || route.path.startsWith(`${item.route}/`),
  }))
})

// ─── Toggle state ─────────────────────────────────────────────────────────────
const collapseToggleIcon = 'pi pi-bars'

const isCollapsed = computed(() => layoutStore.orbitCollapsed)
const currentOrientation = computed(() => layoutStore.orbitOrientation)
const currentPosition = computed(() => layoutStore.orbitPos)

// 'horizontal' → 'top' CSS class, 'vertical' → 'left' CSS class (mirrors Vizion naming)
const orientationClass = computed(() =>
  layoutStore.orbitOrientation === 'horizontal' ? 'top' : 'left',
)

// ─── Panel ref access ─────────────────────────────────────────────────────────
const panelRef = computed(() => orbitaPanelRef.value?.panelRef ?? null)

// ─── Drag ─────────────────────────────────────────────────────────────────────
const { startDrag, orbitaStyle } = useOrbitaDrag({
  collapsed: isCollapsed,
  currentOrientation,
  currentPosition,
  orbitaRef,
  setPosition: layoutStore.setOrbitPos,
})

// ─── Panel direction ──────────────────────────────────────────────────────────
const { panelDirection } = useOrbitaPanelDirection({
  collapsed: isCollapsed,
  currentOrientation,
  currentPosition,
  panelRef,
  orbitaRef,
})

// ─── Actions ──────────────────────────────────────────────────────────────────
function navigateTo(path: string) {
  const isActive = route.path === path || route.path.startsWith(`${path}/`)
  if (!isActive) void router.push(path)
}

function toggleCollapsed() {
  layoutStore.toggleOrbitCollapsed()
}

function toggleOrientation() {
  layoutStore.setOrbitOrientation(
    layoutStore.orbitOrientation === 'horizontal' ? 'vertical' : 'horizontal',
  )
}

// Re-anchor open overlays after Orbita moves (mirrors Vizion pattern)
const realignOpenOverlay = () => {
  void nextTick()
}

void realignOpenOverlay
</script>

<style lang="scss" scoped>
.orbita {
  position: fixed;
  z-index: $z-toolbox;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  touch-action: none;
  user-select: none;
  cursor: grab;

  // Horizontal mode — top-right corner (mirrors Vizion --top)
  &--top {
    top: var(--toolbox-top-offset, 1rem);
    right: 1rem;
    flex-direction: row-reverse;
  }

  // Vertical mode — bottom-left corner (mirrors Vizion --left)
  &--left {
    bottom: 1rem;
    left: 1rem;
    flex-direction: column-reverse;
    align-items: center;
  }
}

@media (max-width: 767px) {
  .orbita {
    &--top {
      top: var(--toolbox-top-offset-mobile, 0.5rem);
      right: 0.75rem;
    }

    &--left {
      left: 0.5rem;
      bottom: 0.5rem;
    }
  }
}

@media (max-width: 560px) {
  .orbita--top {
    width: max-content;
    height: auto;
    overflow: visible;
  }
}

// Accessibility: reduce motion
@media (prefers-reduced-motion: reduce) {
  .orbita * {
    transition: none !important;
    animation: none !important;
  }
}
</style>
