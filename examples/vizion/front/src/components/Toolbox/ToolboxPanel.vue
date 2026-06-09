<template>
  <div
    ref="panelRef"
    class="toolbox-panel"
    :data-placement="placement"
    :data-direction="direction"
    :class="{ 'is-collapsed': collapsed }"
  >
    <div class="toolbox-panel__chrome" aria-label="Toolbar controls">
      <Button
        v-tooltip="tooltipOptions(placementToggleLabel)"
        :class="[
          'toolbox-panel__button',
          'toolbox-panel__button--placement-toggle',
          { 'toolbox-panel__button--placement-toggle-vertical': placement === 'left' },
        ]"
        :icon="placementToggleIcon"
        text
        :aria-label="placementToggleLabel"
        @click="emit('toggle-placement')"
      />
    </div>

    <div class="toolbox-panel__divider" />

    <div class="toolbox-panel__group toolbox-panel__group--nav">
      <Button
        v-for="item in navItems"
        :key="item.key"
        v-tooltip="tooltipOptions(item.ariaLabel)"
        :class="[
          'toolbox-panel__button',
          'toolbox-panel__button--nav',
          { 'is-active': isRouteActive(item.path) },
        ]"
        :icon="`pi ${item.icon}`"
        text
        :aria-label="item.ariaLabel"
        @click="emit('navigate', item.path)"
      />
    </div>

    <div class="toolbox-panel__divider" />

    <div class="toolbox-panel__group toolbox-panel__group--actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import Button from 'primevue/button'
import Tooltip from 'primevue/tooltip'
import type { ToolboxTooltipOptions } from './composables/useToolboxTooltip'
import type { ToolboxNavItem, ToolboxPanelDirection, ToolboxPlacement } from './types'

interface Props {
  collapsed: boolean
  direction: ToolboxPanelDirection
  isRouteActive: (path: string) => boolean
  navItems: ToolboxNavItem[]
  nextPlacement: ToolboxPlacement
  placement: ToolboxPlacement
  placementToggleIcon: string
  placementToggleLabel: string
  tooltipOptions: (value: string) => ToolboxTooltipOptions
}

defineProps<Props>()

const emit = defineEmits<{
  navigate: [path: string]
  'toggle-placement': []
}>()

const vTooltip = Tooltip
const panelRef = ref<HTMLElement | null>(null)

defineExpose({
  panelRef,
})
</script>

<style lang="scss" scoped>
@use './styles/tokens' as toolbox;

.toolbox-panel {
  position: absolute;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 0.75rem;
  border: toolbox.$toolbox-surface-border;
  border-radius: $radius-lg;
  background: toolbox.$toolbox-surface-bg;
  box-shadow: $shadow-lg;
  transition:
    opacity $transition-fast,
    transform $transition-fast;

  &.is-collapsed {
    opacity: 0;
    pointer-events: none;
  }

  &__group {
    display: flex;
    align-items: center;
    gap: 0.5rem;
  }

  &__chrome {
    display: flex;
    align-items: center;
    gap: 0.375rem;
  }

  &__divider {
    width: 1px;
    align-self: stretch;
    background: $surface-200;
  }

  &__button {
    position: relative;
    z-index: 2;
    width: toolbox.$toolbox-control-size;
    height: toolbox.$toolbox-control-size;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    border: 1px solid transparent;
    border-radius: $radius-md;
    background: transparent;
    color: $surface-700;
    padding: 0;
    box-shadow: none;
    transition:
      background-color $transition-fast,
      border-color $transition-fast,
      color $transition-fast,
      transform $transition-fast;

    :deep(.p-button-icon) {
      font-size: 1rem;
    }

    &:deep(.p-button-label) {
      display: none;
    }

    &:hover {
      background: $surface-100;
      color: $surface-900;
      transform: translateY(-1px);
    }

    &:focus-within {
      outline: 2px solid $primary;
      outline-offset: 2px;
    }

    &.is-active {
      background: rgba($primary, 0.12);
      border-color: rgba($primary, 0.18);
      color: $primary;
    }
  }

  &__button--placement-toggle {
    border: 1px solid rgba($surface-900, 0.08);
    background: transparent;
  }

  &__button--placement-toggle-vertical :deep(.p-button-icon) {
    transform: rotate(90deg);
  }

  &[data-placement='top'][data-direction='start'] {
    top: 50%;
    right: calc(100% - #{toolbox.$toolbox-toggle-overlap});
    transform: translateY(-50%);
    transform-origin: right center;
  }

  &[data-placement='top'][data-direction='end'] {
    top: 50%;
    left: calc(100% - #{toolbox.$toolbox-toggle-overlap});
    transform: translateY(-50%);
    transform-origin: left center;
  }

  &[data-placement='top'].is-collapsed[data-direction='start'] {
    transform: translateY(-50%) translateX(0.5rem) scaleX(0.96);
  }

  &[data-placement='top'].is-collapsed[data-direction='end'] {
    transform: translateY(-50%) translateX(-0.5rem) scaleX(0.96);
  }

  &[data-placement='left'][data-direction='up'] {
    left: 50%;
    bottom: calc(100% - #{toolbox.$toolbox-toggle-overlap});
    flex-direction: column;
    transform: translateX(-50%);
    transform-origin: center bottom;
  }

  &[data-placement='left'][data-direction='down'] {
    left: 50%;
    top: calc(100% - #{toolbox.$toolbox-toggle-overlap});
    flex-direction: column;
    transform: translateX(-50%);
    transform-origin: center top;
  }

  &[data-placement='left'] &__chrome,
  &[data-placement='left'] &__group {
    flex-direction: column;
  }

  &[data-placement='left'] &__divider {
    width: 100%;
    height: 1px;
  }

  &[data-placement='left'].is-collapsed[data-direction='up'] {
    transform: translateX(-50%) translateY(0.5rem) scaleY(0.96);
  }

  &[data-placement='left'].is-collapsed[data-direction='down'] {
    transform: translateX(-50%) translateY(-0.5rem) scaleY(0.96);
  }
}

@media (max-width: 767px) {
  .toolbox-panel {
    max-width: calc(100vw - 5rem);
  }
}

@media (max-width: 560px) {
  .toolbox-panel[data-placement='top'][data-direction='start'],
  .toolbox-panel[data-placement='top'][data-direction='end'] {
    top: 0;
    display: grid;
    grid-template-columns: repeat(2, max-content);
    justify-content: start;
    align-items: start;
    column-gap: 0.5rem;
    row-gap: 0.5rem;
    transform: none;
  }

  .toolbox-panel[data-placement='top'][data-direction='start'] {
    right: calc(100% - #{toolbox.$toolbox-toggle-overlap});
    transform-origin: right top;
  }

  .toolbox-panel[data-placement='top'][data-direction='end'] {
    left: calc(100% - #{toolbox.$toolbox-toggle-overlap});
    transform-origin: left top;
  }

  .toolbox-panel[data-placement='top'].is-collapsed[data-direction='start'] {
    transform: translateX(0.5rem) scaleX(0.96);
  }

  .toolbox-panel[data-placement='top'].is-collapsed[data-direction='end'] {
    transform: translateX(-0.5rem) scaleX(0.96);
  }

  .toolbox-panel[data-placement='top'] .toolbox-panel__divider {
    display: none;
  }

  .toolbox-panel[data-placement='top'] .toolbox-panel__chrome,
  .toolbox-panel[data-placement='top'] .toolbox-panel__group {
    width: max-content;
  }

  .toolbox-panel[data-placement='top'] .toolbox-panel__group--nav {
    grid-column: 1 / -1;
    justify-self: center;
  }
}
</style>
