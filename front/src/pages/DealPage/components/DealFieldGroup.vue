<template>
  <div
    class="deal-field-group"
    :class="{
      'deal-field-group--collapsed': collapsed,
      'deal-field-group--accent': accent,
    }"
  >
    <button
      class="deal-field-group__header"
      type="button"
      @click="toggle"
    >
      <!-- Accent icon tile -->
      <div v-if="accent && icon" class="deal-field-group__accent-icon">
        <i :class="['pi', icon]" />
      </div>
      <!-- Regular icon -->
      <i v-else-if="icon" :class="['pi', icon, 'deal-field-group__header-icon']" />

      <span class="deal-field-group__title">{{ title }}</span>

      <!-- Badge (count) -->
      <Badge
        v-if="count !== undefined && count !== null"
        :value="count"
        severity="secondary"
        size="small"
        class="deal-field-group__count-badge"
      />

      <!-- Total inline (for products) -->
      <span v-if="totalLabel" class="deal-field-group__total-inline">{{ totalLabel }}</span>

      <div class="deal-field-group__header-actions" @click.stop>
        <slot name="header-action" />
      </div>
      <i
        class="pi deal-field-group__chevron"
        :class="collapsed ? 'pi-chevron-right' : 'pi-chevron-down'"
      />
    </button>
    <div v-if="!collapsed" class="deal-field-group__body">
      <slot />
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import Badge from 'primevue/badge'

const props = withDefaults(
  defineProps<{
    title: string
    icon?: string
    groupKey: string
    defaultCollapsed?: boolean
    accent?: boolean
    count?: number | null
    totalLabel?: string | null
  }>(),
  {
    icon: undefined,
    defaultCollapsed: false,
    accent: false,
    count: null,
    totalLabel: null,
  },
)

const STORAGE_PREFIX = 'deal_field_group_'

const collapsed = ref(props.defaultCollapsed)

onMounted(() => {
  const stored = localStorage.getItem(STORAGE_PREFIX + props.groupKey)
  if (stored !== null) {
    collapsed.value = stored === 'true'
  }
})

function toggle() {
  collapsed.value = !collapsed.value
  localStorage.setItem(STORAGE_PREFIX + props.groupKey, String(collapsed.value))
}

function collapse() {
  collapsed.value = true
  localStorage.setItem(STORAGE_PREFIX + props.groupKey, 'true')
}

function expand() {
  collapsed.value = false
  localStorage.setItem(STORAGE_PREFIX + props.groupKey, 'false')
}

defineExpose({ collapse, expand })
</script>

<style lang="scss" scoped>
.deal-field-group {
  border-bottom: 1px solid var(--p-surface-200);

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }
}

.deal-field-group__header {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-2 $space-4;
  background: transparent;
  border: none;
  cursor: pointer;
  text-align: left;
  color: $surface-700;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  transition: background var(--app-transition-fast);
  min-height: 36px;

  &:hover {
    background: var(--p-surface-50);

    .app-dark & {
      background: var(--p-surface-800);
    }
  }
}

// Accent mode header
.deal-field-group--accent .deal-field-group__header {
  background: var(--p-surface-50);

  .app-dark & {
    background: var(--p-surface-800);
  }
}

.deal-field-group__accent-icon {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 24px;
  height: 24px;
  border-radius: $radius-sm;
  background: var(--p-primary-100);
  color: var(--p-primary-color);
  flex-shrink: 0;

  .app-dark & {
    background: var(--p-primary-900);
    color: var(--p-primary-300);
  }

  i {
    font-size: $font-size-2xs;
  }
}

.deal-field-group__header-icon {
  font-size: $font-size-xs;
  color: $surface-500;
  flex-shrink: 0;
}

.deal-field-group__title {
  flex: 1;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.06em;
}

.deal-field-group--accent .deal-field-group__title {
  color: $surface-700;

  .app-dark & {
    color: var(--p-surface-200);
  }
}

.deal-field-group__count-badge {
  flex-shrink: 0;
}

.deal-field-group__total-inline {
  font-size: $font-size-xs;
  color: var(--p-primary-color);
  font-weight: $font-weight-semibold;
  flex-shrink: 0;
}

.deal-field-group__header-actions {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.deal-field-group__chevron {
  font-size: $font-size-xs;
  color: $surface-400;
  flex-shrink: 0;
  transition: transform var(--app-transition-fast);
}

.deal-field-group__body {
  padding: $space-2 0;
}
</style>
