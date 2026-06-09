<template>
  <div
    ref="toolboxRef"
    :class="[
      'toolbox',
      `toolbox--${layoutStore.toolboxPlacement}`,
      { 'is-collapsed': layoutStore.toolboxCollapsed },
    ]"
    :data-placement="layoutStore.toolboxPlacement"
    :style="toolboxStyle"
    @pointerdown="startDrag"
  >
    <ToolboxToggle
      :icon="collapseToggleIcon"
      :move-label="t('move')"
      :toggle-label="layoutStore.toolboxCollapsed ? t('expand') : t('collapse')"
      :placement="layoutStore.toolboxPlacement"
      :tooltip-options="tooltipOptions"
      @toggle="toggleToolboxCollapsed"
      @start-drag="startDrag"
    />

    <ToolboxPanel
      ref="toolboxPanelRef"
      :collapsed="layoutStore.toolboxCollapsed"
      :direction="panelDirection"
      :is-route-active="isRouteActive"
      :nav-items="navItems"
      :next-placement="nextToolboxPlacement"
      :placement="layoutStore.toolboxPlacement"
      :placement-toggle-icon="placementToggleIcon"
      :placement-toggle-label="placementToggleLabel"
      :tooltip-options="tooltipOptions"
      @navigate="navigateTo"
      @toggle-placement="toggleToolboxPlacement"
    >
      <template #actions>
        <HomeStar compact :tooltip-options="null" />
        <MiniChatWidget
          v-if="canUseMiniChat"
          :ref="setOverlayControlRef('miniChat')"
          compact
          :tooltip-options="tooltipOptions(t('miniChat'))"
          @toggle-request="handleOverlayToggle('miniChat', $event)"
          @visibility-change="handleOverlayVisibility('miniChat', $event)"
        />
        <CompanySwitcher
          v-if="showCompanySwitcher"
          v-model:modal-visible="companiesModalVisible"
          :ref="setOverlayControlRef('company')"
          compact
          :tooltip-options="tooltipOptions(t('company'))"
          @toggle-request="handleOverlayToggle('company', $event)"
          @visibility-change="handleOverlayVisibility('company', $event)"
        />
        <ProfileMenu
          :ref="setOverlayControlRef('profile')"
          compact
          :tooltip-options="tooltipOptions(userStore.getUser?.name ?? 'Profile')"
          @toggle-request="handleOverlayToggle('profile', $event)"
          @visibility-change="handleOverlayVisibility('profile', $event)"
        />
      </template>
    </ToolboxPanel>
  </div>

  <CompanyManagementModal v-model="companiesModalVisible" />
</template>

<script setup lang="ts">
import { computed, nextTick, ref, watch } from 'vue'
import type { ComponentPublicInstance } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { CompanyManagementModal, CompanySwitcher } from '@/components/Company'
import ProfileMenu from '@/components/ProfileMenu'
import { HomeStar } from '@/components/HomeStar'
import { MiniChatWidget } from '@/components/chat'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { useLayoutStore } from '@/stores/layout'
import { useUserStore } from '@/stores/user'
import {
  canAccessCompanySection,
  canManageCompanies,
  canUseDocuments as hasDocumentsCapability,
  canUseMiniChat as hasMiniChatCapability,
} from '@/shared/auth/capabilities'
import ToolboxPanel from './ToolboxPanel.vue'
import ToolboxToggle from './ToolboxToggle.vue'
import { useToolboxDrag } from './composables/useToolboxDrag'
import { useToolboxOverlays } from './composables/useToolboxOverlays'
import { useToolboxPanelDirection } from './composables/useToolboxPanelDirection'
import { useToolboxTooltip } from './composables/useToolboxTooltip'
import type { ToolboxNavItem, ToolboxOverlayControl, ToolboxOverlayName } from './types'
import en from './locale/en.json'
import ru from './locale/ru.json'

const route = useRoute()
const router = useRouter()
const userStore = useUserStore()
const layoutStore = useLayoutStore()
const { t } = useLocalI18n({ en, ru })
const { tooltipOptions } = useToolboxTooltip()

const companiesModalVisible = ref(false)
const toolboxRef = ref<HTMLElement | null>(null)
const toolboxPanelRef = ref<InstanceType<typeof ToolboxPanel> | null>(null)
const overlayControls = {
  company: ref<ToolboxOverlayControl | null>(null),
  profile: ref<ToolboxOverlayControl | null>(null),
  miniChat: ref<ToolboxOverlayControl | null>(null),
}

const canAccessCompany = computed(() => {
  return canAccessCompanySection(userStore.getUserRole)
})

const canUseMiniChat = computed(() => hasMiniChatCapability(userStore.getUserRole))
const canShowDocuments = computed(() => hasDocumentsCapability(userStore.getUserRole))
const canManageCompanyDirectory = computed(() => canManageCompanies(userStore.getUserRole))

const showCompanySwitcher = computed(
  () => userStore.getAvailableCompanyIds.length > 1 || canManageCompanyDirectory.value,
)

const collapseToggleIcon = 'pi pi-bars'

const nextToolboxPlacement = computed(() =>
  layoutStore.toolboxPlacement === 'top' ? 'left' : 'top',
)

const placementToggleLabel = computed(() =>
  nextToolboxPlacement.value === 'top' ? t('placementTop') : t('placementLeft'),
)

const placementToggleIcon = 'pi pi-sync'
const currentPlacement = computed(() => layoutStore.toolboxPlacement)
const currentPosition = computed(() => layoutStore.getToolboxPosition)
const isCollapsed = computed(() => layoutStore.toolboxCollapsed)
const panelRef = computed(() => toolboxPanelRef.value?.panelRef ?? null)

const navItems = computed<ToolboxNavItem[]>(() => {
  const items: Array<ToolboxNavItem | null> = [
    {
      key: 'dashboards',
      path: '/dashboards',
      icon: 'pi-th-large',
      ariaLabel: t('dashboards'),
    },
    {
      key: 'reports',
      path: '/reports',
      icon: 'pi-chart-bar',
      ariaLabel: t('reports'),
    },
    canShowDocuments.value
      ? {
          key: 'documents',
          path: '/documents',
          icon: 'pi-file-pdf',
          ariaLabel: t('documents'),
        }
      : null,
    canAccessCompany.value
      ? {
          key: 'company',
          path: '/company',
          icon: 'pi-building',
          ariaLabel: t('company'),
        }
      : null,
  ]

  return items.filter((item): item is ToolboxNavItem => item !== null)
})

const isRouteActive = (path: string) => route.path === path || route.path.startsWith(`${path}/`)

const navigateTo = (path: string) => {
  if (!isRouteActive(path)) {
    void router.push(path)
  }
}

const toggleToolboxPlacement = () => {
  layoutStore.setToolboxPlacement(nextToolboxPlacement.value)
}

const toggleToolboxCollapsed = () => {
  layoutStore.toggleToolboxCollapsed()
  handleOverlayVisibility('company', false)
  handleOverlayVisibility('profile', false)
  handleOverlayVisibility('miniChat', false)
}

const setOverlayControlRef =
  (name: ToolboxOverlayName) => (value: Element | ComponentPublicInstance | null) => {
    overlayControls[name].value = value as ToolboxOverlayControl | null
  }

const { handleOverlayToggle, handleOverlayVisibility } = useToolboxOverlays({
  route,
  controls: overlayControls,
  closeWhen: companiesModalVisible,
})

const { startDrag, toolboxStyle } = useToolboxDrag({
  collapsed: isCollapsed,
  currentPlacement,
  currentPosition,
  toolboxRef,
  setPositionByPlacement: layoutStore.setToolboxPositionByPlacement,
})

const { panelDirection } = useToolboxPanelDirection({
  collapsed: isCollapsed,
  currentPlacement,
  currentPosition,
  panelRef,
  toolboxRef,
})

// ─── re-anchor open overlays after the Toolbox moves ───────────────────
// PrimeVue Popover anchors once at `show()` and never recomputes unless the
// window scrolls or resizes. The Toolbox itself, however, is draggable and
// can flip placements (top ↔ left) on user action — when that happens, an
// open overlay (CompanySwitcher / ProfileMenu / MiniChat) is left floating
// at its old viewport coordinates while its trigger button moves with the
// Toolbox. We watch the position + placement, then ask whichever overlay is
// currently open to re-run `alignOverlay()` against its (now-moved) anchor.
//
// `nextTick` matters: when `currentPosition` mutates, the new inline `style`
// on the Toolbox root is applied in the same flush as the watcher fires, but
// the browser hasn't painted yet. We wait one tick + rAF (PrimeVue's own
// positioning math reads getBoundingClientRect from the trigger button), so
// the trigger is at its final pixel position before we recompute.
const realignOpenOverlay = () => {
  void nextTick().then(() => {
    requestAnimationFrame(() => {
      ;(['company', 'profile', 'miniChat'] as const).forEach((name) => {
        overlayControls[name].value?.realign()
      })
    })
  })
}

watch([currentPosition, currentPlacement], () => {
  realignOpenOverlay()
})
</script>

<style lang="scss" scoped>
.toolbox {
  position: fixed;
  z-index: $z-toolbox;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  touch-action: none;
  user-select: none;
  cursor: grab;

  // ─── vertical offset from viewport top (P1 fix v3, P2 cleanup) ─────────
  // The Toolbox is position:fixed in the top-right corner. Its panel
  // (.toolbox-panel) is expanded by default (toolboxCollapsed defaults to
  // false) and extends ~280-310px to the left of the toggle. On pages with
  // a header in the same top strip (ReportPage's .report-header), the panel
  // physically overlaps header controls (SelectButton, filter button) and
  // intercepts their clicks because the panel has higher stacking context.
  //
  // Previous attempts that failed:
  //   v1: pointer-events:none on root with auto on children — the panel's
  //       interactive children (placement-toggle, nav buttons, action
  //       overlays) opt back into hit-testing, so they still ate clicks.
  //   v2: padding-inline-end: 4rem on .report-header — only displaced the
  //       leftmost panel button by 4rem; the panel as a whole is ~20rem
  //       wide, so the gap was insufficient and flex-wrap on the header
  //       further shuffled the rightmost controls back under the panel.
  //
  // Fix v3: drop the Toolbox below the page-header strip. A header is
  // typically ~3rem tall with 0.75–1rem of page padding above it (~4rem
  // bottom edge). 5rem gives clearance — the expanded panel sits entirely
  // below any standard page header, so no horizontal overlap is possible.
  //
  // P2 cleanup (2026-05-21): the 5rem offset was global, which pushed the
  // Toolbox unnecessarily low on pages with NO header (ReportsPage,
  // AiChatPage, UserSettings, etc.). The offset is now driven by the CSS
  // custom property `--toolbox-top-offset` (default `1rem`), and only
  // ReportPage (the single header-bearing surface) overrides it to `5rem`
  // on its root container. Other pages stay at the original `1rem`.
  // Mobile breakpoint has its own variable `--toolbox-top-offset-mobile`
  // (default `0.5rem`, also overridden by ReportPage).

  &--top {
    top: var(--toolbox-top-offset, 1rem);
    right: 1rem;
    flex-direction: row-reverse;
  }

  &--left {
    bottom: 1rem;
    left: 1rem;
    flex-direction: column-reverse;
    align-items: center;
  }
}

@media (max-width: 767px) {
  .toolbox {
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
  .toolbox--top {
    width: max-content;
    height: auto;
    overflow: visible;
  }
}
</style>
