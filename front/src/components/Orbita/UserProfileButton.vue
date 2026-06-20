<template>
  <!--
    Inline-label wrapper follows the same orbita-panel__btn expand pattern.
    v-tooltip removed — it rendered behind the toggle in vertical mode.
  -->
  <div
    class="user-profile-btn"
    :class="[
      'orbita-action-btn',
      panelOrientation === 'vertical' ? `orbita-action-btn--${labelSide}` : 'orbita-action-btn--h',
    ]"
  >
    <button
      class="user-profile-btn__trigger orbita-action-btn__trigger"
      :aria-label="label"
      @click="handleClick"
      @keydown.esc.stop="accountMenuRef?.hide()"
    >
      <img
        v-if="userStore.getAvatarPath"
        class="user-profile-btn__avatar"
        :src="userStore.getAvatarPath"
        :alt="userStore.getUserName"
      />
      <span v-else class="user-profile-btn__initials">{{ initials }}</span>
    </button>

    <!-- Inline label — expands on hover, edge-aware -->
    <span class="orbita-action-btn__label" aria-hidden="true">{{ label }}</span>

    <AccountMenu
      ref="accountMenuRef"
      @show="emit('visibility-change', true)"
      @hide="emit('visibility-change', false)"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import { useI18n } from 'vue-i18n'
import AccountMenu from '@/components/AppShell/AccountMenu.vue'
import { useUserStore } from '@/stores/user'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOverlayControl, OrbitaOrientation } from './types'

interface Props {
  tooltipOptions: (value: string) => OrbitaTooltipOptions
  /** Edge-aware label side forwarded from OrbitaPanel scoped slot */
  labelSide?: 'start' | 'end' | 'center'
  /** Panel orientation forwarded from OrbitaPanel scoped slot */
  panelOrientation?: OrbitaOrientation
}

defineProps<Props>()

const emit = defineEmits<{
  'toggle-request': [event: MouseEvent]
  'visibility-change': [visible: boolean]
}>()

const { t } = useI18n()
const userStore = useUserStore()
const accountMenuRef = ref<InstanceType<typeof AccountMenu> | null>(null)

const label = computed(() => userStore.getUserName || t('orbita.profile'))

const initials = computed(() => {
  const name = userStore.getUserName
  if (!name) return '?'
  return name
    .split(' ')
    .slice(0, 2)
    .map((n: string) => n.charAt(0).toUpperCase())
    .join('')
})

function handleClick(event: MouseEvent) {
  emit('toggle-request', event)
}

// ─── OrbitaOverlayControl interface ──────────────────────────────────────────
function syncPopover(open: boolean, event?: MouseEvent | null) {
  if (!accountMenuRef.value) return
  if (open && event) {
    accountMenuRef.value.show(event)
  } else if (!open) {
    accountMenuRef.value.hide()
  }
}

function realign() {
  // AccountMenu (Popover) re-aligns on its own; no-op fallback
}

defineExpose<OrbitaOverlayControl>({ syncPopover, realign })
</script>

<style lang="scss" scoped>
@use './styles/tokens' as orbita;

// ─── Inline-label action button wrapper (shared with NotificationsButton) ───
// Defined here locally because scoped styles don't leak; action btn label
// classes are duplicated across components intentionally (no global SCSS leak).
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
  font-size: 13px;
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

// ─── Trigger ─────────────────────────────────────────────────────────────────
.user-profile-btn {
  position: relative;
}

.user-profile-btn__trigger {
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
}

.user-profile-btn__avatar {
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  object-fit: cover;
}

.user-profile-btn__initials {
  width: 28px;
  height: 28px;
  border-radius: $radius-sm;
  background: rgba($primary, 0.15);
  color: $primary;
  font-size: 11px;
  font-weight: $font-weight-bold;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  line-height: 1;
}

// ─── Dark mode ────────────────────────────────────────────────────────────────
:global(.app-dark) {
  .orbita-action-btn__label {
    color: $surface-300;
  }

  .user-profile-btn__trigger {
    &:hover {
      background: $surface-800;
      border-color: rgba($surface-100, 0.1);
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
    color: ButtonText;
  }
}
</style>
