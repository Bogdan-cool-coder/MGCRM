<template>
  <!--
    OrbitaToggle — the "anchor" widget at the far edge of Orbita.
    Layout (H horizontal mode, column):
      [grip dots]  ←  drag satellite (focusable, keyboard-nudge via @keydown)
      [÷ divider]
      [+ / × btn] ←  toggle collapse/expand
      [÷ divider]
      [↻ rotate]  ←  rotate satellite H↔V

    In V (row mode) the same order runs left→right.
  -->
  <div
    role="group"
    :class="['orbita-toggle', `orbita-toggle--${orientationClass}`, { 'is-dragging': isDragging }]"
  >
    <!-- Drag satellite (focusable for keyboard nudge) -->
    <div
      class="orbita-toggle__grip"
      tabindex="0"
      :aria-label="t('orbita.drag')"
      role="button"
      @pointerdown.stop="emit('start-drag', $event)"
      @keydown="emit('grip-keydown', $event)"
    >
      <span
        v-for="dot in gripDots"
        :key="dot"
        class="orbita-toggle__grip-dot"
        aria-hidden="true"
      />
    </div>

    <div class="orbita-toggle__divider" aria-hidden="true" />

    <!-- Main toggle button: collapse / expand -->
    <Button
      v-tooltip="tooltipOptions(toggleLabel)"
      class="orbita-toggle__button"
      :icon="icon"
      text
      :aria-label="toggleLabel"
      @click="emit('toggle')"
    />

    <div class="orbita-toggle__divider" aria-hidden="true" />

    <!-- Rotate satellite H↔V -->
    <Button
      v-tooltip="tooltipOptions(rotateLabel)"
      :class="['orbita-toggle__rotate', { 'orbita-toggle__rotate--vertical': orientation === 'vertical' }]"
      icon="pi pi-sync"
      text
      :aria-label="rotateLabel"
      @click="emit('toggle-orientation')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tooltip from 'primevue/tooltip'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOrientation } from './types'

interface Props {
  icon:           string
  toggleLabel:    string
  orientation:    OrbitaOrientation
  tooltipOptions: (value: string) => OrbitaTooltipOptions
  isDragging?:    boolean
}

const props = withDefaults(defineProps<Props>(), { isDragging: false })

const emit = defineEmits<{
  toggle:             []
  'start-drag':       [event: PointerEvent]
  'toggle-orientation': []
  'grip-keydown':     [event: KeyboardEvent]
}>()

const { t } = useI18n()
const vTooltip = Tooltip
const gripDots = Array.from({ length: 2 }, (_, index) => index)

// 'horizontal' uses column layout (top-placement), 'vertical' uses row layout
const orientationClass = computed(() =>
  props.orientation === 'horizontal' ? 'top' : 'left',
)

const rotateLabel = computed(() =>
  props.orientation === 'horizontal' ? t('orbita.rotateV') : t('orbita.rotateH'),
)
</script>

<style lang="scss" scoped>
@use './styles/tokens' as orbita;

.orbita-toggle {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: orbita.$orbita-surface-bg;
  border: orbita.$orbita-surface-border;
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  transition:
    border-color $transition-fast,
    box-shadow $transition-fast,
    background-color $transition-fast;

  &:hover {
    border-color: orbita.$orbita-surface-border-hover;
  }

  // Horizontal mode: toggle is column-oriented (buttons stacked vertically)
  &--top {
    flex-direction: column;
  }

  // Vertical mode: toggle is row-oriented (buttons side by side)
  &--left {
    flex-direction: row;
  }

  // Grabbing cursor during drag
  &.is-dragging {
    cursor: grabbing !important;
  }

  &__grip {
    display: grid;
    place-content: center;
    gap: 2px;
    flex-shrink: 0;
    position: relative;
    background: orbita.$orbita-surface-bg;
    color: $surface-500;
    cursor: grab;
    transition: color $transition-fast;
    border-radius: $radius-sm;

    &:hover {
      color: $surface-700;
    }

    &:focus-visible {
      outline: 2px solid $primary;
      outline-offset: 2px;
    }
  }

  &__grip-dot {
    width: 4px;
    height: 4px;
    border-radius: $radius-pill; // 999px
    background: currentColor;
    opacity: 0.8;
  }

  &__divider {
    flex-shrink: 0;
    background: $surface-300;
    pointer-events: none;
  }

  &__button {
    position: relative;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    border-radius: 0;
    background: transparent;
    color: $surface-700;
    padding: 0;
    box-shadow: none;
    -webkit-tap-highlight-color: transparent;

    &:hover,
    &:active {
      background: transparent;
      color: $surface-900;
      transform: none;
    }

    &:focus,
    &:focus-visible,
    &:focus-within {
      outline: none;
      box-shadow: none;
    }

    :deep(.p-button),
    :deep(.p-button:focus),
    :deep(.p-button:focus-visible),
    :deep(.p-button:active) {
      outline: none;
      box-shadow: none;
      background: transparent;
    }

    :deep(.p-button-icon) {
      font-size: $font-size-md; // 1rem
    }
  }

  &__rotate {
    position: relative;
    z-index: 2;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: none;
    background: transparent;
    color: $surface-500;
    padding: 0;
    box-shadow: none;
    border-radius: 0;

    &:hover,
    &:active {
      background: transparent;
      color: $surface-900;
    }

    &:focus-visible {
      outline: 2px solid $primary;
      outline-offset: 2px;
    }

    :deep(.p-button-icon) {
      font-size: $font-size-sm; // snap from 0.85rem (13.6px→14px)
    }

    // In vertical mode, rotate icon 90deg to indicate axis
    &--vertical :deep(.p-button-icon) {
      transform: rotate(90deg);
    }
  }
}

// ─── Horizontal mode (--top) sizing ──────────────────────────────────────────
.orbita-toggle--top {
  .orbita-toggle__button {
    width: orbita.$orbita-control-size;
    height: orbita.$orbita-toggle-main-size;
  }

  .orbita-toggle__grip {
    width: orbita.$orbita-control-size;
    height: orbita.$orbita-grip-size;
    grid-template-columns: repeat(2, 4px);
    grid-template-rows: 4px;
    padding-top: 0.25rem;
  }

  .orbita-toggle__divider {
    height: 1px;
    width: auto;
    margin: 0 orbita.$orbita-divider-inset;
    align-self: stretch;
  }

  .orbita-toggle__rotate {
    width: orbita.$orbita-control-size;
    height: orbita.$orbita-grip-size + 0.25rem;
    padding-bottom: 0.25rem;
  }
}

// ─── Vertical mode (--left) sizing ───────────────────────────────────────────
.orbita-toggle--left {
  .orbita-toggle__button {
    width: orbita.$orbita-toggle-main-size;
    height: orbita.$orbita-control-size;
  }

  .orbita-toggle__grip {
    width: orbita.$orbita-grip-size;
    height: orbita.$orbita-control-size;
    grid-template-columns: 4px;
    grid-template-rows: repeat(2, 4px);
    padding-left: 0.25rem;
  }

  .orbita-toggle__divider {
    width: 1px;
    align-self: stretch;
    margin: orbita.$orbita-divider-inset 0;
  }

  .orbita-toggle__rotate {
    width: orbita.$orbita-grip-size + 0.25rem;
    height: orbita.$orbita-control-size;
    padding-right: 0.25rem;
  }
}

// Accessibility: forced-colors (high contrast mode)
@media (forced-colors: active) {
  .orbita-toggle {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    border: 1px solid ButtonText; // a11y forced-colors system keyword
  }

  .orbita-toggle__grip-dot {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    background: ButtonText; // a11y forced-colors system keyword
  }
}
</style>
