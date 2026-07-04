<template>
  <div
    :class="[
      'inbox-row',
      isUnread ? 'inbox-row--unread' : 'inbox-row--read',
      { 'inbox-row--selected': selected, 'inbox-row--cozy': cozy },
    ]"
    role="button"
    tabindex="0"
    @click="emit('open', msg)"
    @keydown.enter="emit('open', msg)"
    @keydown.space.prevent="emit('open', msg)"
  >
    <!-- Unread dot column (always reserved, dot only when unread) -->
    <span class="inbox-row__dot-col" aria-hidden="true">
      <span v-if="isUnread" class="inbox-row__dot" />
    </span>

    <!-- Star toggle (contour / gold) -->
    <button
      type="button"
      :class="['inbox-row__star', { 'inbox-row__star--on': isStarred }]"
      :title="isStarred ? t('inbox.star.unset') : t('inbox.star.set')"
      :aria-label="isStarred ? t('inbox.star.unset') : t('inbox.star.set')"
      :aria-pressed="isStarred"
      @click.stop="emit('star', msg.id)"
      @keydown.enter.stop
      @keydown.space.stop.prevent
    >
      <i :class="['pi', isStarred ? 'pi-star-fill' : 'pi-star']" />
    </button>

    <!-- Channel dot -->
    <InboxChannelDot :kind="msg.channel.kind" :size="cozy ? 38 : 34" />

    <!-- Body: name/ident/time + subject·preview + deal chip -->
    <div class="inbox-row__main">
      <div class="inbox-row__top">
        <i
          v-if="msg.important"
          v-tooltip.top="t('inbox.important.badge')"
          class="pi pi-flag-fill inbox-row__important"
        />
        <span class="inbox-row__name">
          {{ msg.from_name || msg.from_identifier || '—' }}
        </span>
        <span v-if="msg.from_name && msg.from_identifier" class="inbox-row__ident">
          {{ msg.from_identifier }}
        </span>
        <span v-else class="inbox-row__ident" />
        <span
          v-tooltip.top="fullDatetime"
          :class="['inbox-row__when', { 'inbox-row__when--unread': isUnread }]"
        >
          {{ relativeTime }}
        </span>
      </div>

      <div class="inbox-row__bottom">
        <span class="inbox-row__preview">
          <span v-if="msg.subject" class="inbox-row__subject">{{ msg.subject }} · </span>
          <span v-if="msg.body">{{ msg.body }}</span>
          <span v-else-if="!msg.subject" class="inbox-row__preview--empty">
            {{ t('inbox.detail.bodyEmpty') }}
          </span>
        </span>
        <span v-if="snoozedLabel" class="inbox-row__snoozed" :title="snoozedFull">
          <i class="pi pi-clock" />
          {{ snoozedLabel }}
        </span>
        <InboxDealChip :msg="msg" :pending="reprocessPending" @reprocess="emit('reprocess', $event)" />
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import InboxChannelDot from './InboxChannelDot.vue'
import InboxDealChip from './InboxDealChip.vue'
import { OPERATIONAL_TZ } from '@/utils/activity'
import type { InboundMessage } from '@/api/inbox'

const props = defineProps<{
  msg: InboundMessage
  selected?: boolean
  /** Cozy density → roomier padding + larger channel dot. */
  cozy?: boolean
  reprocessPending?: boolean
}>()

const emit = defineEmits<{
  open: [msg: InboundMessage]
  reprocess: [id: number]
  star: [id: number]
}>()

const { t } = useI18n()

const isUnread = computed(() => props.msg.read_at === null)
const isStarred = computed(() => props.msg.starred_at !== null)

/** Snoozed "until" clock label (shown in the Отложенные folder). */
const snoozedLabel = computed(() => {
  if (!props.msg.snoozed_until) return null
  const d = new Date(props.msg.snoozed_until)
  if (d.getTime() <= Date.now()) return null // already elapsed → back in Inbox
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    day: '2-digit',
    month: '2-digit',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d)
})

const snoozedFull = computed(() =>
  props.msg.snoozed_until ? t('inbox.snooze.untilTooltip', { when: snoozedLabel.value ?? '' }) : '',
)

/** Relative time display (Dubai tz) */
const relativeTime = computed(() => {
  const d = new Date(props.msg.received_at)
  const now = new Date()
  const diffMs = now.getTime() - d.getTime()
  const diffMin = Math.floor(diffMs / 60_000)
  const diffH = Math.floor(diffMs / 3_600_000)
  const diffD = Math.floor(diffMs / 86_400_000)

  if (diffMin < 60) return `${diffMin} мин`
  if (diffH < 24) return `${diffH} ч`
  if (diffD === 1) return 'вчера'

  const parts = new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    day: '2-digit',
    month: '2-digit',
  }).formatToParts(d)
  const day = parts.find((p) => p.type === 'day')?.value ?? '??'
  const month = parts.find((p) => p.type === 'month')?.value ?? '??'
  return `${day}.${month}`
})

/** Full datetime for tooltip */
const fullDatetime = computed(() => {
  const d = new Date(props.msg.received_at)
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(d)
})
</script>

<style lang="scss" scoped>
.inbox-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;
  border-left: 3px solid transparent;
  cursor: pointer;
  transition: background-color $transition-fast;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }

  &--cozy {
    padding: $space-4;
  }

  &:last-child {
    border-bottom: none;
  }

  &:hover {
    background-color: var(--mg-surface-hover);
  }

  &:focus-visible {
    outline: 2px solid $primary-color;
    outline-offset: -2px;
  }

  // ── Selected ────────────────────────────────────────────────────────────────
  &--selected {
    border-left-color: $primary-color;
    background-color: $primary-50;

    .app-dark & {
      background-color: var(--p-surface-200);
    }

    &:hover {
      background-color: $primary-50;

      .app-dark & {
        background-color: var(--p-surface-200);
      }
    }
  }
}

// ── Unread dot ──────────────────────────────────────────────────────────────
.inbox-row__dot-col {
  width: 8px;
  flex-shrink: 0;
  display: flex;
  justify-content: center;
}

.inbox-row__dot {
  width: 8px;
  height: 8px;
  border-radius: $radius-circle;
  background-color: $primary-color;
}

// ── Star toggle ───────────────────────────────────────────────────────────────
.inbox-row__star {
  flex-shrink: 0;
  width: 22px;
  height: 22px;
  border: none;
  background: transparent;
  cursor: pointer;
  display: inline-flex;
  align-items: center;
  justify-content: center;
  border-radius: $radius-sm;
  // Empty-star colour: muted contour (theme-reactive).
  color: $surface-300;
  transition: color $transition-fast, background-color $transition-fast;

  .pi {
    font-size: $font-size-sm;
  }

  .app-dark & {
    color: var(--p-surface-300);
  }

  &:hover {
    color: $stage-color-amber;
    background: var(--mg-surface-hover);
  }

  &:focus-visible {
    outline: 2px solid $primary-color;
    outline-offset: -2px;
  }

  // Starred (gold, vivid brand amber — identical in both themes).
  &--on {
    color: $stage-color-amber;

    .app-dark & {
      color: $stage-color-amber;
    }
  }
}

// ── Important flag ──────────────────────────────────────────────────────────────
.inbox-row__important {
  flex-shrink: 0;
  font-size: $font-size-xs;
  color: var(--p-orange-500);

  .app-dark & {
    color: var(--p-orange-400);
  }
}

// ── Main body ───────────────────────────────────────────────────────────────
.inbox-row__main {
  flex: 1;
  min-width: 0;
}

.inbox-row__top {
  display: flex;
  align-items: baseline;
  gap: $space-2;
}

.inbox-row__name {
  font-size: $font-size-sm;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  max-width: 55%;

  .inbox-row--unread & {
    font-weight: $font-weight-semibold;
    color: $surface-900;

    .app-dark & {
      color: var(--p-surface-900); // light text in dark (inverted scale)
    }
  }

  .inbox-row--read & {
    font-weight: $font-weight-normal;
    // Light: surface-700 (#616263) → 6.11:1 on white (surface-600 was 4.00:1, WCAG-fail).
    // Dark override kept at surface-600 (#8593B0) → 5.37:1 on navy card — passes AA and
    // preserves the read/unread muting (surface-700 in dark = #B4C2DA is too light, ~9:1,
    // collapses the contrast against the unread name).
    color: $surface-700;

    .app-dark & {
      color: var(--p-surface-600);
    }
  }
}

.inbox-row__ident {
  flex: 1;
  min-width: 0;
  font-size: $font-size-xs;
  font-family: $font-family-mono;
  color: var(--p-text-muted-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.inbox-row__when {
  flex-shrink: 0;
  font-size: $font-size-xs;
  color: $surface-400;
  font-weight: $font-weight-normal;

  .app-dark & {
    color: var(--p-surface-500);
  }

  &--unread {
    color: $primary-color;
    font-weight: $font-weight-medium;
  }
}

.inbox-row__bottom {
  display: flex;
  align-items: center;
  gap: $space-2;
  margin-top: $space-1;
}

.inbox-row__preview {
  flex: 1;
  min-width: 0;
  font-size: $font-size-sm;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
  color: $surface-600;

  .app-dark & {
    color: var(--p-surface-600);
  }

  .inbox-row--read & {
    color: $surface-500;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }
}

.inbox-row__subject {
  .inbox-row--unread & {
    font-weight: $font-weight-semibold;
    color: $surface-900;

    .app-dark & {
      color: var(--p-surface-900);
    }
  }

  .inbox-row--read & {
    font-weight: $font-weight-medium;
    color: $surface-600;

    .app-dark & {
      color: var(--p-surface-600);
    }
  }
}

.inbox-row__preview--empty {
  font-style: italic;
}

// ── Snoozed-until label (Отложенные folder) ─────────────────────────────────────
.inbox-row__snoozed {
  flex-shrink: 0;
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  white-space: nowrap;
  color: var(--p-primary-color);

  .pi {
    font-size: $font-size-2xs;
  }
}
</style>
