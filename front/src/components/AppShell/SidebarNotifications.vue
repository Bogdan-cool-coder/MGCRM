<template>
  <!--
    Sidebar notifications bell. Mirrors Orbita/NotificationsButton but rendered in
    the dark sidebar footer (light-on-dark trigger). Reuses the same flyout
    composable + store so behaviour (digest / actionable / feed / mark-read) is
    identical across nav modes. Flyout Popover appends to body, so its content uses
    the normal surface tokens.
  -->
  <div class="sidebar-notif" :class="{ 'sidebar-notif--collapsed': collapsed }">
    <button
      class="sidebar-notif__trigger"
      :class="{ 'sidebar-notif__trigger--has-unread': hasUnread }"
      type="button"
      :aria-label="badgeAriaLabel"
      :title="collapsed ? t('orbita.notifications') : undefined"
      @click="handleClick"
    >
      <i class="pi pi-bell sidebar-notif__icon" />
      <span v-if="!collapsed" class="sidebar-notif__label">{{ t('orbita.notifications') }}</span>
      <span
        v-if="hasUnread"
        class="sidebar-notif__badge"
        aria-hidden="true"
      >{{ badgeLabel }}</span>
    </button>

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
        @item-click="onItemClick"
        @cta-click="onItemClick"
        @mark-all="markAllRead"
        @load-more="loadMore"
      />
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import NotificationsFlyoutPanel from '@/components/Orbita/NotificationsFlyoutPanel.vue'
import { useNotificationsStore } from '@/stores/notificationsStore'
import { useNotificationsFlyout } from '@/components/Orbita/composables/useNotificationsFlyout'
import type { NotificationDto } from '@/entities/notification'

defineProps<{
  collapsed: boolean
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
// overlay tier (1000+) — above the Orbita dock (toolbox=900), below modal
// (2600). Dropping the former hardcoded 9999 lets a Dialog opened on top of the
// flyout cover it correctly (audit L1).
const popoverPt = {
  root: { style: 'padding: 0; overflow: hidden;' },
  content: { style: 'padding: 0;' },
}

// ─── Handlers ─────────────────────────────────────────────────────────────
function handleClick(event: MouseEvent): void {
  popoverRef.value?.toggle(event)
}

async function onShow(): Promise<void> {
  await load()
}

async function onHide(): Promise<void> {
  await onFlyoutClose()
}

// Clicking an item marks it read (optimistic flip fires immediately), then hides
// the popover. Order matters: hide() synchronously triggers onHide → onFlyoutClose
// which skips already-read items — so markRead must flip is_read BEFORE hide().
async function onItemClick(item: NotificationDto): Promise<void> {
  await markRead(item)
  popoverRef.value?.hide()
}
</script>

<style lang="scss" scoped>
// ─── Trigger (dark sidebar footer) ──────────────────────────────────────────
.sidebar-notif {
  position: relative;
}

.sidebar-notif__trigger {
  display: flex;
  align-items: center;
  gap: $space-2;
  width: 100%;
  padding: $space-2;
  border: none;
  border-radius: $radius-md;
  background: transparent;
  color: $sidebar-text;
  cursor: pointer;
  text-align: left;
  position: relative;
  transition:
    background-color var(--app-transition-fast),
    color var(--app-transition-fast);

  &:hover {
    background-color: rgba(255, 255, 255, 0.05);
    color: $sidebar-text-active;
  }

  &:focus-visible {
    outline: 2px solid rgba(255, 255, 255, 0.4);
    outline-offset: 2px;
  }

  .sidebar-notif--collapsed & {
    justify-content: center;
    gap: 0;
  }
}

.sidebar-notif__icon {
  font-size: $font-size-lg;
  flex-shrink: 0;
  width: 18px;
  text-align: center;
}

.sidebar-notif__label {
  font-size: $font-size-xs; // snap from 13px
  font-weight: $font-weight-medium;
  flex: 1;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.sidebar-notif__badge {
  display: inline-flex;
  align-items: center;
  justify-content: center;
  min-width: 18px;
  height: 18px;
  padding: 0 4px;
  border-radius: $radius-badge;
  background: $color-danger;
  color: $surface-0;
  font-size: $font-size-3xs; // snap from 10px
  font-weight: 700;
  line-height: 1;
  flex-shrink: 0;
  margin-left: auto;

  .sidebar-notif--collapsed & {
    position: absolute;
    top: 2px;
    right: 2px;
    margin-left: 0;
    min-width: 16px;
    height: 16px;
  }
}
</style>
