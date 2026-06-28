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
                  @click="markRead(item)"
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
                  @click="markRead(item)"
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
                  @click="markRead(item)"
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
import { computed, onMounted, onUnmounted, ref, toRefs } from 'vue'
import { useI18n } from 'vue-i18n'
import Popover from 'primevue/popover'
import Button from 'primevue/button'
import { useNotificationsStore } from '@/stores/notificationsStore'
import { useNotificationsFlyout } from './composables/useNotificationsFlyout'
import type { OrbitaTooltipOptions } from './composables/useOrbitaTooltip'
import type { OrbitaOverlayControl, OrbitaOrientation } from './types'

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

const hasAnyNotifications = computed(
  () => actionable.value.length > 0 || feed.value.length > 0,
)

// Derive display keys from the by_category sub-object (non-zero entries only).
// digest.value shape: { unread_total?: number, by_category?: Record<string, number> }
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
    border-color: rgba($surface-900, 0.08);
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

// ─── Flyout panel ──────────────────────────────────────────────────────────
.nf {
  width: 360px;
  // Min-height ensures absolutePosition() flip logic is triggered when the bell
  // is near the bottom of the viewport (e.g. in Orbita above the MC button).
  // With min-height=320px and bell at y≈580 on a 900px screen:
  // 580+44+320=944>900 → Floating UI flips the Popover to open upward.
  // Reduces max-height slightly so the panel stays compact when open upward.
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
  border-radius: $radius-badge; // snap from 10px → 9px
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

// Scrollable: last section with feed is scrollable
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

  // Unread indicator: left accent bar
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

// ─── Dark mode ────────────────────────────────────────────────────────────────
:global(.app-dark) {
  .orbita-action-btn__label {
    color: $surface-300;
  }

  .notifications-btn__trigger {
    &:hover {
      background: $surface-800;
      border-color: rgba($surface-100, 0.1);
    }
  }

  .notifications-btn__icon {
    color: $surface-300;
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
    // stylelint-disable-next-line scale-unlimited/declaration-strict-value
    color: ButtonText; // a11y forced-colors system keyword
  }
}
</style>
