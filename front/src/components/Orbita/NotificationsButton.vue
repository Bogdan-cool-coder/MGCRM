<template>
  <div class="notifications-btn">
    <button
      v-tooltip="tooltipOptions(t('orbita.notifications'))"
      class="notifications-btn__trigger"
      :aria-label="t('orbita.notifications')"
      @click="handleClick"
    >
      <i class="pi pi-bell notifications-btn__icon" />
    </button>

    <!-- Flyout — stub until backend provides GET /api/notifications -->
    <Popover ref="popoverRef" append-to="body" :pt="{ root: { style: 'z-index: 9999' } }">
      <div class="notifications-flyout">
        <div class="notifications-flyout__header">
          <span class="notifications-flyout__title">{{ t('orbita.notifications') }}</span>
        </div>
        <div class="notifications-flyout__empty">
          <i class="pi pi-bell notifications-flyout__empty-icon" />
          <p>{{ t('orbita.noNotifications') }}</p>
        </div>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Tooltip from 'primevue/tooltip'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOverlayControl } from './types'

interface Props {
  tooltipOptions: (value: string) => OrbitaTooltipOptions
}

defineProps<Props>()

const { t } = useI18n()
const vTooltip = Tooltip
const popoverRef = ref<InstanceType<typeof Popover> | null>(null)

function handleClick(event: MouseEvent) {
  popoverRef.value?.toggle(event)
}

// ─── OrbitaOverlayControl interface ──────────────────────────────────────────
// Exposed so parent (Orbita.vue) can wire useOrbitaOverlays for mutual exclusion.
function syncPopover(open: boolean, event?: MouseEvent | null) {
  if (!popoverRef.value) return
  if (open && event) {
    popoverRef.value.show(event)
  } else if (!open) {
    popoverRef.value.hide()
  }
}

function realign() {
  // PrimeVue Popover re-aligns on its own; no-op fallback
}

defineExpose<OrbitaOverlayControl>({ syncPopover, realign })
</script>

<style lang="scss" scoped>
.notifications-btn {
  position: relative;
  display: inline-flex;
}

.notifications-btn__trigger {
  width: 2.75rem;
  height: 2.75rem;
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
}

.notifications-btn__icon {
  font-size: 1rem;
  color: $surface-700;
}

.notifications-flyout {
  width: 320px;
  padding: 0;
}

.notifications-flyout__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
}

.notifications-flyout__title {
  font-size: 14px;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.notifications-flyout__empty {
  display: flex;
  flex-direction: column;
  align-items: center;
  gap: $space-2;
  padding: $space-8 $space-4;
  color: $surface-400;

  p {
    margin: 0;
    font-size: $font-size-sm;
  }
}

.notifications-flyout__empty-icon {
  font-size: 32px;
  opacity: 0.4;
}
</style>
