<template>
  <div :class="['orbita-toggle', `orbita-toggle--${orientationClass}`]">
    <div
      class="orbita-toggle__grip"
      :aria-label="moveLabel"
      @pointerdown.stop="emit('start-drag', $event)"
    >
      <span
        v-for="dot in gripDots"
        :key="dot"
        class="orbita-toggle__grip-dot"
        aria-hidden="true"
      />
    </div>

    <div class="orbita-toggle__divider" aria-hidden="true" />

    <Button
      v-tooltip="tooltipOptions(toggleLabel)"
      class="orbita-toggle__button"
      :icon="icon"
      text
      :aria-label="toggleLabel"
      @click="emit('toggle')"
    />

    <div class="orbita-toggle__divider" aria-hidden="true" />

    <div
      class="orbita-toggle__grip"
      :aria-label="moveLabel"
      @pointerdown.stop="emit('start-drag', $event)"
    >
      <span
        v-for="dot in gripDots"
        :key="`secondary-${dot}`"
        class="orbita-toggle__grip-dot"
        aria-hidden="true"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import Tooltip from 'primevue/tooltip'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOrientation } from './types'

interface Props {
  icon: string
  moveLabel: string
  toggleLabel: string
  orientation: OrbitaOrientation
  tooltipOptions: (value: string) => OrbitaTooltipOptions
}

const props = defineProps<Props>()

const emit = defineEmits<{
  toggle: []
  'start-drag': [event: PointerEvent]
}>()

const vTooltip = Tooltip
const gripDots = Array.from({ length: 2 }, (_, index) => index)

// horizontal → orientation shown top-to-bottom like Vizion 'top' placement
// vertical → left-to-right like Vizion 'left' placement
const orientationClass = props.orientation === 'horizontal' ? 'top' : 'left'
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
  overflow: hidden;
  transition:
    border-color $transition-fast,
    box-shadow $transition-fast,
    background-color $transition-fast;

  &:hover {
    border-color: orbita.$orbita-surface-border-hover;
  }

  // horizontal mode: toggle is column-oriented (top placement in Vizion)
  &--top {
    flex-direction: column;
  }

  // vertical mode: toggle is row-oriented (left placement in Vizion)
  &--left {
    flex-direction: row;
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
  }

  &__grip-dot {
    width: 4px;
    height: 4px;
    border-radius: 999px;
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
      color: $surface-700;
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
      font-size: 1rem;
    }
  }
}

// Horizontal mode (top)
.orbita-toggle--top .orbita-toggle__button {
  width: orbita.$orbita-control-size;
  height: orbita.$orbita-toggle-main-size;
}

.orbita-toggle--top .orbita-toggle__grip {
  width: orbita.$orbita-control-size;
  height: orbita.$orbita-grip-size;
  grid-template-columns: repeat(2, 4px);
  grid-template-rows: 4px;
  padding-top: 0.25rem;
}

.orbita-toggle--top .orbita-toggle__divider {
  height: 1px;
  width: auto;
  margin: 0 orbita.$orbita-divider-inset;
  align-self: stretch;
}

// Vertical mode (left)
.orbita-toggle--left .orbita-toggle__button {
  width: orbita.$orbita-toggle-main-size;
  height: orbita.$orbita-control-size;
}

.orbita-toggle--left .orbita-toggle__grip {
  width: orbita.$orbita-grip-size;
  height: orbita.$orbita-control-size;
  grid-template-columns: 4px;
  grid-template-rows: repeat(2, 4px);
  padding-left: 0.25rem;
}

.orbita-toggle--left .orbita-toggle__divider {
  width: 1px;
  align-self: stretch;
  margin: orbita.$orbita-divider-inset 0;
}
</style>
