<template>
  <div :class="['toolbox-toggle', `toolbox-toggle--${placement}`]">
    <div
      class="toolbox-toggle__grip"
      :aria-label="moveLabel"
      @pointerdown.stop="emit('start-drag', $event)"
    >
      <span
        v-for="dot in gripDots"
        :key="dot"
        class="toolbox-toggle__grip-dot"
        aria-hidden="true"
      />
    </div>

    <div class="toolbox-toggle__divider" aria-hidden="true" />

    <Button
      v-tooltip="tooltipOptions(toggleLabel)"
      class="toolbox-toggle__button"
      :icon="icon"
      text
      :aria-label="toggleLabel"
      @click="emit('toggle')"
    />

    <div class="toolbox-toggle__divider" aria-hidden="true" />

    <div
      class="toolbox-toggle__grip"
      :aria-label="moveLabel"
      @pointerdown.stop="emit('start-drag', $event)"
    >
      <span
        v-for="dot in gripDots"
        :key="`secondary-${dot}`"
        class="toolbox-toggle__grip-dot"
        aria-hidden="true"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import Button from 'primevue/button'
import Tooltip from 'primevue/tooltip'
import type { ToolboxTooltipOptions } from './composables/useToolboxTooltip'
import type { ToolboxPlacement } from './types'

interface Props {
  icon: string
  moveLabel: string
  toggleLabel: string
  placement: ToolboxPlacement
  tooltipOptions: (value: string) => ToolboxTooltipOptions
}

defineProps<Props>()

const emit = defineEmits<{
  toggle: []
  'start-drag': [event: PointerEvent]
}>()

const vTooltip = Tooltip
const gripDots = Array.from({ length: 2 }, (_, index) => index)
</script>

<style lang="scss" scoped>
@use './styles/tokens' as toolbox;

.toolbox-toggle {
  position: relative;
  display: flex;
  align-items: center;
  justify-content: center;
  background: toolbox.$toolbox-surface-bg;
  border: toolbox.$toolbox-surface-border;
  border-radius: $radius-md;
  box-shadow: $shadow-lg;
  overflow: hidden;
  transition:
    border-color $transition-fast,
    box-shadow $transition-fast,
    background-color $transition-fast;

  &:hover {
    border-color: toolbox.$toolbox-surface-border-hover;
  }

  &--top {
    flex-direction: column;
  }

  &--left {
    flex-direction: row;
  }

  &__grip {
    display: grid;
    place-content: center;
    gap: 2px;
    flex-shrink: 0;
    position: relative;
    background: toolbox.$toolbox-surface-bg;
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

.toolbox-toggle--left .toolbox-toggle__button {
  width: toolbox.$toolbox-toggle-main-size;
  height: toolbox.$toolbox-control-size;
}

.toolbox-toggle--top .toolbox-toggle__button {
  width: toolbox.$toolbox-control-size;
  height: toolbox.$toolbox-toggle-main-size;
}

.toolbox-toggle--left .toolbox-toggle__grip {
  width: toolbox.$toolbox-grip-size;
  height: toolbox.$toolbox-control-size;
  grid-template-columns: 4px;
  grid-template-rows: repeat(2, 4px);
  padding-left: 0.25rem;
}

.toolbox-toggle--top .toolbox-toggle__grip {
  width: toolbox.$toolbox-control-size;
  height: toolbox.$toolbox-grip-size;
  grid-template-columns: repeat(2, 4px);
  grid-template-rows: 4px;
  padding-top: 0.25rem;
}

.toolbox-toggle--left .toolbox-toggle__divider {
  width: 1px;
  align-self: stretch;
  margin: toolbox.$toolbox-divider-inset 0;
}

.toolbox-toggle--top .toolbox-toggle__divider {
  height: 1px;
  width: auto;
  margin: 0 toolbox.$toolbox-divider-inset;
  align-self: stretch;
}
</style>
