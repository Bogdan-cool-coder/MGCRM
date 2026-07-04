<template>
  <!--
    Shared notifications flyout body. Rendered inside the <Popover> of BOTH triggers:
    Orbita/NotificationsButton (panel bell) and AppShell/SidebarNotifications (sidebar
    footer bell). Previously this ~250-line panel (template + .nf__* styles) was
    duplicated verbatim in both files — the twin drifted and muted-token bugs had to
    be fixed twice (audit §3). Single source now; each trigger keeps its own distinct
    trigger markup + click semantics via emitted events.
  -->
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
        @click="emit('mark-all')"
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
              @click="emit('item-click', item)"
            >
              <span class="nf__item-title">{{ item.title }}</span>
              <span v-if="item.body" class="nf__item-body-text">{{ item.body }}</span>
              <span class="nf__item-time">{{ formatTime(item.created_at) }}</span>
            </button>
            <!-- CTA button. @click.stop keeps it from bubbling to the body button. -->
            <Button
              v-if="item.action_label && item.deep_link"
              size="small"
              outlined
              :label="item.action_label"
              class="nf__item-action-btn"
              @click.stop="emit('cta-click', item)"
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
              @click="emit('item-click', item)"
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
            @click="emit('load-more')"
          />
        </div>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import type { NotificationDto, NotificationDigestDto } from '@/entities/notification'

const props = defineProps<{
  actionable: NotificationDto[]
  feed: NotificationDto[]
  digest: NotificationDigestDto
  hasMoreFeed: boolean
  isEmpty: boolean
  loadingInitial: boolean
  loadingMore: boolean
  // useMutation.error is typed unknown; consumed only as a truthy flag in v-else-if
  loadError: unknown
  markAllPending: boolean
}>()

const emit = defineEmits<{
  /** Notification body clicked (title/text row). Parent decides markRead/navigate. */
  'item-click': [item: NotificationDto]
  /** Explicit CTA button clicked. Emitted only when item has action_label + deep_link. */
  'cta-click': [item: NotificationDto]
  /** «Прочитать все» clicked. */
  'mark-all': []
  /** «Загрузить ещё» clicked. */
  'load-more': []
}>()

const { t } = useI18n()

const hasAnyNotifications = computed(
  () => props.actionable.length > 0 || props.feed.length > 0,
)

// Derive display keys from the by_category sub-object (non-zero entries only).
const digestKeys = computed(() => {
  const cats = props.digest.by_category ?? {}
  return Object.keys(cats).filter((k) => (cats[k] ?? 0) > 0)
})

const digestCategoryCount = (key: string): number =>
  props.digest.by_category?.[key] ?? 0

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
// ─── Flyout panel (body-portaled, normal surface tokens) ─────────────────────
.nf {
  width: 360px;
  // Min-height ensures absolutePosition() flip logic triggers when the bell is
  // near the viewport bottom (Orbita above the MC button) → Floating UI flips upward.
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
  color: var(--p-text-muted-color);

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
// Sections must be able to SHRINK inside the fixed-height .nf column so their
// own overflow-y:auto activates. min-height:0 unlocks flex shrinking; the last
// section (feed) grows to take the leftover height and owns the scroll. Without
// this both sections kept intrinsic height (flex-shrink:0) and the panel
// silently clipped the tail of the list + «Загрузить ещё» (audit L1 HIGH).
.nf__section {
  flex: 0 1 auto;
  min-height: 0;
}

// The feed section (last one, holds the long list + load-more) grows to fill
// the remaining height so its own overflow-y:auto has room to scroll.
.nf__section:last-child {
  flex: 1 1 auto;
}

.nf__section + .nf__section {
  border-top: 1px solid $surface-100;
}

// Scrollable: content sections scroll internally once they can shrink (min-height:0
// above). «Загрузить ещё» sits at the end of the feed list and is reached by
// scrolling — no longer clipped by the panel.
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
  color: var(--p-text-muted-color);
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
    // theme-reactive: brand navy #172747 (light) vs accent #4C7DF0 (dark). The 7%
    // tint of the static brand constant is invisible on dark surfaces; the reactive
    // token also removes the need for the former rgba(255,255,255,.05) dark override.
    background: color-mix(in srgb, var(--p-primary-color) 7%, transparent);

    &::before {
      content: '';
      position: absolute;
      left: 0;
      top: 50%;
      transform: translateY(-50%);
      width: 3px;
      height: 60%;
      // Reactive accent: navy #172747 (light) is invisible on dark surface-100;
      // --p-primary-color lightens to #4C7DF0 in dark and reads on both.
      background: var(--p-primary-color);
      border-radius: 0 $radius-2xs $radius-2xs 0;
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
  color: var(--p-text-muted-color);
  display: -webkit-box;
  -webkit-line-clamp: 2;
  -webkit-box-orient: vertical;
  overflow: hidden;
  line-height: 1.4;
}

.nf__item-time {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
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
