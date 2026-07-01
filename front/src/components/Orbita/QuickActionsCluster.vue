<template>
  <!--
    QuickActionsCluster — renders the user's saved quick-actions
    as compact icon-buttons inside the Orbita actions slot.
    Shown only when the user has at least one configured action.
    Uses inline-label expand pattern (same as nav buttons) instead of v-tooltip.
  -->
  <template v-if="resolvedActions.length > 0">
    <div
      v-for="action in resolvedActions"
      :key="action.key"
      class="quick-action-btn-wrap orbita-action-btn"
      :class="[
        panelOrientation === 'vertical'
          ? `orbita-action-btn--${labelSide}`
          : 'orbita-action-btn--h',
      ]"
    >
      <button
        class="quick-action-btn orbita-action-btn__trigger"
        :aria-label="t(action.labelKey)"
        @click="execute(action)"
      >
        <i :class="[action.icon, 'quick-action-btn__icon']" aria-hidden="true" />
      </button>
      <!-- Inline label — expands on hover, edge-aware -->
      <span class="orbita-action-btn__label" aria-hidden="true">{{ t(action.labelKey) }}</span>
    </div>
  </template>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useRouter } from 'vue-router'
import { useI18n } from 'vue-i18n'
import { useUserStore } from '@/stores/user'
import { useThemeStore } from '@/stores/theme'
import { useLayoutStore } from '@/stores/layout'
import { resolveQuickActions, type QuickActionDef } from '@/shared/nav/quickActionRegistry'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOrientation } from './types'

interface Props {
  tooltipOptions: (value: string) => OrbitaTooltipOptions
  /** Edge-aware label side forwarded from OrbitaPanel scoped slot */
  labelSide?: 'start' | 'end' | 'center'
  /** Panel orientation forwarded from OrbitaPanel scoped slot */
  panelOrientation?: OrbitaOrientation
}

defineProps<Props>()

const { t } = useI18n()
const router = useRouter()
const userStore = useUserStore()
const themeStore = useThemeStore()
const layoutStore = useLayoutStore()

const resolvedActions = computed<QuickActionDef[]>(() =>
  resolveQuickActions(userStore.getNavQuickActions),
)

function execute(action: QuickActionDef): void {
  switch (action.actionType) {
    case 'inline':
      if (action.key === 'toggle_theme') {
        themeStore.setTheme(themeStore.theme === 'light' ? 'dark' : 'light')
      } else if (action.key === 'open_search') {
        layoutStore.openCommandPalette()
      }
      break
    // 'drawer' type is deprecated (Wave 4). All creation actions in the registry
    // now use actionType: 'route' with an explicit route — they fall through to
    // the default branch. The 'drawer' branch is intentionally removed.
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
@use './styles/tokens' as orbita;

// ─── Inline-label action button wrapper ────────────────────────────────────
.orbita-action-btn {
  display: inline-flex;
  align-items: center;
  overflow: hidden;
  height: orbita.$orbita-control-size;
  min-width: orbita.$orbita-control-size;
  max-width: orbita.$orbita-control-size;
  border-radius: $radius-md;
  transition: max-width 0.18s ease-out;

  &:hover,
  &:focus-within {
    max-width: 14rem;

    .orbita-action-btn__label {
      max-width: 10rem;
      opacity: 1;
      padding-inline-end: 0.625rem;
    }
  }

  &--start {
    flex-direction: row;
  }

  &--end {
    flex-direction: row-reverse;

    &:hover,
    &:focus-within {
      .orbita-action-btn__label {
        padding-inline-end: 0;
        padding-inline-start: 0.625rem;
      }
    }
  }

  &--h,
  &--center {
    flex-direction: row;
  }
}

.orbita-action-btn__trigger {
  flex-shrink: 0;
  width: orbita.$orbita-control-size;
  min-width: orbita.$orbita-control-size;
}

.orbita-action-btn__label {
  font-size: $font-size-xs; // snap from 13px
  font-weight: $font-weight-medium;
  white-space: nowrap;
  pointer-events: none;
  display: block;
  max-width: 0;
  overflow: hidden;
  opacity: 0;
  padding-inline: 0;
  color: $surface-700;
  transition:
    max-width 0.18s ease-out,
    opacity 0.14s ease-out,
    padding-inline 0.18s ease-out;
}

// ─── Quick action button ────────────────────────────────────────────────────
.quick-action-btn-wrap {
  // Layout handled by orbita-action-btn above
}

.quick-action-btn {
  width: orbita.$orbita-control-size;
  height: orbita.$orbita-control-size;
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
    font-size: $font-size-md;
    color: $surface-700;
    line-height: 1;
  }
}

// ─── Dark mode ────────────────────────────────────────────────────────────────
:global(.app-dark) {
  .orbita-action-btn__label {
    color: $surface-300;
  }

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

// ─── Accessibility ────────────────────────────────────────────────────────────
@media (prefers-reduced-motion: reduce) {
  .orbita-action-btn,
  .orbita-action-btn__label {
    transition: none !important;
  }
}

@media (forced-colors: active) {
  .orbita-action-btn__label {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: ButtonText; // a11y forced-colors system keyword
  }
}
</style>
