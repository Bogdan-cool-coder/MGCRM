<template>
  <div
    :class="[
      'inbox-row',
      isUnread ? 'inbox-row--unread' : 'inbox-row--read',
    ]"
    role="button"
    tabindex="0"
    @click="emit('open', msg)"
    @keydown.enter="emit('open', msg)"
    @keydown.space.prevent="emit('open', msg)"
  >
    <!-- Unread dot indicator -->
    <span v-if="isUnread" class="inbox-row__dot" aria-hidden="true" />

    <!-- Channel badge -->
    <div class="inbox-row__channel">
      <ChannelKindTag :kind="msg.channel.kind" size="small" :show-label="true" />
    </div>

    <!-- From sender -->
    <div class="inbox-row__from">
      <div class="inbox-row__from-name">
        {{ msg.from_name || msg.from_identifier || '—' }}
      </div>
      <div v-if="msg.from_name && msg.from_identifier" class="inbox-row__from-ident">
        {{ msg.from_identifier }}
      </div>
    </div>

    <!-- Subject + body preview -->
    <div class="inbox-row__content">
      <div v-if="msg.subject" class="inbox-row__subject">{{ msg.subject }}</div>
      <div v-if="msg.body" class="inbox-row__preview">{{ msg.body }}</div>
      <div v-else-if="!msg.subject" class="inbox-row__preview inbox-row__preview--empty">
        {{ t('inbox.detail.bodyEmpty') }}
      </div>
    </div>

    <!-- Received at -->
    <div
      v-tooltip.top="fullDatetime"
      :class="['inbox-row__received', isUnread ? 'inbox-row__received--unread' : '']"
    >
      {{ relativeTime }}
    </div>

    <!-- Deal chip / routing status -->
    <div class="inbox-row__deal">
      <template v-if="msg.routing_status === 'routed' && msg.target_deal_id">
        <RouterLink :to="`/deals/${msg.target_deal_id}`" class="inbox-row__deal-link" @click.stop>
          <Tag icon="pi pi-briefcase" :value="`#${msg.target_deal_id}`" severity="success" size="small" />
          <i
            v-if="msg.target_deal_created"
            v-tooltip.top="t('inbox.dealChip.created')"
            class="pi pi-check-circle inbox-row__deal-created"
          />
        </RouterLink>
      </template>
      <template v-else-if="msg.routing_status === 'dedup' && msg.target_deal_id">
        <RouterLink :to="`/deals/${msg.target_deal_id}`" class="inbox-row__deal-link" @click.stop>
          <Tag icon="pi pi-link" :value="`#${msg.target_deal_id}`" severity="info" size="small" />
        </RouterLink>
      </template>
      <template v-else-if="msg.routing_status === 'failed'">
        <div class="inbox-row__failed-group">
          <Tag
            icon="pi pi-exclamation-triangle"
            :value="t('inbox.routingStatus.failed')"
            severity="danger"
            size="small"
          />
          <Button
            v-tooltip.top="t('inbox.reprocess.rowTooltip')"
            icon="pi pi-refresh"
            severity="danger"
            text
            size="small"
            class="inbox-row__reprocess-btn"
            :loading="reprocessPending"
            @click.stop="onReprocess"
          />
        </div>
      </template>
    </div>

    <!-- Chevron hint on hover -->
    <i class="pi pi-chevron-right inbox-row__chevron" />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Tag from 'primevue/tag'
import Button from 'primevue/button'
import ChannelKindTag from '@/components/inbox/ChannelKindTag.vue'
import { OPERATIONAL_TZ } from '@/utils/activity'
import type { InboundMessage } from '@/api/inbox'

const props = defineProps<{
  msg: InboundMessage
  reprocessPending?: boolean
}>()

const emit = defineEmits<{
  open: [msg: InboundMessage]
  reprocess: [id: number]
}>()

const { t } = useI18n()

const isUnread = computed(() => props.msg.read_at === null)

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

  // Older: show DD.MM
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

function onReprocess() {
  emit('reprocess', props.msg.id)
}
</script>

<style lang="scss" scoped>
.inbox-row {
  position: relative;
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4 $space-3 $space-6;
  border-bottom: 1px solid $surface-200;
  cursor: pointer;
  transition: background-color $transition-fast,
              color $transition-fast;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }

  &:last-child {
    border-bottom: none;
  }

  &:focus-visible {
    outline: 2px solid $primary-color;
    outline-offset: -2px;
  }

  // ── Unread state ────────────────────────────────────────────────────────────
  &--unread {
    background-color: $surface-card;

    // Dark scale is INVERTED: surface-100 ≈ #444547 (dark card surface)
    .app-dark & {
      background-color: var(--p-surface-100);
    }

    &:hover {
      background-color: $surface-50;

      .app-dark & {
        background-color: var(--p-surface-200);
      }
    }
  }

  // ── Read state ──────────────────────────────────────────────────────────────
  &--read {
    background-color: $surface-50;

    // Slightly darker than unread to preserve Gmail-style read/unread contrast
    .app-dark & {
      background-color: var(--p-surface-200);
    }

    &:hover {
      background-color: $surface-100;

      .app-dark & {
        background-color: var(--p-surface-300);
      }
    }
  }

  // Chevron hint
  &:hover .inbox-row__chevron {
    opacity: 1;
  }
}

// ── Unread dot ────────────────────────────────────────────────────────────────
.inbox-row__dot {
  position: absolute;
  left: $space-2;
  top: 50%;
  transform: translateY(-50%);
  width: 6px;
  height: 6px;
  border-radius: $radius-circle;
  background-color: $primary-color;
  flex-shrink: 0;
}

// ── Channel badge ─────────────────────────────────────────────────────────────
.inbox-row__channel {
  width: 80px;
  flex-shrink: 0;
}

// ── From sender ───────────────────────────────────────────────────────────────
.inbox-row__from {
  width: 160px;
  flex-shrink: 0;
  overflow: hidden;
}

.inbox-row__from-name {
  font-size: $font-size-sm;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .inbox-row--unread & {
    font-weight: $font-weight-semibold;
    color: $surface-900;

    .app-dark & {
      color: var(--p-surface-0);
    }
  }

  .inbox-row--read & {
    font-weight: $font-weight-normal;
    color: $surface-600;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }
}

.inbox-row__from-ident {
  font-size: $font-size-xs;
  font-family: $font-family-mono;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  color: var(--p-text-muted-color);
}

// ── Content (subject + preview) ───────────────────────────────────────────────
.inbox-row__content {
  flex: 1;
  min-width: 0;
  overflow: hidden;
}

.inbox-row__subject {
  font-size: $font-size-sm;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;

  .inbox-row--unread & {
    font-weight: $font-weight-semibold;
    color: $surface-900;

    .app-dark & {
      color: var(--p-surface-0);
    }
  }

  .inbox-row--read & {
    font-weight: $font-weight-normal;
    color: $surface-600;

    .app-dark & {
      color: var(--p-surface-400);
    }
  }
}

.inbox-row__preview {
  font-size: $font-size-xs;
  display: -webkit-box;
  -webkit-line-clamp: 1;
  -webkit-box-orient: vertical;
  overflow: hidden;
  color: $surface-500;

  .app-dark & {
    color: var(--p-surface-400);
  }

  .inbox-row--read & {
    color: $surface-400;

    .app-dark & {
      color: var(--p-surface-500);
    }
  }

  &--empty {
    font-style: italic;
  }
}

// ── Received at ───────────────────────────────────────────────────────────────
.inbox-row__received {
  width: 80px;
  text-align: right;
  font-size: $font-size-xs;
  flex-shrink: 0;
  color: $surface-400;
  font-weight: $font-weight-normal;

  .app-dark & {
    color: var(--p-surface-500);
  }

  &--unread {
    color: $surface-700;
    font-weight: $font-weight-medium;

    .app-dark & {
      color: var(--p-surface-200);
    }
  }
}

// ── Deal chip ─────────────────────────────────────────────────────────────────
.inbox-row__deal {
  width: 140px;
  flex-shrink: 0;
  display: flex;
  align-items: center;
}

.inbox-row__deal-link {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  text-decoration: none;
}

.inbox-row__deal-created {
  font-size: $font-size-xs;
  color: $green-500;

  .app-dark & {
    color: var(--p-green-400);
  }
}

.inbox-row__failed-group {
  display: flex;
  align-items: center;
  gap: $space-1;
}

.inbox-row__reprocess-btn {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 0;
  width: 24px;
  height: 24px;
}

// ── Chevron hint ──────────────────────────────────────────────────────────────
.inbox-row__chevron {
  font-size: $font-size-xs;
  color: $surface-300;
  opacity: 0;
  transition: opacity $transition-fast;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-surface-500);
  }
}
</style>
