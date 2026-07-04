<template>
  <div class="inbox-reading">
    <!-- Empty: nothing selected -->
    <div v-if="!selectedId && !loading" class="inbox-reading__empty">
      <i class="pi pi-envelope inbox-reading__empty-icon" />
      <p class="inbox-reading__empty-title">{{ t('inbox.reading.emptyTitle') }}</p>
      <p class="inbox-reading__empty-hint">{{ t('inbox.reading.emptyHint') }}</p>
    </div>

    <template v-else>
      <!-- Toolbar -->
      <div class="inbox-reading__toolbar">
        <!-- Back to list (mobile only) -->
        <Button
          v-if="showBack"
          icon="pi pi-chevron-left"
          :label="t('inbox.reading.backToList')"
          severity="secondary"
          text
          size="small"
          class="inbox-reading__back"
          @click="emit('back')"
        />

        <template v-if="msg">
          <InboxChannelDot :kind="msg.channel.kind" :size="38" />
          <div class="inbox-reading__ident">
            <div class="inbox-reading__ident-name">
              {{ msg.from_name || msg.from_identifier || '—' }}
            </div>
            <div class="inbox-reading__ident-sub">
              {{ t('inbox.reading.channelAndId', { channel: msg.channel.name, id: msg.id }) }}
            </div>
          </div>
          <Button
            :icon="msg.read_at ? 'pi pi-envelope' : 'pi pi-envelope-open'"
            :label="msg.read_at ? t('inbox.detail.markUnread') : t('inbox.detail.markRead')"
            severity="secondary"
            outlined
            size="small"
            :loading="markReadPending"
            class="inbox-reading__read-toggle"
            @click="emit('toggle-read')"
          />
        </template>
      </div>

      <!-- Body -->
      <div class="inbox-reading__body">
        <!-- Loading -->
        <div v-if="loading" class="inbox-reading__meta-grid">
          <div v-for="n in 6" :key="n" class="inbox-reading__meta-cell">
            <Skeleton height="10px" width="60%" class="mb-1" />
            <Skeleton height="16px" width="80%" />
          </div>
        </div>

        <!-- Error -->
        <Message v-else-if="loadError" severity="error" :closable="false">
          {{ t('inbox.detail.loadError') }}
        </Message>

        <!-- Content -->
        <template v-else-if="msg">
          <!-- Failed banner -->
          <div v-if="msg.routing_status === 'failed'" class="inbox-reading__banner">
            <i class="pi pi-exclamation-triangle inbox-reading__banner-icon" />
            <div class="inbox-reading__banner-text">
              <div class="inbox-reading__banner-title">{{ t('inbox.detail.failedBannerTitle') }}</div>
              <div class="inbox-reading__banner-hint">{{ t('inbox.detail.failedBannerHint') }}</div>
            </div>
            <Button
              icon="pi pi-refresh"
              :label="t('inbox.reprocess.button')"
              severity="danger"
              size="small"
              :loading="reprocessPending"
              class="inbox-reading__banner-btn"
              @click="emit('reprocess', msg.id)"
            />
          </div>

          <!-- Meta grid -->
          <div class="inbox-reading__meta-grid">
            <div class="inbox-reading__meta-cell">
              <span class="inbox-reading__meta-label">{{ t('inbox.detail.meta.channel') }}</span>
              <div class="inbox-reading__meta-value inbox-reading__meta-value--inline">
                <ChannelKindTag :kind="msg.channel.kind" size="small" />
                <span class="inbox-reading__channel-name">{{ msg.channel.name }}</span>
              </div>
            </div>

            <div class="inbox-reading__meta-cell">
              <span class="inbox-reading__meta-label">{{ t('inbox.detail.meta.received') }}</span>
              <span class="inbox-reading__meta-value">{{ formatDatetime(msg.received_at) }}</span>
            </div>

            <div class="inbox-reading__meta-cell">
              <span class="inbox-reading__meta-label">{{ t('inbox.detail.meta.fromName') }}</span>
              <span class="inbox-reading__meta-value">{{ msg.from_name || '—' }}</span>
            </div>

            <div class="inbox-reading__meta-cell">
              <span class="inbox-reading__meta-label">{{ t('inbox.detail.meta.fromIdent') }}</span>
              <span class="inbox-reading__meta-value inbox-reading__meta-value--mono">
                {{ msg.from_identifier || '—' }}
              </span>
            </div>

            <div class="inbox-reading__meta-cell">
              <span class="inbox-reading__meta-label">{{ t('inbox.detail.meta.routingStatus') }}</span>
              <div class="inbox-reading__meta-value">
                <Tag
                  :value="t(`inbox.routingStatus.${msg.routing_status}`)"
                  :severity="routingStatusSeverity(msg.routing_status)"
                  size="small"
                />
              </div>
            </div>

            <div class="inbox-reading__meta-cell">
              <span class="inbox-reading__meta-label">{{ t('inbox.detail.meta.deal') }}</span>
              <div class="inbox-reading__meta-value">
                <RouterLink
                  v-if="msg.target_deal_id"
                  :to="`/deals/${msg.target_deal_id}`"
                  class="inbox-reading__deal-link"
                >
                  <i :class="['pi', msg.routing_status === 'dedup' ? 'pi-link' : 'pi-briefcase']" />
                  #{{ msg.target_deal_id }}
                  <i
                    v-if="msg.target_deal_created"
                    v-tooltip.top="t('inbox.dealChip.created')"
                    class="pi pi-check-circle inbox-reading__deal-created"
                  />
                </RouterLink>
                <span v-else class="inbox-reading__meta-muted">{{ t('inbox.dealChip.none') }}</span>
              </div>
            </div>
          </div>

          <!-- Subject -->
          <div v-if="msg.subject" class="inbox-reading__section">
            <div class="inbox-reading__section-label">{{ t('inbox.detail.subjectLabel') }}</div>
            <div class="inbox-reading__subject">{{ msg.subject }}</div>
          </div>

          <!-- Body -->
          <div class="inbox-reading__section">
            <div class="inbox-reading__section-label">{{ t('inbox.detail.bodyLabel') }}</div>
            <div v-if="msg.body" class="inbox-reading__text">{{ msg.body }}</div>
            <p v-else class="inbox-reading__meta-muted inbox-reading__text--empty">
              {{ t('inbox.detail.bodyEmpty') }}
            </p>
          </div>

          <!-- Raw payload (admin / director only) -->
          <div v-if="canViewRawPayload && msg.raw_payload" class="inbox-reading__section">
            <Accordion>
              <AccordionPanel value="0">
                <AccordionHeader>{{ t('inbox.detail.rawPayload') }}</AccordionHeader>
                <AccordionContent>
                  <pre class="inbox-reading__raw">{{ JSON.stringify(msg.raw_payload, null, 2) }}</pre>
                </AccordionContent>
              </AccordionPanel>
            </Accordion>
          </div>
        </template>
      </div>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Accordion from 'primevue/accordion'
import AccordionPanel from 'primevue/accordionpanel'
import AccordionHeader from 'primevue/accordionheader'
import AccordionContent from 'primevue/accordioncontent'
import ChannelKindTag from '@/components/inbox/ChannelKindTag.vue'
import InboxChannelDot from './InboxChannelDot.vue'
import { OPERATIONAL_TZ } from '@/utils/activity'
import type { InboundMessage, RoutingStatus } from '@/api/inbox'

defineProps<{
  /** null = empty state ("select a message"). */
  selectedId: number | null
  msg: InboundMessage | null
  loading: boolean
  loadError: unknown
  reprocessPending: boolean
  markReadPending: boolean
  canViewRawPayload: boolean
  /** Show the "back to list" button (mobile single-pane mode). */
  showBack?: boolean
}>()

const emit = defineEmits<{
  'toggle-read': []
  reprocess: [id: number]
  back: []
}>()

const { t } = useI18n()

function formatDatetime(iso: string): string {
  return new Intl.DateTimeFormat('ru-RU', {
    timeZone: OPERATIONAL_TZ,
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(new Date(iso))
}

function routingStatusSeverity(status: RoutingStatus): 'success' | 'info' | 'danger' {
  const map: Record<RoutingStatus, 'success' | 'info' | 'danger'> = {
    routed: 'success',
    dedup: 'info',
    failed: 'danger',
  }
  return map[status]
}
</script>

<style lang="scss" scoped>
.inbox-reading {
  height: 100%;
  display: flex;
  flex-direction: column;
  min-height: 0;
}

// ── Empty ─────────────────────────────────────────────────────────────────────
.inbox-reading__empty {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8 $space-5;
  text-align: center;
}

.inbox-reading__empty-icon {
  font-size: $font-size-icon-lg;
  color: var(--p-text-muted-color);
  opacity: 0.5;
}

.inbox-reading__empty-title {
  margin: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.inbox-reading__empty-hint {
  margin: 0;
  max-width: 260px;
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
}

// ── Toolbar ─────────────────────────────────────────────────────────────────
.inbox-reading__toolbar {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-5;
  border-bottom: 1px solid $surface-200;
  flex-shrink: 0;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.inbox-reading__back {
  flex-shrink: 0;
}

.inbox-reading__ident {
  flex: 1;
  min-width: 0;
}

.inbox-reading__ident-name {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.inbox-reading__ident-sub {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

.inbox-reading__read-toggle {
  flex-shrink: 0;
}

// ── Body ────────────────────────────────────────────────────────────────────
.inbox-reading__body {
  flex: 1;
  overflow-y: auto;
  padding: $space-4 $space-5;
  min-height: 0;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

// ── Failed banner ─────────────────────────────────────────────────────────────
.inbox-reading__banner {
  display: flex;
  align-items: flex-start;
  gap: $space-3;
  padding: $space-3;
  margin-bottom: $space-4;
  border-radius: $radius-md;
  background: $red-50;
  border: 1px solid $red-200;

  .app-dark & {
    background: color-mix(in srgb, var(--p-red-500) 16%, var(--p-surface-100));
    border-color: var(--p-red-400);
  }
}

.inbox-reading__banner-icon {
  font-size: $font-size-md;
  color: $red-700;
  margin-top: 1px;
  flex-shrink: 0;

  .app-dark & {
    color: var(--p-red-400);
  }
}

.inbox-reading__banner-text {
  flex: 1;
  min-width: 0;
}

.inbox-reading__banner-title {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: $red-700;

  .app-dark & {
    color: var(--p-red-400);
  }
}

.inbox-reading__banner-hint {
  margin-top: $space-1;
  font-size: $font-size-xs;
  color: $red-700;
  opacity: 0.85;

  .app-dark & {
    color: var(--p-red-300);
    opacity: 1;
  }
}

.inbox-reading__banner-btn {
  flex-shrink: 0;
}

// ── Meta grid ─────────────────────────────────────────────────────────────────
.inbox-reading__meta-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: $space-4 $space-6;
  padding-block: $space-2 $space-4;
  border-bottom: 1px solid $surface-200;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.inbox-reading__meta-cell {
  display: flex;
  flex-direction: column;
  gap: $space-1;
  min-width: 0;
}

.inbox-reading__meta-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
}

.inbox-reading__meta-value {
  font-size: $font-size-sm;
  color: var(--p-text-color);
  word-break: break-word;

  &--mono {
    font-family: $font-family-mono;
    font-size: $font-size-xs;
  }

  &--inline {
    display: inline-flex;
    align-items: center;
    gap: $space-2;
  }
}

.inbox-reading__meta-muted {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
}

.inbox-reading__channel-name {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

.inbox-reading__deal-link {
  display: inline-flex;
  align-items: center;
  gap: $space-1;
  color: $primary-color;
  text-decoration: none;
  font-size: $font-size-sm;
  font-weight: $font-weight-medium;

  .app-dark & {
    color: var(--p-primary-color);
  }

  &:hover {
    text-decoration: underline;
  }

  .pi:first-child {
    font-size: $font-size-xs;
  }
}

.inbox-reading__deal-created {
  color: var(--p-green-600);
  font-size: $font-size-xs;

  .app-dark & {
    color: var(--p-green-400);
  }
}

// ── Sections ──────────────────────────────────────────────────────────────────
.inbox-reading__section {
  margin-top: $space-4;
}

.inbox-reading__section-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  margin-bottom: $space-1;
}

.inbox-reading__subject {
  font-size: $font-size-md;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.inbox-reading__text {
  font-size: $font-size-sm;
  line-height: $line-height-relaxed;
  color: var(--p-text-color);
  white-space: pre-wrap;
  word-break: break-word;

  &--empty {
    font-style: italic;
  }
}

.inbox-reading__raw {
  margin: 0;
  padding: $space-3;
  font-size: $font-size-xs;
  font-family: $font-family-mono;
  background: $surface-50;
  border-radius: $radius-sm;
  color: $surface-700;
  white-space: pre-wrap;
  word-break: break-word;
  max-height: 260px;
  overflow-y: auto;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }

  .app-dark & {
    background: var(--p-surface-50);
    color: var(--p-surface-800);
  }
}
</style>
