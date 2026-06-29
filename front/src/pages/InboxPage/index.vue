<template>
  <div class="inbox-page">
    <!-- Page header -->
    <PageHeader :title="t('inbox.page.title')">
      <template #actions>
        <Badge
          v-if="inboxUnreadCount > 0"
          :value="inboxUnreadCount > 99 ? '99+' : String(inboxUnreadCount)"
          severity="danger"
          class="inbox-page__unread-badge"
        />
        <Button
          icon="pi pi-refresh"
          :label="t('inbox.page.refresh')"
          severity="secondary"
          text
          @click="onRefresh"
        />
      </template>
    </PageHeader>

    <div class="inbox-page__body">
      <!-- Filter bar -->
      <InboxFilterBar
        :filters="filters"
        :has-active-filters="hasActiveFilters"
        @update:unread-only="filters.unreadOnly = $event; currentPage = 1"
        @update:channel="filters.channel = $event; currentPage = 1"
        @update:routing-status="filters.routingStatus = $event; currentPage = 1"
        @update:date-range="filters.dateRange = $event; currentPage = 1"
        @search="onSearchInput"
        @toggle-failed="toggleFailedQuick"
        @reset="resetFilters"
      />

      <!-- Inbox list -->
      <InboxList
        :messages="messages"
        :loading="listLoading"
        :error="listError"
        :total-records="totalRecords"
        :per-page="perPage"
        :is-failed-filter="filters.failedQuick"
        @open="openDetail"
        @reprocess="onRowReprocess"
        @page="onPageChange"
        @refresh="fetchMessages"
      />
    </div>

    <!-- Detail dialog -->
    <InboxDetailDialog
      v-model="detailVisible"
      :msg="selectedMessage"
      :loading="detailLoading"
      :load-error="detailError"
      :reprocess-pending="reprocessMutation.isPending.value"
      :mark-read-pending="markReadPending"
      :can-view-raw-payload="canViewRawPayload"
      @close="closeDetail"
      @toggle-read="onToggleRead"
      @reprocess="onDialogReprocess"
    />

  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PageHeader from '@/components/AppShell/PageHeader.vue'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
// ConfirmDialog is NOT mounted here — DefaultLayout/index.vue mounts a global
// instance that handles all useConfirm() calls app-wide. A second local instance
// causes confirm.require() to render TWO dialogs simultaneously.
import InboxFilterBar from './components/InboxFilterBar.vue'
import InboxList from './components/InboxList.vue'
import InboxDetailDialog from './components/InboxDetailDialog.vue'
import { useInboxPage } from './composables/useInboxPage'

const { t } = useI18n()

const {
  messages,
  listLoading,
  listError,
  totalRecords,
  currentPage,
  perPage,
  filters,
  hasActiveFilters,
  onSearchInput,
  resetFilters,
  toggleFailedQuick,
  selectedMessage,
  detailVisible,
  detailLoading,
  detailError,
  openDetail,
  closeDetail,
  markRead,
  markUnread,
  markReadPending,
  reprocessMutation,
  confirmReprocess,
  reprocess,
  onPageChange,
  fetchMessages,
  canViewRawPayload,
  inboxUnreadCount,
} = useInboxPage()

function onRefresh() {
  void fetchMessages()
}

function onToggleRead() {
  if (!selectedMessage.value) return
  if (selectedMessage.value.read_at) {
    void markUnread(selectedMessage.value.id)
  } else {
    void markRead(selectedMessage.value.id)
  }
}

function onRowReprocess(id: number) {
  confirmReprocess(id, () => {
    void reprocess(id)
  })
}

function onDialogReprocess(id: number) {
  confirmReprocess(id, () => {
    void reprocess(id)
  })
}
</script>

<style lang="scss" scoped>
.inbox-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

.inbox-page__body {
  flex: 1;
  overflow-y: auto;
  padding: $space-4;
}

.inbox-page__unread-badge {
  // Inline next to the title — positioned by PageHeader subtitle slot
  vertical-align: middle;
  margin-left: $space-2;
}
</style>
