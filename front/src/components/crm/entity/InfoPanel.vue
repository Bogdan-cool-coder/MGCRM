<template>
  <div
    class="info-panel"
    :class="{
      'info-panel--collapsed': collapsed,
    }"
  >
    <button
      class="info-panel__header"
      type="button"
      @click="toggle"
    >
      <div v-if="icon" class="info-panel__icon-tile">
        <i :class="['pi', icon]" />
      </div>

      <span class="info-panel__title">{{ title }}</span>

      <!-- spec §4: count pill — raw span, NOT PrimeVue Badge -->
      <span
        v-if="count !== null && count !== undefined"
        class="info-panel__count"
      >{{ count }}</span>

      <div class="info-panel__header-actions" @click.stop>
        <slot name="header-action" />
      </div>

      <i
        class="pi info-panel__chevron"
        :class="collapsed ? 'pi-chevron-right' : 'pi-chevron-down'"
      />
    </button>

    <div v-if="!collapsed" class="info-panel__body">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'

const props = withDefaults(
  defineProps<{
    title: string
    icon?: string
    /** Unique key for localStorage persistence */
    panelKey: string
    defaultCollapsed?: boolean
    count?: number | null
  }>(),
  {
    icon: undefined,
    defaultCollapsed: false,
    count: null,
  },
)

const STORAGE_PREFIX = 'info_panel_'

const collapsed = ref(props.defaultCollapsed)

onMounted(() => {
  const stored = localStorage.getItem(STORAGE_PREFIX + props.panelKey)
  if (stored !== null) {
    collapsed.value = stored === 'true'
  }
})

function toggle() {
  collapsed.value = !collapsed.value
  localStorage.setItem(STORAGE_PREFIX + props.panelKey, String(collapsed.value))
}

function collapse() {
  collapsed.value = true
  localStorage.setItem(STORAGE_PREFIX + props.panelKey, 'true')
}

function expand() {
  collapsed.value = false
  localStorage.setItem(STORAGE_PREFIX + props.panelKey, 'false')
}

defineExpose({ collapse, expand, collapsed })
</script>

<style lang="scss" scoped>
.info-panel {
  // var(--p-surface-200) is reactive: light=#E3E4E6, dark=#616263 (inverted palette).
  // No dark override needed — the token resolves correctly in both themes.
  border-bottom: 1px solid var(--p-surface-200);

  &:last-child {
    border-bottom: none;
  }
}

.info-panel__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-3 $space-4;
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: left;
  color: $surface-700;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  transition: background var(--app-transition-fast);
  min-height: 44px;

  &:hover {
    // --mg-surface-hover is reactive: light = surface-50 (#F9FAFB), dark = #3a3b3d
    // (between surface-50/#272829 and surface-100/#444547 — subtly darker than the card).
    // Defined in theme/scss/foundation/_colors.scss; no dark override needed here.
    background: var(--mg-surface-hover);
  }
}

.info-panel__icon-tile {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 26px;
  height: 26px;
  border-radius: $radius-sm;
  background: var(--p-primary-100);
  color: var(--p-primary-color);
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-300);
  }

  i {
    font-size: $font-size-xs;
  }
}

.info-panel__title {
  flex: 1;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $surface-700;

  .app-dark & {
    // surface-400 = #9b9c9e — sufficiently bright against dark surfaces (50/100/200/300)
    color: var(--p-surface-400);
  }
}

// spec §4: count pill — raw span styled as lightweight chip (not PrimeVue Badge)
.info-panel__count {
  flex-shrink: 0;
  font-size: $font-size-2xs;
  font-weight: $font-weight-bold;
  color: $surface-500;
  background: var(--p-surface-50);
  border-radius: $radius-circle;
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 1px 8px; // spec §4: pill padding 1px 8px

  .app-dark & {
    background: var(--p-surface-200);
    color: var(--p-surface-400);
  }
}

.info-panel__header-actions {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.info-panel__chevron {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
  transition: transform var(--app-transition-fast);
}

.info-panel__body {
  padding: $space-3 $space-4 $space-4;
}
</style>
