<template>
  <div class="inbox-list">
    <!-- Scrollable rows -->
    <div class="inbox-list__scroll">
      <!-- Loading skeleton -->
      <template v-if="loading">
        <div v-for="n in 8" :key="n" class="inbox-list__skeleton-row">
          <Skeleton shape="circle" size="34px" />
          <div class="inbox-list__skeleton-lines">
            <Skeleton height="16px" width="55%" class="mb-1" />
            <Skeleton height="14px" width="85%" />
          </div>
        </div>
      </template>

      <!-- Error state -->
      <div v-else-if="error" class="inbox-list__state">
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

      <!-- Empty state -->
      <div v-else-if="messages.length === 0" class="inbox-list__state inbox-list__state--empty">
        <template v-if="isFailedFilter">
          <i class="pi pi-check-circle inbox-list__empty-icon inbox-list__empty-icon--success" />
          <p class="inbox-list__empty-title">{{ t('inbox.empty.failedTitle') }}</p>
          <p class="inbox-list__empty-body">{{ t('inbox.empty.failedBody') }}</p>
        </template>
        <template v-else-if="isPlainInbox">
          <i class="pi pi-inbox inbox-list__empty-icon" />
          <p class="inbox-list__empty-title">{{ t('inbox.empty.title') }}</p>
          <p class="inbox-list__empty-body">{{ t('inbox.empty.body') }}</p>
          <Button
            v-if="hasActiveFilters"
            :label="t('inbox.filters.reset')"
            text
            size="small"
            class="inbox-list__empty-reset"
            @click="emit('reset')"
          />
        </template>
        <template v-else>
          <i :class="['pi', folderEmptyIcon, 'inbox-list__empty-icon']" />
          <p class="inbox-list__empty-title">{{ t('inbox.empty.folderTitle') }}</p>
          <p class="inbox-list__empty-body">{{ t('inbox.empty.folderBody') }}</p>
          <Button
            v-if="hasActiveFilters"
            :label="t('inbox.filters.reset')"
            text
            size="small"
            class="inbox-list__empty-reset"
            @click="emit('reset')"
          />
        </template>
      </div>

      <!-- Message rows -->
      <template v-else>
        <InboxMessageRow
          v-for="msg in messages"
          :key="msg.id"
          :msg="msg"
          :selected="msg.id === selectedId"
          :cozy="cozy"
          :reprocess-pending="activeReprocessId === msg.id"
          @open="emit('open', $event)"
          @reprocess="emit('reprocess', $event)"
          @star="emit('star', $event)"
        />
      </template>
    </div>

    <!-- Footer: count + paginator -->
    <div v-if="!loading && !error && messages.length > 0" class="inbox-list__footer">
      <span class="inbox-list__count">
        {{ t('inbox.list.shownOf', { shown: messages.length, total: totalRecords }) }}
      </span>
      <Paginator
        v-if="totalRecords > perPage"
        :rows="perPage"
        :total-records="totalRecords"
        template="PrevPageLink PageLinks NextPageLink"
        class="inbox-list__paginator"
        @page="emit('page', $event)"
      />
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Message from 'primevue/message'
import Button from 'primevue/button'
import Skeleton from 'primevue/skeleton'
import Paginator from 'primevue/paginator'
import InboxMessageRow from './InboxMessageRow.vue'
import type { InboundMessage } from '@/api/inbox'
import type { InboxFolder } from '../composables/useInboxPage'

const props = defineProps<{
  messages: InboundMessage[]
  loading: boolean
  error: unknown
  totalRecords: number
  perPage: number
  selectedId: number | null
  cozy: boolean
  isFailedFilter: boolean
  hasActiveFilters: boolean
  /** Current folder — drives the empty-state copy/icon. */
  folder: InboxFolder
  /** ID of the row currently being reprocessed; spinner shows on that row only. */
  activeReprocessId?: number | null
}>()

const emit = defineEmits<{
  open: [msg: InboundMessage]
  reprocess: [id: number]
  star: [id: number]
  page: [event: { page: number; rows: number }]
  refresh: []
  reset: []
}>()

const { t } = useI18n()

/** Plain "Входящие" (or a channel filter within it) → the generic inbox empty copy. */
const isPlainInbox = computed(() => props.folder === 'all')

const folderEmptyIcon = computed(() => {
  const map: Partial<Record<InboxFolder, string>> = {
    starred: 'pi-star',
    important: 'pi-flag',
    deals: 'pi-briefcase',
    snoozed: 'pi-clock',
  }
  return map[props.folder] ?? 'pi-inbox'
})
</script>

<style lang="scss" scoped>
.inbox-list {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.inbox-list__scroll {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  scrollbar-width: none;

  &::-webkit-scrollbar {
    display: none;
  }
}

// ── Skeleton rows ─────────────────────────────────────────────────────────────
.inbox-list__skeleton-row {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-4;
  border-bottom: 1px solid $surface-200;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }

  &:last-child {
    border-bottom: none;
  }
}

.inbox-list__skeleton-lines {
  flex: 1;
  min-width: 0;
}

// ── State (empty, error) ──────────────────────────────────────────────────────
.inbox-list__state {
  padding: $space-8 $space-4;

  &--empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
}

.inbox-list__empty-icon {
  font-size: $font-size-icon-lg;
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

.inbox-list__empty-reset {
  margin-top: $space-2;
}

// ── Footer ────────────────────────────────────────────────────────────────────
.inbox-list__footer {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: $space-2;
  padding: $space-2 $space-4;
  border-top: 1px solid $surface-200;
  flex-shrink: 0;

  .app-dark & {
    border-top-color: var(--p-surface-200);
  }
}

.inbox-list__count {
  font-size: $font-size-xs;
  color: var(--p-text-muted-color);
}

.inbox-list__paginator {
  // stylelint-disable-next-line scale-unlimited/declaration-strict-value
  padding: 0;
  background: transparent;
}
</style>
