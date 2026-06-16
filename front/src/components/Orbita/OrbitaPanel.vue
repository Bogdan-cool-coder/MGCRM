<template>
  <div
    ref="panelRef"
    class="orbita-panel"
    :data-orientation="orientation"
    :data-direction="direction"
    :class="{ 'is-collapsed': collapsed }"
  >
    <!-- Rotate orientation toggle -->
    <div class="orbita-panel__chrome" :aria-label="t('orbita.drag')">
      <Button
        v-tooltip="tooltipOptions(orientationToggleLabel)"
        :class="[
          'orbita-panel__button',
          'orbita-panel__button--orient-toggle',
          { 'orbita-panel__button--orient-toggle-vertical': orientation === 'vertical' },
        ]"
        icon="pi pi-sync"
        text
        :aria-label="orientationToggleLabel"
        @click="emit('toggle-orientation')"
      />
    </div>

    <div class="orbita-panel__divider" />

    <!-- Navigation items from shared navItems -->
    <nav aria-label="Основная навигация" class="orbita-panel__group orbita-panel__group--nav">
      <Button
        v-for="item in navItems"
        :key="item.key"
        v-tooltip="tooltipOptions(item.ariaLabel)"
        :class="[
          'orbita-panel__button',
          'orbita-panel__button--nav',
          { 'is-active': item.isActive },
        ]"
        :icon="item.icon"
        text
        :aria-label="item.ariaLabel"
        :aria-current="item.isActive ? 'page' : undefined"
        @click="emit('navigate', item.route)"
      />
    </nav>

    <div class="orbita-panel__divider" />

    <!-- Action slots: notifications, user profile -->
    <div class="orbita-panel__group orbita-panel__group--actions">
      <slot name="actions" />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Tooltip from 'primevue/tooltip'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaNavItem, OrbitaOrientation, OrbitaPanelDirection } from './types'

interface Props {
  collapsed: boolean
  direction: OrbitaPanelDirection
  navItems: OrbitaNavItem[]
  orientation: OrbitaOrientation
  tooltipOptions: (value: string) => OrbitaTooltipOptions
}

const props = defineProps<Props>()

const emit = defineEmits<{
  navigate: [route: string]
  'toggle-orientation': []
}>()

const { t } = useI18n()
const vTooltip = Tooltip
const panelRef = ref<HTMLElement | null>(null)

const orientationToggleLabel = computed(() =>
  props.orientation === 'horizontal' ? t('orbita.rotateV') : t('orbita.rotateH'),
)

defineExpose({ panelRef })
</script>

<style lang="scss" scoped>
@use './styles/tokens' as orbita;

.orbita-panel {
  position: absolute;
  z-index: 1;
  display: flex;
  align-items: center;
  gap: 0.5rem;
  padding: 0.625rem 0.75rem;
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
    width: orbita.$orbita-control-size;
    height: orbita.$orbita-control-size;
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

  &__button--orient-toggle {
    border: 1px solid rgba($surface-900, 0.08);
    background: transparent;
  }

  &__button--orient-toggle-vertical :deep(.p-button-icon) {
    transform: rotate(90deg);
  }

  // ─── Horizontal (top placement) ─────────────────────────────────────────────
  &[data-orientation='horizontal'][data-direction='start'] {
    top: 50%;
    right: calc(100% - #{orbita.$orbita-toggle-overlap});
    transform: translateY(-50%);
    transform-origin: right center;
  }

  &[data-orientation='horizontal'][data-direction='end'] {
    top: 50%;
    left: calc(100% - #{orbita.$orbita-toggle-overlap});
    transform: translateY(-50%);
    transform-origin: left center;
  }

  &[data-orientation='horizontal'].is-collapsed[data-direction='start'] {
    transform: translateY(-50%) translateX(0.5rem) scaleX(0.96);
  }

  &[data-orientation='horizontal'].is-collapsed[data-direction='end'] {
    transform: translateY(-50%) translateX(-0.5rem) scaleX(0.96);
  }

  // ─── Vertical (left placement) ──────────────────────────────────────────────
  &[data-orientation='vertical'][data-direction='up'] {
    left: 50%;
    bottom: calc(100% - #{orbita.$orbita-toggle-overlap});
    flex-direction: column;
    transform: translateX(-50%);
    transform-origin: center bottom;
  }

  &[data-orientation='vertical'][data-direction='down'] {
    left: 50%;
    top: calc(100% - #{orbita.$orbita-toggle-overlap});
    flex-direction: column;
    transform: translateX(-50%);
    transform-origin: center top;
  }

  &[data-orientation='vertical'] .orbita-panel__chrome,
  &[data-orientation='vertical'] .orbita-panel__group {
    flex-direction: column;
  }

  &[data-orientation='vertical'] .orbita-panel__divider {
    width: 100%;
    height: 1px;
  }

  &[data-orientation='vertical'].is-collapsed[data-direction='up'] {
    transform: translateX(-50%) translateY(0.5rem) scaleY(0.96);
  }

  &[data-orientation='vertical'].is-collapsed[data-direction='down'] {
    transform: translateX(-50%) translateY(-0.5rem) scaleY(0.96);
  }
}

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
    column-gap: 0.5rem;
    row-gap: 0.5rem;
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

  .orbita-panel[data-orientation='horizontal'].is-collapsed[data-direction='start'] {
    transform: translateX(0.5rem) scaleX(0.96);
  }

  .orbita-panel[data-orientation='horizontal'].is-collapsed[data-direction='end'] {
    transform: translateX(-0.5rem) scaleX(0.96);
  }

  .orbita-panel[data-orientation='horizontal'] .orbita-panel__divider {
    display: none;
  }

  .orbita-panel[data-orientation='horizontal'] .orbita-panel__chrome,
  .orbita-panel[data-orientation='horizontal'] .orbita-panel__group {
    width: max-content;
  }

  .orbita-panel[data-orientation='horizontal'] .orbita-panel__group--nav {
    grid-column: 1 / -1;
    justify-self: center;
  }
}
</style>
