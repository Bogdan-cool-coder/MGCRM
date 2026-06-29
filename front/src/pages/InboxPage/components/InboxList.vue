<template>
  <Card class="inbox-list" :pt="{ body: { style: 'padding: 0' }, content: { style: 'padding: 0' } }">
    <!-- Column header row (sticky) -->
    <template #header>
      <div class="inbox-list__header-row">
        <div class="inbox-list__col-dot" />
        <div class="inbox-list__col-channel">{{ t('inbox.columns.channel') }}</div>
        <div class="inbox-list__col-from">{{ t('inbox.columns.from') }}</div>
        <div class="inbox-list__col-subject">{{ t('inbox.columns.subject') }}</div>
        <div class="inbox-list__col-when">{{ t('inbox.columns.when') }}</div>
        <div class="inbox-list__col-deal">{{ t('inbox.columns.deal') }}</div>
        <div class="inbox-list__col-chevron" />
      </div>
    </template>

    <template #content>
      <!-- Loading skeleton -->
      <template v-if="loading">
        <div
          v-for="n in 8"
          :key="n"
          class="inbox-list__skeleton-row"
        >
          <Skeleton shape="circle" size="6px" />
          <Skeleton width="80px" height="22px" border-radius="4px" />
          <Skeleton width="120px" height="16px" />
          <Skeleton width="100%" height="16px" />
          <Skeleton width="50px" height="16px" />
          <Skeleton width="80px" height="22px" border-radius="4px" />
        </div>
      </template>

      <!-- Error state -->
      <template v-else-if="error">
        <div class="inbox-list__state">
          <Message severity="error" :closable="false">
            {{ t('inbox.error.loadFailed') }}
            <Button
              :label="t('inbox.error.retry')"
              text
              size="small"
              class="ms-2"
              @click="emit('refresh')"
            />
          </Message>
        </div>
      </template>

      <!-- Empty state -->
      <template v-else-if="messages.length === 0">
        <div class="inbox-list__state inbox-list__state--empty">
          <template v-if="isFailedFilter">
            <i class="pi pi-check-circle inbox-list__empty-icon inbox-list__empty-icon--success" />
            <p class="inbox-list__empty-title">{{ t('inbox.empty.failedTitle') }}</p>
            <p class="inbox-list__empty-body">{{ t('inbox.empty.failedBody') }}</p>
          </template>
          <template v-else>
            <i class="pi pi-inbox inbox-list__empty-icon" />
            <p class="inbox-list__empty-title">{{ t('inbox.empty.title') }}</p>
            <p class="inbox-list__empty-body">{{ t('inbox.empty.body') }}</p>
          </template>
        </div>
      </template>

      <!-- Message rows -->
      <template v-else>
        <InboxMessageRow
          v-for="msg in messages"
          :key="msg.id"
          :msg="msg"
          :reprocess-pending="reprocessingId === msg.id"
          @open="emit('open', $event)"
          @reprocess="onReprocessRow"
        />
      </template>

      <!-- Paginator -->
      <Paginator
        v-if="!loading && !error && totalRecords > perPage"
        :rows="perPage"
        :total-records="totalRecords"
        :rows-per-page-options="[15, 30, 50]"
        template="FirstPageLink PrevPageLink PageLinks NextPageLink LastPageLink RowsPerPageDropdown"
        class="inbox-list__paginator"
        @page="emit('page', $event)"
      />
    </template>
  </Card>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import Card from 'primevue/card'
import Message from 'primevue/message'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Paginator from 'primevue/paginator'
import InboxMessageRow from './InboxMessageRow.vue'
import type { InboundMessage } from '@/api/inbox'

const props = defineProps<{
  messages: InboundMessage[]
  loading: boolean
  error: unknown
  totalRecords: number
  perPage: number
  isFailedFilter: boolean
}>()

const emit = defineEmits<{
  open: [msg: InboundMessage]
  reprocess: [id: number]
  page: [event: { page: number; rows: number }]
  refresh: []
}>()

const { t } = useI18n()

const reprocessingId = ref<number | null>(null)

async function onReprocessRow(id: number) {
  reprocessingId.value = id
  emit('reprocess', id)
  // Parent handles the actual call; reset after a short delay
  setTimeout(() => {
    if (reprocessingId.value === id) reprocessingId.value = null
  }, 3000)
}

// Expose reprocessingId setter so parent can clear it on completion
defineExpose({ props })
</script>

<style lang="scss" scoped>
.inbox-list {
  overflow: hidden;

  :deep(.p-card-header) {
    padding: 0;
  }

  :deep(.p-card-body) {
    padding: 0;
  }

  :deep(.p-card-content) {
    padding: 0;
  }
}

// ── Column header row ─────────────────────────────────────────────────────────
.inbox-list__header-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-2 $space-4 $space-2 $space-6;
  background-color: $surface-50;
  border-bottom: 1px solid $surface-200;
  font-size: $font-size-xs;
  font-weight: $font-weight-medium;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--p-text-muted-color);
  position: sticky;
  top: 0;
  z-index: 1;

  .app-dark & {
    background-color: var(--p-surface-800);
    border-bottom-color: var(--p-surface-700);
  }
}

.inbox-list__col-dot {
  width: 6px;
  flex-shrink: 0;
}

.inbox-list__col-channel {
  width: 80px;
  flex-shrink: 0;
}

.inbox-list__col-from {
  width: 160px;
  flex-shrink: 0;
}

.inbox-list__col-subject {
  flex: 1;
}

.inbox-list__col-when {
  width: 80px;
  text-align: right;
  flex-shrink: 0;
}

.inbox-list__col-deal {
  width: 140px;
  flex-shrink: 0;
}

.inbox-list__col-chevron {
  width: 16px;
  flex-shrink: 0;
}

// ── Skeleton rows ─────────────────────────────────────────────────────────────
.inbox-list__skeleton-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4 $space-3 $space-6;
  min-height: 52px;
  border-bottom: 1px solid $surface-200;

  .app-dark & {
    border-bottom-color: var(--p-surface-700);
  }

  &:last-child {
    border-bottom: none;
  }
}

// ── State (empty, error) ──────────────────────────────────────────────────────
.inbox-list__state {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 3rem $space-4;

  &--empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
}

.inbox-list__empty-icon {
  font-size: $font-size-3xl;
  color: var(--p-text-muted-color);
  opacity: 0.4;
}

.inbox-list__empty-icon--success {
  color: $green-500;
  opacity: 0.8;

  .app-dark & {
    color: var(--p-green-400);
  }
}

.inbox-list__empty-title {
  margin-top: $space-3;
  margin-bottom: $space-1;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  font-size: $font-size-sm;
}

.inbox-list__empty-body {
  color: var(--p-text-muted-color);
  font-size: $font-size-sm;
  margin: 0;
}

// ── Paginator ─────────────────────────────────────────────────────────────────
.inbox-list__paginator {
  border-top: 1px solid $surface-200;

  .app-dark & {
    border-top-color: var(--p-surface-700);
  }
}
</style>
