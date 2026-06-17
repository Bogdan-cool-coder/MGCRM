<template>
  <!--
    Orbita — floating navigation dock.
    Layout order (H, left→right): [OrbitaPanel (nav + actions)] [OrbitaToggle (grip | + | rotate)]
    Layout order (V, top→bottom): same logical order; toggle anchor is at the bottom.
    The "+" button is the ANCHOR for drag pivot and rotate pivot.
  -->
  <div
    ref="orbitaRef"
    :class="[
      'orbita',
      `orbita--${orientationClass}`,
      { 'is-collapsed': layoutStore.orbitCollapsed, 'is-dragging': isDragging },
    ]"
    :data-orientation="layoutStore.orbitOrientation"
    :style="orbitaStyle"
  >
    <!-- Panel: nav + actions (rendered BEFORE toggle so it appears left/top) -->
    <OrbitaPanel
      ref="orbitaPanelRef"
      :collapsed="layoutStore.orbitCollapsed"
      :direction="panelDirection"
      :nav-items="resolvedNavItems"
      :orientation="layoutStore.orbitOrientation"
      :current-position="layoutStore.orbitPos"
      @navigate="navigateTo"
    >
      <template #actions>
        <!-- User-configured quick actions (from profile) -->
        <QuickActionsCluster :tooltip-options="tooltipOptions" />
        <NotificationsButton
          ref="notificationsRef"
          :tooltip-options="tooltipOptions"
          @toggle-request="handleOverlayToggle('notifications', $event)"
          @visibility-change="handleOverlayVisibility('notifications', $event)"
        />
        <UserProfileButton
          ref="userProfileRef"
          :tooltip-options="tooltipOptions"
          @toggle-request="handleOverlayToggle('profile', $event)"
          @visibility-change="handleOverlayVisibility('profile', $event)"
        />
      </template>
    </OrbitaPanel>

    <!-- Toggle (anchor): drag grip | +×toggle | rotate satellite -->
    <!-- Rendered AFTER panel so it sits at the far right (H) or bottom (V) -->
    <OrbitaToggle
      :icon="collapseToggleIcon"
      :move-label="t('orbita.drag')"
      :toggle-label="layoutStore.orbitCollapsed ? t('orbita.expand') : t('orbita.collapse')"
      :orientation="layoutStore.orbitOrientation"
      :tooltip-options="tooltipOptions"
      :is-dragging="isDragging"
      @toggle="toggleCollapsed"
      @start-drag="startDrag"
      @toggle-orientation="handleRotate"
      @grip-keydown="onGripKeyDown"
    />
  </div>
</template>

<script setup lang="ts">
import { computed, nextTick, ref, type Ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useLayoutStore } from '@/stores/layout'
import { useUserStore } from '@/stores/user'
import { prototypeNavItems, filterNavByRole } from '@/shared/nav/navItems'
import OrbitaPanel  from './OrbitaPanel.vue'
import OrbitaToggle from './OrbitaToggle.vue'
import NotificationsButton from './NotificationsButton.vue'
import UserProfileButton   from './UserProfileButton.vue'
import QuickActionsCluster from './QuickActionsCluster.vue'
import { useOrbitaDrag }           from './composables/useOrbitaDrag'
import { useOrbitaOverlays }       from './composables/useOrbitaOverlays'
import { useOrbitaPanelDirection } from './composables/useOrbitaPanelDirection'
import { useOrbitaTooltip }        from './composables/useOrbitaTooltip'
import type { OrbitaNavItem, OrbitaOverlayControl } from './types'

const { t } = useI18n()
const route = useRoute()
const router = useRouter()
const layoutStore = useLayoutStore()
const userStore   = useUserStore()
const { tooltipOptions } = useOrbitaTooltip()

const orbitaRef      = ref<HTMLElement | null>(null)
const orbitaPanelRef = ref<InstanceType<typeof OrbitaPanel> | null>(null)

// ─── Overlay sub-component refs (for mutual exclusion) ────────────────────────
const notificationsRef = ref<(OrbitaOverlayControl & InstanceType<typeof NotificationsButton>) | null>(null)
const userProfileRef   = ref<(OrbitaOverlayControl & InstanceType<typeof UserProfileButton>) | null>(null)

// ─── Nav items resolved from shared source ────────────────────────────────────
const resolvedNavItems = computed<OrbitaNavItem[]>(() => {
  const role     = userStore.getUserRole ?? null
  const filtered = filterNavByRole(prototypeNavItems, role)
  return filtered.map((item) => ({
    key:      item.key,
    route:    item.route,
    icon:     item.icon,
    ariaLabel: t(item.labelKey),
    isActive:  route.path === item.route || route.path.startsWith(`${item.route}/`),
  }))
})

// ─── Computed helpers ─────────────────────────────────────────────────────────
const collapseToggleIcon = computed(() =>
  layoutStore.orbitCollapsed ? 'pi pi-plus' : 'pi pi-times',
)

const isCollapsed         = computed(() => layoutStore.orbitCollapsed)
const currentOrientation  = computed(() => layoutStore.orbitOrientation)
const currentPosition     = computed(() => layoutStore.orbitPos)

// 'horizontal' → class 'top', 'vertical' → class 'left'  (mirrors Vizion naming)
const orientationClass = computed(() =>
  layoutStore.orbitOrientation === 'horizontal' ? 'top' : 'left',
)

// ─── Panel ref forwarded to panel direction composable ────────────────────────
const panelRef = computed(() => orbitaPanelRef.value?.panelRef ?? null)

// ─── Drag (Slice 3: magnet snap + keyboard nudge) ─────────────────────────────
// NOTE: useOrbitaDrag already calls scheduleClamp() on mount — no additional
//       onMounted re-clamp needed here (removed to prevent duplicate clamping).
const { startDrag, onGripKeyDown, orbitaStyle, isDragging } = useOrbitaDrag({
  collapsed:           isCollapsed,
  currentOrientation,
  currentPosition,
  orbitaRef,
  setPosition: layoutStore.setOrbitPos,
})

// ─── Panel direction (edge-aware) ─────────────────────────────────────────────
const { panelDirection } = useOrbitaPanelDirection({
  collapsed:          isCollapsed,
  currentOrientation,
  currentPosition,
  panelRef,
  orbitaRef,
})

// ─── Overlay mutual exclusion (profile ↔ notifications) ──────────────────────
// Wires useOrbitaOverlays so only one overlay can be open at a time.
// Sub-components expose syncPopover + realign via defineExpose (OrbitaOverlayControl).
// Cast needed because component instance type is a superset of OrbitaOverlayControl.
const notificationsControlRef = notificationsRef as Ref<OrbitaOverlayControl | null>
const userProfileControlRef   = userProfileRef   as Ref<OrbitaOverlayControl | null>
const { handleOverlayToggle, handleOverlayVisibility } = useOrbitaOverlays({
  route,
  controls: {
    notifications: notificationsControlRef,
    profile:       userProfileControlRef,
  },
  closeWhen: isCollapsed,
})

// ─── Rotation: pivot around "+" anchor center ─────────────────────────────────
function handleRotate() {
  const orbitaEl = orbitaRef.value
  if (!orbitaEl || typeof window === 'undefined') {
    layoutStore.setOrbitOrientation(
      layoutStore.orbitOrientation === 'horizontal' ? 'vertical' : 'horizontal',
    )
    return
  }

  // Find the toggle element (last child = OrbitaToggle)
  const toggleEl = orbitaEl.querySelector<HTMLElement>('.orbita-toggle')
  if (!toggleEl) {
    layoutStore.setOrbitOrientation(
      layoutStore.orbitOrientation === 'horizontal' ? 'vertical' : 'horizontal',
    )
    return
  }

  // Capture current pivot point: center of the toggle/anchor before reflow
  const toggleRect = toggleEl.getBoundingClientRect()
  const pivotX = toggleRect.left + toggleRect.width  / 2
  const pivotY = toggleRect.top  + toggleRect.height / 2

  // Flip orientation
  const newOrientation = layoutStore.orbitOrientation === 'horizontal' ? 'vertical' : 'horizontal'
  layoutStore.setOrbitOrientation(newOrientation)

  // After reflow, shift Orbita so the toggle stays at the same viewport position
  void nextTick().then(() => {
    requestAnimationFrame(() => {
      const orbitaRect  = orbitaEl.getBoundingClientRect()
      const toggleElNew = orbitaEl.querySelector<HTMLElement>('.orbita-toggle')
      if (!toggleElNew) return

      const newToggleRect = toggleElNew.getBoundingClientRect()
      // Where the new toggle IS vs where we want it (pivot)
      const newPivotX = newToggleRect.left + newToggleRect.width  / 2
      const newPivotY = newToggleRect.top  + newToggleRect.height / 2

      const dx = pivotX - newPivotX
      const dy = pivotY - newPivotY

      const currentOrbitaPos = layoutStore.orbitPos ?? {
        top:  orbitaRect.top,
        left: orbitaRect.left,
      }

      const newPos = {
        top:  currentOrbitaPos.top  + dy,
        left: currentOrbitaPos.left + dx,
      }

      // Clamp to viewport
      const vw = window.innerWidth
      const vh = window.innerHeight
      const newRect = orbitaEl.getBoundingClientRect()
      const clamped = {
        top:  Math.min(Math.max(newPos.top,  8), vh - newRect.height - 8),
        left: Math.min(Math.max(newPos.left, 8), vw - newRect.width  - 8),
      }
      layoutStore.setOrbitPos(clamped)
    })
  })
}

// ─── Actions ──────────────────────────────────────────────────────────────────
function navigateTo(path: string) {
  const isActive = route.path === path || route.path.startsWith(`${path}/`)
  if (!isActive) void router.push(path)
}

function toggleCollapsed() {
  layoutStore.toggleOrbitCollapsed()
}

// ─── Mount: re-clamp handled by useOrbitaDrag.scheduleClamp() on mount.
// Duplicate onMounted re-clamp removed (Срез 4 PM fix 4c).

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
  // Default cursor; drag grip has cursor:grab; isDragging overrides to grabbing
  cursor: default;

  // Horizontal mode (H): toggle anchor at the RIGHT END
  // Panel renders first in DOM → appears left, toggle renders last → appears right.
  &--top {
    flex-direction: row;
    // Default position: bottom-center area (overridden by orbitaStyle when persisted)
    bottom: 1.5rem;
    left: 50%;
    transform: translateX(-50%);
  }

  // Vertical mode (V): toggle anchor at the BOTTOM
  // Panel top, toggle last = bottom.
  &--left {
    flex-direction: column;
    // Default position: right-center (overridden by orbitaStyle when persisted)
    right: 1.5rem;
    top: 50%;
    transform: translateY(-50%);
  }

  // When position is set via orbitaStyle (top/left absolute), override defaults
  &[style*='top:'] {
    bottom: auto;
    right: auto;
    transform: none;
  }

  // During drag: grabbing cursor everywhere on Orbita
  &.is-dragging,
  &.is-dragging * {
    cursor: grabbing !important;
  }
}

@media (max-width: 767px) {
  .orbita--top {
    bottom: 0.75rem;
  }
  .orbita--left {
    right: 0.75rem;
  }
}

// Accessibility: reduce motion
@media (prefers-reduced-motion: reduce) {
  .orbita,
  .orbita * {
    transition: none !important;
    animation: none !important;
  }
}
</style>
