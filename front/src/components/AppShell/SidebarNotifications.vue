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
      <div
        class="nf"
        role="dialog"
        :aria-label="t('orbita.notifications')"
      >
        <!-- Header -->
        <div class="nf__header">
          <span class="nf__title">{{ t('orbita.notifications') }}</span>
          <Button
            v-if="hasAnyNotifications"
            text
            size="small"
            :label="t('orbita.markAllRead')"
            :loading="markAllPending"
            class="nf__mark-all-btn"
            @click="markAllRead"
          />
        </div>

        <!-- Loading skeleton -->
        <div v-if="loadingInitial" class="nf__skeleton-list">
          <div v-for="n in 3" :key="n" class="nf__skeleton-item" />
        </div>

        <!-- Error state -->
        <div v-else-if="loadError" class="nf__empty">
          <i class="pi pi-exclamation-circle nf__empty-icon nf__empty-icon--error" />
          <p>{{ t('orbita.notificationsError') }}</p>
        </div>

        <!-- Empty state -->
        <div v-else-if="isEmpty" class="nf__empty">
          <i class="pi pi-bell nf__empty-icon" />
          <p>{{ t('orbita.noNotifications') }}</p>
        </div>

        <template v-else>
          <!-- Digest chips -->
          <div v-if="digestKeys.length > 0" class="nf__digest">
            <span
              v-for="key in digestKeys"
              :key="key"
              class="nf__digest-chip"
            >
              <span class="nf__digest-chip-count">{{ digestCategoryCount(key) }}</span>
              {{ t(`orbita.digest.${key}`, key) }}
            </span>
          </div>

          <!-- Actionable bucket -->
          <section v-if="actionable.length > 0" class="nf__section">
            <div class="nf__section-header">
              <i class="pi pi-bolt nf__section-icon" />
              <span>{{ t('orbita.sectionActionable') }}</span>
            </div>
            <ul class="nf__list" role="list">
              <li
                v-for="item in actionable"
                :key="item.id"
                class="nf__item"
                :class="{ 'nf__item--unread': !item.is_read }"
              >
                <button
                  class="nf__item-body"
                  @click="onItemClick(item)"
                >
                  <span class="nf__item-title">{{ item.title }}</span>
                  <span v-if="item.body" class="nf__item-body-text">{{ item.body }}</span>
                  <span class="nf__item-time">{{ formatTime(item.created_at) }}</span>
                </button>
                <Button
                  v-if="item.action_label && item.deep_link"
                  size="small"
                  outlined
                  :label="item.action_label"
                  class="nf__item-action-btn"
                  @click="onItemClick(item)"
                />
              </li>
            </ul>
          </section>

          <!-- Feed bucket -->
          <section v-if="feed.length > 0" class="nf__section">
            <div class="nf__section-header">
              <i class="pi pi-list nf__section-icon" />
              <span>{{ t('orbita.sectionFeed') }}</span>
            </div>
            <ul class="nf__list" role="list">
              <li
                v-for="item in feed"
                :key="item.id"
                class="nf__item"
                :class="{ 'nf__item--unread': !item.is_read }"
              >
                <button
                  class="nf__item-body"
                  @click="onItemClick(item)"
                >
                  <span class="nf__item-title">{{ item.title }}</span>
                  <span v-if="item.body" class="nf__item-body-text">{{ item.body }}</span>
                  <span class="nf__item-time">{{ formatTime(item.created_at) }}</span>
                </button>
              </li>
            </ul>

            <!-- Load more -->
            <div v-if="hasMoreFeed" class="nf__load-more">
              <Button
                text
                size="small"
                :label="t('orbita.loadMore')"
                :loading="loadingMore"
                @click="loadMore"
              />
            </div>
          </section>
        </template>
      </div>
    </Popover>
  </div>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Button from 'primevue/button'
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

const hasAnyNotifications = computed(
  () => actionable.value.length > 0 || feed.value.length > 0,
)

const digestKeys = computed(() => {
  const cats = digest.value.by_category ?? {}
  return Object.keys(cats).filter((k) => (cats[k] ?? 0) > 0)
})

const digestCategoryCount = (key: string): number =>
  digest.value.by_category?.[key] ?? 0

// ─── PrimeVue Popover pass-through ────────────────────────────────────────
const popoverPt = {
  root: { style: 'z-index: 9999; padding: 0; overflow: hidden;' },
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

// ─── Helpers ──────────────────────────────────────────────────────────────
function formatTime(iso: string): string {
  const d = new Date(iso)
  const now = new Date()
  const diffMs = now.getTime() - d.getTime()
  const diffMin = Math.floor(diffMs / 60_000)
  const diffH = Math.floor(diffMin / 60)
  const diffD = Math.floor(diffH / 24)

  if (diffMin < 1) return t('orbita.timeJustNow')
  if (diffMin < 60) return t('orbita.timeMinutes', { n: diffMin })
  if (diffH < 24) return t('orbita.timeHours', { n: diffH })
  if (diffD < 7) return t('orbita.timeDays', { n: diffD })

  return d.toLocaleDateString()
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

// ─── Flyout panel (body-portaled, normal surface) ────────────────────────────
.nf {
  width: 360px;
  min-height: 320px;
  max-height: 480px;
  display: flex;
  flex-direction: column;
  overflow: hidden;
  background: $surface-card;
}

.nf__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  flex-shrink: 0;
}

.nf__title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.nf__mark-all-btn {
  font-size: $font-size-xs !important;
  padding: 0 !important;
  color: $primary !important;
}

// ─── Skeleton ──────────────────────────────────────────────────────────────
.nf__skeleton-list {
  padding: $space-3 $space-4;
  display: flex;
  flex-direction: column;
  gap: $space-3;
}

.nf__skeleton-item {
  height: 48px;
  border-radius: $radius-md;
  background: $surface-100;
  animation: nf-pulse 1.4s ease-in-out infinite;
}

@keyframes nf-pulse {
  0%, 100% { opacity: 0.5; }
  50%       { opacity: 1; }
}

// ─── Empty / Error ─────────────────────────────────────────────────────────
.nf__empty {
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

.nf__empty-icon {
  font-size: $font-size-icon-lg;
  opacity: 0.4;

  &--error {
    color: $color-danger;
    opacity: 0.6;
  }
}

// ─── Digest chips ──────────────────────────────────────────────────────────
.nf__digest {
  display: flex;
  flex-wrap: wrap;
  gap: $space-2;
  padding: $space-3 $space-4 0;
  flex-shrink: 0;
}

.nf__digest-chip {
  display: inline-flex;
  align-items: center;
  gap: 4px;
  padding: 2px 8px;
  border-radius: $radius-badge;
  background: $surface-100;
  font-size: $font-size-xs;
  color: $surface-700;
  border: 1px solid $surface-200;
}

.nf__digest-chip-count {
  font-weight: $font-weight-bold;
  color: $primary;
}

// ─── Section ───────────────────────────────────────────────────────────────
.nf__section {
  flex-shrink: 0;
}

.nf__section + .nf__section {
  border-top: 1px solid $surface-100;
}

.nf {
  > :not(.nf__header, .nf__digest, .nf__skeleton-list, .nf__empty) {
    overflow-y: auto;
    scrollbar-width: thin;

    &::-webkit-scrollbar {
      width: 4px;
    }
    &::-webkit-scrollbar-thumb {
      background: $surface-300;
      border-radius: $radius-2xs;
    }
  }
}

.nf__section-header {
  display: flex;
  align-items: center;
  gap: $space-2;
  padding: $space-2 $space-4;
  font-size: $font-size-xs;
  font-weight: $font-weight-semibold;
  color: $surface-500;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  background: $surface-50;
  border-bottom: 1px solid $surface-100;
}

.nf__section-icon {
  font-size: $font-size-2xs;
}

// ─── Notification list ─────────────────────────────────────────────────────
.nf__list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.nf__item {
  display: flex;
  align-items: flex-start;
  gap: $space-2;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-100;
  transition: background-color $transition-fast;
  position: relative;

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background: $surface-50;
  }

  &--unread {
    background: rgba($primary, 0.07);

    &::before {
      content: '';
      position: absolute;
      left: 0;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 60%;
      background: $primary;
      border-radius: 0 $radius-2xs $radius-2xs 0;

      .app-dark & {
        // Navy $primary (#172747) is invisible on dark surface-100 (#444547);
        // use a lighter accent that reads on dark bg.
        background: var(--p-primary-400);
      }
    }

    .app-dark & {
      background: rgba(255, 255, 255, 0.05);
    }
  }
}

.nf__item-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 2px;
  background: none;
  border: none;
  padding: 0;
  cursor: pointer;
  text-align: left;
  min-width: 0;
}

.nf__item-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;
  color: var(--p-text-color);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  display: block;

  .nf__item--unread & {
    font-weight: $font-weight-semibold;
  }
}

.nf__item-body-text {
  font-size: $font-size-xs;
  color: $surface-500;
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.4;
}

.nf__item-time {
  font-size: $font-size-xs;
  color: $surface-400;
  margin-top: 2px;
}

.nf__item-action-btn {
  flex-shrink: 0;
  align-self: center;
  font-size: $font-size-2xs !important;
}

// ─── Load more ─────────────────────────────────────────────────────────────
.nf__load-more {
  display: flex;
  justify-content: center;
  padding: $space-2 $space-4;
  border-top: 1px solid $surface-100;
}
</style>
