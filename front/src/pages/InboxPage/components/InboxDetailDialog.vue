<template>
  <Dialog
    v-model:visible="visible"
    modal
    :draggable="false"
    dismissable-mask
    :style="{ width: '680px', maxWidth: '95vw' }"
    @hide="emit('close')"
  >
    <!-- Custom header -->
    <template #header>
      <div class="inbox-detail__header">
        <span class="inbox-detail__title">
          {{ t('inbox.detail.title') }} #{{ msg?.id }}
        </span>
        <div class="inbox-detail__header-actions">
          <!-- Mark read/unread toggle -->
          <Button
            :icon="msg?.read_at ? 'pi pi-envelope' : 'pi pi-envelope-open'"
            :label="msg?.read_at ? t('inbox.detail.markUnread') : t('inbox.detail.markRead')"
            severity="secondary"
            text
            size="small"
            :loading="markReadPending"
            @click="onToggleRead"
          />
          <!-- Close button (spec: custom header slot hides native close) -->
          <Button
            icon="pi pi-times"
            severity="secondary"
            text
            size="small"
            :aria-label="t('inbox.detail.close')"
            @click="emit('close')"
          />
        </div>
      </div>
    </template>

    <!-- Loading state -->
    <template v-if="loading">
      <div class="inbox-detail__meta-grid">
        <div v-for="n in 6" :key="n" class="inbox-detail__meta-cell">
          <Skeleton height="10px" width="60%" class="mb-1" />
          <Skeleton height="16px" width="80%" />
        </div>
      </div>
    </template>

    <!-- Error state -->
    <template v-else-if="loadError">
      <Message severity="error" :closable="false">
        {{ t('inbox.detail.loadError') }}
      </Message>
    </template>

    <!-- Content -->
    <template v-else-if="msg">
      <!-- Failed alert -->
      <Message
        v-if="msg.routing_status === 'failed'"
        severity="error"
        :closable="false"
        class="mb-3"
      >
        {{ t('inbox.detail.failedAlert') }}
      </Message>

      <!-- Meta grid -->
      <div class="inbox-detail__meta-grid">
        <!-- Channel -->
        <div class="inbox-detail__meta-cell">
          <span class="inbox-detail__meta-label">{{ t('inbox.detail.meta.channel') }}</span>
          <div class="inbox-detail__meta-value d-flex align-items-center gap-2">
            <ChannelKindTag :kind="msg.channel.kind" size="small" />
            <span class="inbox-detail__channel-name">{{ msg.channel.name }}</span>
          </div>
        </div>

        <!-- Received -->
        <div class="inbox-detail__meta-cell">
          <span class="inbox-detail__meta-label">{{ t('inbox.detail.meta.received') }}</span>
          <span class="inbox-detail__meta-value">{{ formatDatetime(msg.received_at) }}</span>
        </div>

        <!-- From name -->
        <div class="inbox-detail__meta-cell">
          <span class="inbox-detail__meta-label">{{ t('inbox.detail.meta.fromName') }}</span>
          <span class="inbox-detail__meta-value">{{ msg.from_name || '—' }}</span>
        </div>

        <!-- From identifier -->
        <div class="inbox-detail__meta-cell">
          <span class="inbox-detail__meta-label">{{ t('inbox.detail.meta.fromIdent') }}</span>
          <span class="inbox-detail__meta-value inbox-detail__meta-value--mono">
            {{ msg.from_identifier || '—' }}
          </span>
        </div>

        <!-- Routing status -->
        <div class="inbox-detail__meta-cell">
          <span class="inbox-detail__meta-label">{{ t('inbox.detail.meta.routingStatus') }}</span>
          <div class="inbox-detail__meta-value">
            <Tag
              :value="t(`inbox.routingStatus.${msg.routing_status}`)"
              :severity="routingStatusSeverity(msg.routing_status)"
              size="small"
            />
          </div>
        </div>

        <!-- Deal -->
        <div class="inbox-detail__meta-cell">
          <span class="inbox-detail__meta-label">{{ t('inbox.detail.meta.deal') }}</span>
          <div class="inbox-detail__meta-value">
            <template v-if="msg.target_deal_id">
              <RouterLink
                :to="`/deals/${msg.target_deal_id}`"
                class="inbox-detail__deal-link"
                @click="emit('close')"
              >
                #{{ msg.target_deal_id }}
                <i
                  v-if="msg.target_deal_created"
                  v-tooltip.top="t('inbox.dealChip.created')"
                  class="pi pi-check-circle ms-1"
                />
              </RouterLink>
            </template>
            <span v-else class="inbox-detail__meta-muted">{{ t('inbox.dealChip.none') }}</span>
          </div>
        </div>
      </div>

      <!-- Subject -->
      <div v-if="msg.subject" class="inbox-detail__section mt-3">
        <span class="inbox-detail__section-label">{{ t('inbox.detail.subjectLabel') }}</span>
        <p class="inbox-detail__subject">{{ msg.subject }}</p>
      </div>

      <!-- Body -->
      <div class="inbox-detail__section mt-3">
        <span class="inbox-detail__section-label">{{ t('inbox.detail.bodyLabel') }}</span>
        <div v-if="msg.body" class="inbox-detail__body">
          {{ msg.body }}
        </div>
        <p v-else class="inbox-detail__meta-muted" style="font-style: italic">
          {{ t('inbox.detail.bodyEmpty') }}
        </p>
      </div>

      <!-- Raw payload (admin / director only) -->
      <div v-if="canViewRawPayload && msg.raw_payload" class="inbox-detail__section mt-3">
        <Accordion>
          <AccordionPanel value="0">
            <AccordionHeader>{{ t('inbox.detail.rawPayload') }}</AccordionHeader>
            <AccordionContent>
              <pre class="inbox-detail__raw">{{ JSON.stringify(msg.raw_payload, null, 2) }}</pre>
            </AccordionContent>
          </AccordionPanel>
        </Accordion>
      </div>
    </template>

    <!-- Footer -->
    <template #footer>
      <div class="inbox-detail__footer">
        <Button
          v-if="msg?.routing_status === 'failed'"
          icon="pi pi-refresh"
          :label="t('inbox.reprocess.button')"
          severity="primary"
          :loading="reprocessPending"
          @click="emit('reprocess', msg!.id)"
        />
        <Button
          :label="t('inbox.detail.close')"
          severity="secondary"
          outlined
          @click="emit('close')"
        />
      </div>
    </template>
  </Dialog>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { RouterLink } from 'vue-router'
import Dialog from 'primevue/dialog'
import Button from 'primevue/button'
import Tag from 'primevue/tag'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import Accordion from 'primevue/accordion'
import AccordionPanel from 'primevue/accordionpanel'
import AccordionHeader from 'primevue/accordionheader'
import AccordionContent from 'primevue/accordioncontent'
import ChannelKindTag from '@/components/inbox/ChannelKindTag.vue'
import { OPERATIONAL_TZ } from '@/utils/activity'
import type { InboundMessage, RoutingStatus } from '@/api/inbox'

const props = defineProps<{
  modelValue: boolean
  msg: InboundMessage | null
  loading: boolean
  loadError: unknown
  reprocessPending: boolean
  markReadPending: boolean
  canViewRawPayload: boolean
}>()

const emit = defineEmits<{
  'update:modelValue': [value: boolean]
  close: []
  toggleRead: []
  reprocess: [id: number]
}>()

const { t } = useI18n()

const visible = computed({
  get: () => props.modelValue,
  set: (v) => emit('update:modelValue', v),
})

function onToggleRead() {
  emit('toggleRead')
}

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
.inbox-detail__header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  width: 100%;
  gap: $space-3;
}

.inbox-detail__title {
  font-size: $font-size-lg;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.inbox-detail__header-actions {
  display: flex;
  align-items: center;
  gap: $space-1;
  margin-left: auto;
}

// ── Meta grid ─────────────────────────────────────────────────────────────────
.inbox-detail__meta-grid {
  display: grid;
  grid-template-columns: 1fr 1fr;
  gap: $space-3;
  padding: $space-3;
  background-color: $surface-50;
  border: 1px solid $surface-200;
  border-radius: $radius-md;

  // Dark scale inverted: surface-100 ≈ #444547 (dark panel), surface-300 = divider
  .app-dark & {
    background-color: var(--p-surface-100);
    border-color: var(--p-surface-300);
  }
}

.inbox-detail__meta-cell {
  display: flex;
  flex-direction: column;
  gap: 4px;
}

.inbox-detail__meta-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
}

.inbox-detail__meta-value {
  font-size: $font-size-sm;
  color: var(--p-text-color);

  &--mono {
    font-family: $font-family-mono;
    font-size: $font-size-xs;
  }
}

.inbox-detail__meta-muted {
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
}

.inbox-detail__channel-name {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

.inbox-detail__deal-link {
  color: $primary-color;
  text-decoration: none;
  font-size: $font-size-sm;

  &:hover {
    text-decoration: underline;
  }
}

// ── Section ───────────────────────────────────────────────────────────────────
.inbox-detail__section {
  display: flex;
  flex-direction: column;
  gap: $space-1;
}

.inbox-detail__section-label {
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
}

.inbox-detail__subject {
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  margin: 0;
}

.inbox-detail__body {
  background-color: $surface-50;
  border: 1px solid $surface-200;
  border-radius: $radius-sm;
  padding: $space-3;
  font-size: $font-size-sm;
  white-space: pre-wrap;
  max-height: 260px;
  overflow-y: auto;
  scrollbar-width: none;
  color: var(--p-text-color);

  &::-webkit-scrollbar {
    display: none;
  }

  .app-dark & {
    background-color: var(--p-surface-100);
    border-color: var(--p-surface-300);
  }
}

.inbox-detail__raw {
  font-size: $font-size-xs;
  font-family: $font-family-mono;
  background-color: $surface-50;
  border-radius: $radius-sm;
  padding: $space-3;
  overflow-x: auto;
  overflow-y: auto;
  max-height: 200px;
  white-space: pre-wrap;
  color: $surface-700;
  margin: 0;

  .app-dark & {
    background-color: var(--p-surface-100);
    color: var(--p-surface-200);
  }
}

// ── Footer ────────────────────────────────────────────────────────────────────
.inbox-detail__footer {
  display: flex;
  align-items: center;
  justify-content: flex-end;
  gap: $space-2;
  width: 100%;

  > :first-child:not(:last-child) {
    margin-right: auto;
  }
}
</style>
