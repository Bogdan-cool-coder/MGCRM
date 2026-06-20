<template>
  <!--
    OrbitaPanel — the content panel of Orbita.
    Button order (H, left→right): [nav items...] [divider] [notifications] [user]
    In V (top→bottom): same logical order.

    Labels expand edge-aware via CSS var(--orbita-label-side):
      - V near left edge  → labels expand rightward (start)
      - V near right edge → labels expand leftward (end)
      - H (any position)  → labels expand in-place; flex reflows symmetrically
  -->
  <div
    ref="panelRef"
    class="orbita-panel"
    :data-orientation="orientation"
    :data-direction="direction"
    :data-label-side="labelSide"
    :class="{ 'is-collapsed': collapsed }"
    role="none"
  >
    <!-- Navigation items from shared navItems -->
    <nav
      class="orbita-panel__group orbita-panel__group--nav"
      :aria-label="t('orbita.navLabel')"
    >
      <button
        v-for="item in navItems"
        :key="item.key"
        :class="[
          'orbita-panel__btn',
          'orbita-panel__btn--nav',
          { 'is-active': item.isActive },
        ]"
        :aria-label="item.ariaLabel"
        :aria-current="item.isActive ? 'page' : undefined"
        @mouseenter="prefetch(item.route)"
        @focus="prefetch(item.route)"
        @click="emit('navigate', item.route)"
      >
        <i :class="item.icon" class="orbita-panel__btn-icon" aria-hidden="true" />
        <span class="orbita-panel__btn-label">{{ item.ariaLabel }}</span>
      </button>
    </nav>

    <div class="orbita-panel__divider" role="separator" />

    <!-- Action slots: notifications, user profile -->
    <!-- labelSide is passed as scoped slot so action components can render
         inline labels (same expand pattern as nav buttons) instead of tooltips -->
    <div class="orbita-panel__group orbita-panel__group--actions">
      <slot name="actions" :label-side="labelSide" :orientation="orientation" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useNavPrefetch } from './composables/useNavPrefetch'
import type { OrbitaNavItem, OrbitaOrientation, OrbitaPanelDirection, OrbitaPosition } from './types'

interface Props {
  collapsed:    boolean
  direction:    OrbitaPanelDirection
  navItems:     OrbitaNavItem[]
  orientation:  OrbitaOrientation
  currentPosition: OrbitaPosition | null
}

const props = defineProps<Props>()

const emit = defineEmits<{
  navigate: [route: string]
}>()

const { t } = useI18n()
const panelRef = ref<HTMLElement | null>(null)
const { prefetch } = useNavPrefetch()

/**
 * Edge-aware label side for vertical orientation.
 * V near left edge (left < viewport/2) → labels open to the right ('start').
 * V near right edge → labels open to the left ('end').
 * H → 'center' (in-place symmetric expansion via flex).
 */
const labelSide = computed<'start' | 'end' | 'center'>(() => {
  if (props.orientation === 'horizontal') return 'center'
  if (typeof window === 'undefined') return 'start'
  const left = props.currentPosition?.left ?? 0
  return left < window.innerWidth / 2 ? 'start' : 'end'
})

defineExpose({ panelRef, labelSide })
</script>

<style lang="scss" scoped>
@use './styles/tokens' as orbita;

// ─── Panel container ────────────────────────────────────────────────────────
.orbita-panel {
  position: absolute;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.25rem;
  padding: 0.5rem 0.625rem;
  border: orbita.$orbita-surface-border;
  border-radius: $radius-lg;
  background: orbita.$orbita-surface-bg;
  box-shadow: $shadow-lg;
  transition:
    opacity $transition-fast,
    transform $transition-fast;

  &.is-collapsed {
    opacity: 0;
    pointer-events: none;
    user-select: none;
  }

  &__group {
    display: flex;
    align-items: center;
    gap: 0.25rem;
  }

  &__divider {
    width: 1px;
    align-self: stretch;
    background: $surface-200;
    flex-shrink: 0;
    margin: 0 0.125rem;
  }

  // ─── Nav button (icon + expandable label) ─────────────────────────────────
  //
  // Rest state: button is exactly $orbita-control-size square; label is
  // invisible (max-width:0, opacity:0, padding:0) so zero pixels bleed through.
  // Hover/focus: button expands via max-width + label fades in; neighbours are
  // pushed aside by flex reflow (no absolute positioning needed).
  &__btn {
    position: relative;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid transparent;
    border-radius: $radius-md;
    background: transparent;
    color: $surface-700;
    cursor: pointer;
    padding: 0;
    height: orbita.$orbita-control-size;
    // min-width pins the icon square; max-width drives expansion on hover.
    // width:auto so max-width is the sole constraint (no competing fixed width).
    width: auto;
    min-width: orbita.$orbita-control-size;
    max-width: orbita.$orbita-control-size;
    overflow: hidden;
    transition:
      max-width 0.18s ease-out,
      background-color $transition-fast,
      border-color $transition-fast,
      color $transition-fast,
      transform $transition-fast;

    &-icon {
      flex-shrink: 0;
      font-size: 1rem;
      line-height: 1;
      // Fixed square so icon is always centered inside the button at rest
      width: orbita.$orbita-control-size;
      min-width: orbita.$orbita-control-size;
      text-align: center;
    }

    &-label {
      font-size: 13px;
      font-weight: $font-weight-medium;
      white-space: nowrap;
      pointer-events: none;
      // *** KEY FIX: label occupies zero space at rest ***
      // max-width+opacity transition is the source of truth for reveal;
      // overflow:hidden on the label itself ensures no bleed from padding.
      display: block;
      max-width: 0;
      overflow: hidden;
      opacity: 0;
      // Padding also starts at 0 so no gap bleeds between icon and invisible text
      padding-inline: 0;
      transition:
        max-width 0.18s ease-out,
        opacity 0.14s ease-out,
        padding-inline 0.18s ease-out;
    }

    &:hover,
    &:focus-visible {
      max-width: 12rem;       // wide enough for any label
      background: $surface-100;
      color: $surface-900;
      transform: translateY(-1px);
      border-color: rgba($surface-900, 0.08);

      .orbita-panel__btn-label {
        max-width: 10rem;
        opacity: 1;
        padding-inline-end: 0.625rem;
      }
    }

    &:focus-visible {
      outline: 2px solid $primary;
      outline-offset: 2px;
    }

    &.is-active {
      background: rgba($primary, 0.12);
      border-color: rgba($primary, 0.18);
      color: $primary;
    }

    &.is-active:hover {
      background: rgba($primary, 0.18);
    }
  }
}

// ─── Horizontal (top placement) ─── panel position relative to toggle ───────
.orbita-panel[data-orientation='horizontal'][data-direction='start'] {
  top: 50%;
  right: calc(100% - #{orbita.$orbita-toggle-overlap});
  transform: translateY(-50%);
  transform-origin: right center;
  flex-direction: row;
  // In-place symmetric label expansion: center the group
  .orbita-panel__group--nav {
    justify-content: center;
  }
}

.orbita-panel[data-orientation='horizontal'][data-direction='end'] {
  top: 50%;
  left: calc(100% - #{orbita.$orbita-toggle-overlap});
  transform: translateY(-50%);
  transform-origin: left center;
  flex-direction: row;
  .orbita-panel__group--nav {
    justify-content: center;
  }
}

.orbita-panel[data-orientation='horizontal'].is-collapsed[data-direction='start'] {
  transform: translateY(-50%) translateX(0.5rem) scaleX(0.96);
}

.orbita-panel[data-orientation='horizontal'].is-collapsed[data-direction='end'] {
  transform: translateY(-50%) translateX(-0.5rem) scaleX(0.96);
}

// ─── Vertical (left/right placement) ─────────────────────────────────────────
.orbita-panel[data-orientation='vertical'] {
  flex-direction: column;

  .orbita-panel__group,
  .orbita-panel__group--nav,
  .orbita-panel__group--actions {
    flex-direction: column;
  }

  .orbita-panel__divider {
    width: 100%;
    height: 1px;
    align-self: auto;
    margin: 0.125rem 0;
  }

  // Button layout: icon left, label expands to the right or left based on edge.
  // In vertical mode we override width:auto (unset fixed width from base) so the
  // max-width transition can drive expansion without fighting a fixed width value.
  .orbita-panel__btn {
    width: auto;
    min-width: orbita.$orbita-control-size;
    max-width: orbita.$orbita-control-size;  // still icon-only at rest
    justify-content: flex-start;
  }
}

// Vertical labels: open toward center of screen
.orbita-panel[data-orientation='vertical'][data-label-side='start'] {
  // Near left edge → labels expand to the right; icon stays pinned at left
  .orbita-panel__btn {
    flex-direction: row;
    &:hover,
    &:focus-visible {
      max-width: 12rem;
    }
  }
}

.orbita-panel[data-orientation='vertical'][data-label-side='end'] {
  // Near right edge → labels expand to the left (row-reverse); icon stays pinned at right
  .orbita-panel__btn {
    flex-direction: row-reverse;

    &:hover,
    &:focus-visible {
      max-width: 12rem;

      .orbita-panel__btn-label {
        // Override: padding goes to the start side (= left in row-reverse = visual right of label)
        padding-inline-end: 0;
        padding-inline-start: 0.625rem;
      }
    }
  }
}

// ─── Vertical panel anchor (edge-aware) ──────────────────────────────────────
//
// KEY: panel is positioned relative to .orbita (the flex container holding
// [OrbitaPanel, OrbitaToggle]). The toggle is the LAST child, so in V-mode
// the panel sits ABOVE (up) or BELOW (down) the toggle.
//
// label-side=start (left edge) → anchor left:0, panel grows rightward.
// label-side=end   (right edge)→ anchor right:0, panel grows leftward.
// NO translateX(-50%) — that was the source of symmetric expansion from centre.
// The icon column always stays flush with the edge of the orbita container.

// direction=up, left edge
.orbita-panel[data-orientation='vertical'][data-direction='up'][data-label-side='start'] {
  left: 0;
  right: auto;
  bottom: calc(100% - #{orbita.$orbita-toggle-overlap});
  transform-origin: left bottom;
}

// direction=up, right edge
.orbita-panel[data-orientation='vertical'][data-direction='up'][data-label-side='end'] {
  right: 0;
  left: auto;
  bottom: calc(100% - #{orbita.$orbita-toggle-overlap});
  transform-origin: right bottom;
}

// direction=down, left edge
.orbita-panel[data-orientation='vertical'][data-direction='down'][data-label-side='start'] {
  left: 0;
  right: auto;
  top: calc(100% - #{orbita.$orbita-toggle-overlap});
  transform-origin: left top;
}

// direction=down, right edge
.orbita-panel[data-orientation='vertical'][data-direction='down'][data-label-side='end'] {
  right: 0;
  left: auto;
  top: calc(100% - #{orbita.$orbita-toggle-overlap});
  transform-origin: right top;
}

// Collapse animation — vertical scaleY only, no X shift
.orbita-panel[data-orientation='vertical'].is-collapsed[data-direction='up'] {
  transform: translateY(0.5rem) scaleY(0.96);
}

.orbita-panel[data-orientation='vertical'].is-collapsed[data-direction='down'] {
  transform: translateY(-0.5rem) scaleY(0.96);
}

// ─── Responsive ───────────────────────────────────────────────────────────────
@media (max-width: 767px) {
  .orbita-panel {
    max-width: calc(100vw - 5rem);
  }
}

@media (max-width: 560px) {
  .orbita-panel[data-orientation='horizontal'][data-direction='start'],
  .orbita-panel[data-orientation='horizontal'][data-direction='end'] {
    top: 0;
    display: grid;
    grid-template-columns: repeat(2, max-content);
    justify-content: start;
    align-items: start;
    column-gap: 0.25rem;
    row-gap: 0.25rem;
    transform: none;
  }

  .orbita-panel[data-orientation='horizontal'][data-direction='start'] {
    right: calc(100% - #{orbita.$orbita-toggle-overlap});
    transform-origin: right top;
  }

  .orbita-panel[data-orientation='horizontal'][data-direction='end'] {
    left: calc(100% - #{orbita.$orbita-toggle-overlap});
    transform-origin: left top;
  }

  .orbita-panel[data-orientation='horizontal'] .orbita-panel__divider {
    display: none;
  }

  .orbita-panel[data-orientation='horizontal'] .orbita-panel__group--nav {
    grid-column: 1 / -1;
    justify-self: center;
  }
}

// ─── Accessibility ────────────────────────────────────────────────────────────
@media (prefers-reduced-motion: reduce) {
  .orbita-panel__btn,
  .orbita-panel__btn-label {
    transition: none !important;
  }
}

@media (forced-colors: active) {
  .orbita-panel {
    border: 1px solid ButtonText;
  }

  .orbita-panel__btn {
    &.is-active {
      border: 2px solid Highlight;
      color: HighlightText;
      background: Highlight;
    }
  }
}
</style>
