<template>
  <!--
    Inline-label wrapper follows the same orbita-panel__btn expand pattern as nav
    buttons. In vertical mode the label expands edge-aware (start→right, end→left).
    In horizontal mode it expands rightward (same as nav, symmetric flex reflow).
    v-tooltip is intentionally removed — it rendered under the toggle (z-order issue).
  -->
  <div
    class="notifications-btn"
    :class="[
      'orbita-action-btn',
      panelOrientation === 'vertical' ? `orbita-action-btn--${labelSide}` : 'orbita-action-btn--h',
    ]"
  >
    <!-- Trigger: bell icon + unread badge -->
    <button
      class="notifications-btn__trigger orbita-action-btn__trigger"
      :class="{ 'notifications-btn__trigger--has-unread': hasUnread }"
      :aria-label="badgeAriaLabel"
      @click="handleClick"
    >
      <i class="pi pi-bell notifications-btn__icon" />
      <span
        v-if="hasUnread"
        class="notifications-btn__badge"
        aria-hidden="true"
      >{{ badgeLabel }}</span>
    </button>
    <!-- Inline label — expands on hover, edge-aware -->
    <span class="orbita-action-btn__label" aria-hidden="true">{{ t('orbita.notifications') }}</span>

    <!-- Flyout Popover -->
    <Popover
      ref="popoverRef"
      append-to="body"
      :pt="popoverPt"
      @show="onShow"
      @hide="onHide"
    >
      <NotificationsFlyoutPanel
        :actionable="actionable"
        :feed="feed"
        :digest="digest"
        :has-more-feed="hasMoreFeed"
        :is-empty="isEmpty"
        :loading-initial="loadingInitial"
        :loading-more="loadingMore"
        :load-error="loadError"
        :mark-all-pending="markAllPending"
        @item-click="onItemBodyClick"
        @cta-click="onCtaClick"
        @mark-all="markAllRead"
        @load-more="loadMore"
      />
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref, toRefs } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import NotificationsFlyoutPanel from './NotificationsFlyoutPanel.vue'
import { useNotificationsStore } from '@/stores/notificationsStore'
import { useNotificationsFlyout } from './composables/useNotificationsFlyout'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOverlayControl, OrbitaOrientation } from './types'
import type { NotificationDto } from '@/entities/notification'

interface Props {
  tooltipOptions: (value: string) => OrbitaTooltipOptions
  /** Edge-aware label side forwarded from OrbitaPanel scoped slot */
  labelSide?: 'start' | 'end' | 'center'
  /** Panel orientation forwarded from OrbitaPanel scoped slot */
  panelOrientation?: OrbitaOrientation
}

const props = defineProps<Props>()

const { panelOrientation, labelSide } = toRefs(props)

const emit = defineEmits<{
  'toggle-request': [event: MouseEvent]
  'visibility-change': [visible: boolean]
}>()

const { t } = useI18n()

const popoverRef = ref<InstanceType<typeof Popover> | null>(null)

const notificationsStore = useNotificationsStore()

const {
  actionable,
  feed,
  digest,
  hasMoreFeed,
  isEmpty,
  loadingInitial,
  loadingMore,
  loadError,
  markAllPending,
  load,
  loadMore,
  markRead,
  markAllRead,
  onFlyoutClose,
} = useNotificationsFlyout()

// ─── Computed ──────────────────────────────────────────────────────────────
const hasUnread = computed(() => notificationsStore.unreadCount > 0)

const badgeLabel = computed(() => {
  const c = notificationsStore.unreadCount
  return c > 99 ? '99+' : String(c)
})

const badgeAriaLabel = computed(() => {
  const c = notificationsStore.unreadCount
  return c > 0
    ? t('orbita.notificationsUnread', { count: c })
    : t('orbita.notifications')
})

// ─── PrimeVue Popover pass-through ────────────────────────────────────────
// z-index intentionally NOT overridden: PrimeVue layers the Popover on the
// overlay tier (1000+), which sits ABOVE the Orbita dock (toolbox=900) and
// BELOW modal (2600). A hardcoded 9999 previously kept this flyout over any
// Dialog/mask that opened on top of it (audit L1). Let PrimeVue manage it.
const popoverPt = {
  root: { style: 'padding: 0; overflow: hidden;' },
  content: { style: 'padding: 0;' },
}

// ─── Bootstrap: poll unread count while mounted ────────────────────────────
onMounted(() => {
  notificationsStore.startPolling()
})

onUnmounted(() => {
  notificationsStore.stopPolling()
})

// ─── Handlers ─────────────────────────────────────────────────────────────
function handleClick(event: MouseEvent): void {
  emit('toggle-request', event)
}

async function onShow(): Promise<void> {
  emit('visibility-change', true)
  await load()
}

async function onHide(): Promise<void> {
  emit('visibility-change', false)
  await onFlyoutClose()
}

// ─── Notification item click handlers ─────────────────────────────────────
/**
 * Body click: mark as read. Navigate only when there is no separate CTA
 * button — if a CTA exists the user should click it explicitly.
 */
async function onItemBodyClick(item: NotificationDto): Promise<void> {
  await markRead(item)
  if (!item.action_label || !item.deep_link) {
    // No CTA: markRead already navigated (deep_link handled inside markRead).
    // Close the flyout after navigation.
    popoverRef.value?.hide()
  }
}

/**
 * CTA button click: single markRead + navigate + close flyout.
 * @click.stop prevents bubbling to the body button (which would call markRead again).
 */
async function onCtaClick(item: NotificationDto): Promise<void> {
  await markRead(item)
  popoverRef.value?.hide()
}

// ─── OrbitaOverlayControl interface ──────────────────────────────────────
function syncPopover(open: boolean, event?: MouseEvent | null): void {
  if (!popoverRef.value) return
  if (open && event) {
    popoverRef.value.show(event)
  } else if (!open) {
    popoverRef.value.hide()
  }
}

function realign(): void {
  // PrimeVue Popover re-aligns on its own; no-op
}

defineExpose<OrbitaOverlayControl>({ syncPopover, realign })
</script>

<style lang="scss" scoped>
@use './styles/tokens' as orbita;

// ─── Inline-label action button wrapper ────────────────────────────────────
// Mirrors orbita-panel__btn expand pattern. The wrapper is a flex row that
// grows on hover; the icon trigger stays pinned; the label fades in from hidden.
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

  // Vertical, left edge → icon left, label expands rightward
  &--start {
    flex-direction: row;
  }

  // Vertical, right edge → icon right, label expands leftward
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

  // Horizontal → icon + label expand rightward (matches nav)
  &--h {
    flex-direction: row;
  }

  // center = horizontal, same as --h
  &--center {
    flex-direction: row;
  }
}

.orbita-action-btn__trigger {
  // Icon square stays fixed; never shrinks as label appears
  flex-shrink: 0;
  width: orbita.$orbita-control-size;
  min-width: orbita.$orbita-control-size;
}

.orbita-action-btn__label {
  font-size: $font-size-xs; // snap from 13px
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

// ─── Trigger ───────────────────────────────────────────────────────────────
.notifications-btn {
  position: relative;
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
  position: relative;
  transition:
    background-color $transition-fast,
    border-color $transition-fast;

  &:hover {
    background: $surface-100;
    // rgba($surface-900, …) was invalid (var-hex dropped). color-mix.
    border-color: color-mix(in srgb, var(--app-surface-900) 8%, transparent);
  }

  &:focus-visible {
    outline: 2px solid $primary;
    outline-offset: 2px;
  }
}

.notifications-btn__icon {
  font-size: $font-size-md;
  color: $surface-700;
}

.notifications-btn__badge {
  position: absolute;
  top: 3px;
  right: 3px;
  min-width: 16px;
  height: 16px;
  padding: 0 3px;
  border-radius: $radius-badge; // snap from 8px
  background: $color-danger;
  color: $surface-0;
  font-size: $font-size-3xs; // snap from 9px
  font-weight: 700;
  line-height: 16px;
  text-align: center;
  pointer-events: none;
  white-space: nowrap;
}

// ─── Flyout panel styles live in the shared NotificationsFlyoutPanel.vue ─────
// (was ~250 lines of .nf__* here, duplicated verbatim in AppShell/SidebarNotifications;
// extracted so the panel + its muted-token fixes have one source — audit §3 dedup).


// ─── Accessibility ────────────────────────────────────────────────────────────
@media (prefers-reduced-motion: reduce) {
  .orbita-action-btn,
  .orbita-action-btn__label {
    transition: none !important;
  }
}

@media (forced-colors: active) {
  .orbita-action-btn__label {
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: ButtonText; // a11y forced-colors system keyword
  }
}
</style>
