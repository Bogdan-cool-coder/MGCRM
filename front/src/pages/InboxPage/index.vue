<template>
  <div class="inbox-page">
    <!-- Header (custom toolbar: icon + title + unread badge + segmented + search + refresh) -->
    <header class="inbox-page__header">
      <span class="inbox-page__icon">
        <i class="pi pi-envelope" />
      </span>
      <div class="inbox-page__title-group">
        <h1 class="inbox-page__title">{{ t('inbox.page.title') }}</h1>
        <Badge
          v-if="inboxUnreadCount > 0"
          :value="inboxUnreadCount > 99 ? '99+' : String(inboxUnreadCount)"
          severity="danger"
        />
      </div>

      <SelectButton
        v-if="!isDraftsFolder"
        :model-value="filters.unreadOnly ? unreadOption : allOption"
        :options="unreadOptions"
        option-label="label"
        :allow-empty="false"
        class="inbox-page__segmented"
        @update:model-value="(v: UnreadOption) => v && setUnreadOnly(v.value)"
      />

      <InboxSearchFilters
        :q="filters.q"
        :folder="filters.folder"
        :channel="filters.channel"
        :has-active-filters="hasActiveFilters"
        :counts="counts"
        :date-range="filters.dateRange"
        @search="onSearchInput"
        @update:folder="setFolder"
        @update:channel="setChannel"
        @update:date-range="setDateRange"
        @reset="resetFilters"
      />

      <Button
        icon="pi pi-refresh"
        :label="t('inbox.page.refresh')"
        severity="secondary"
        text
        class="inbox-page__refresh"
        @click="onRefresh"
      />
    </header>

    <!-- Two-pane body -->
    <div :class="['inbox-page__body', `inbox-page__body--${mobileView}`]">
      <!-- ── Drafts folder: draft list + editor ─────────────────────────────── -->
      <template v-if="isDraftsFolder">
        <section class="inbox-page__pane inbox-page__pane--list">
          <InboxDraftList
            :drafts="drafts"
            :loading="draftsLoading"
            :error="draftsError"
            :selected-draft-id="selectedDraftId"
            @open="openDraft"
            @new="startNewDraft"
            @refresh="fetchMessages"
          />
        </section>

        <section class="inbox-page__pane inbox-page__pane--reading">
          <InboxDraftEditor
            v-if="selectedDraftId !== null || draftDirty"
            :subject="draftForm.subject"
            :body="draftForm.body"
            :related-message-id="draftForm.related_message_id"
            :is-new="selectedDraftId === null"
            :dirty="draftDirty"
            :save-pending="draftSavePending"
            :show-back="true"
            @update:subject="onDraftSubject"
            @update:body="onDraftBody"
            @save="saveDraft"
            @delete="deleteDraft"
            @back="backToList"
          />
          <div v-else class="inbox-page__draft-empty">
            <i class="pi pi-file-edit inbox-page__draft-empty-icon" />
            <p class="inbox-page__draft-empty-title">{{ t('inbox.drafts.pickTitle') }}</p>
            <p class="inbox-page__draft-empty-hint">{{ t('inbox.drafts.pickHint') }}</p>
          </div>
        </section>
      </template>

      <!-- ── Message folders: message list + reading pane ───────────────────── -->
      <template v-else>
        <section class="inbox-page__pane inbox-page__pane--list">
          <InboxList
            :messages="messages"
            :loading="listLoading"
            :error="listError"
            :total-records="totalRecords"
            :per-page="perPage"
            :selected-id="selectedId"
            :cozy="cozy"
            :is-failed-filter="filters.folder === 'failed'"
            :has-active-filters="hasActiveFilters"
            :folder="filters.folder"
            :active-reprocess-id="currentReprocessId"
            @open="openMessage"
            @reprocess="confirmReprocess"
            @star="toggleStar"
            @page="onPageChange"
            @refresh="fetchMessages"
            @reset="resetFilters"
          />
        </section>

        <section class="inbox-page__pane inbox-page__pane--reading">
          <InboxReadingPane
            :selected-id="selectedId"
            :msg="selectedMessage"
            :loading="detailLoading"
            :load-error="detailError"
            :reprocess-pending="reprocessPending"
            :mark-read-pending="markReadPending"
            :can-view-raw-payload="canViewRawPayload"
            :show-back="true"
            @toggle-read="toggleSelectedRead"
            @reprocess="confirmReprocess"
            @star="toggleStar"
            @toggle-important="toggleSelectedImportant"
            @snooze="onSnooze"
            @unsnooze="unsnooze"
            @draft-reply="draftReplyToSelected"
            @back="backToList"
          />
        </section>
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import Badge from 'primevue/badge'
import Button from 'primevue/button'
import SelectButton from 'primevue/selectbutton'
// ConfirmDialog is NOT mounted here — DefaultLayout/index.vue mounts a global
// instance that handles all useConfirm() calls app-wide.
import InboxSearchFilters from './components/InboxSearchFilters.vue'
import InboxList from './components/InboxList.vue'
import InboxReadingPane from './components/InboxReadingPane.vue'
import InboxDraftList from './components/InboxDraftList.vue'
import InboxDraftEditor from './components/InboxDraftEditor.vue'
import { useInboxPage } from './composables/useInboxPage'
import { useDensityStore } from '@/stores/density'

const { t } = useI18n()
const densityStore = useDensityStore()

/** Global density → cozy rows + larger channel dots. */
const cozy = computed(() => densityStore.density === 'cozy')

const {
  messages,
  listLoading,
  listError,
  totalRecords,
  perPage,
  counts,
  filters,
  hasActiveFilters,
  isDraftsFolder,
  onSearchInput,
  resetFilters,
  setFolder,
  setChannel,
  setUnreadOnly,
  setDateRange,
  selectedId,
  selectedMessage,
  mobileView,
  detailLoading,
  detailError,
  openMessage,
  backToList,
  toggleSelectedRead,
  markReadPending,
  toggleStar,
  toggleSelectedImportant,
  snooze,
  unsnooze,
  reprocessPending,
  currentReprocessId,
  confirmReprocess,
  drafts,
  draftsLoading,
  draftsError,
  selectedDraftId,
  draftForm,
  draftDirty,
  draftSavePending,
  openDraft,
  startNewDraft,
  markDraftDirty,
  draftReplyToSelected,
  saveDraft,
  deleteDraft,
  onPageChange,
  fetchMessages,
  canViewRawPayload,
  inboxUnreadCount,
} = useInboxPage()

function onSnooze(payload: { id: number; until: string }) {
  void snooze(payload.id, payload.until)
}

function onDraftSubject(value: string) {
  draftForm.value.subject = value
  markDraftDirty()
}

function onDraftBody(value: string) {
  draftForm.value.body = value
  markDraftDirty()
}

// ─── Segmented Unread / All ─────────────────────────────────────────────────
interface UnreadOption {
  label: string
  value: boolean
}
const unreadOption = computed<UnreadOption>(() => ({ label: t('inbox.filters.unreadOnly'), value: true }))
const allOption = computed<UnreadOption>(() => ({ label: t('inbox.filters.all'), value: false }))
const unreadOptions = computed<UnreadOption[]>(() => [unreadOption.value, allOption.value])

function onRefresh() {
  void fetchMessages()
}
</script>

<style lang="scss" scoped>
.inbox-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
}

// ── Header ────────────────────────────────────────────────────────────────────
.inbox-page__header {
  display: flex;
  align-items: center;
  gap: $space-3;
  padding: $space-3 $space-5;
  background: $surface-card;
  border-bottom: 1px solid $surface-200;
  flex-wrap: wrap;
  flex-shrink: 0;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.inbox-page__icon {
  width: 38px;
  height: 38px;
  flex-shrink: 0;
  border-radius: $radius-md;
  background: $primary-50;
  display: inline-flex;
  align-items: center;
  justify-content: center;

  .pi {
    font-size: $font-size-md;
    color: $primary-color;
  }

  .app-dark & {
    background: var(--p-surface-100);

    .pi {
      color: var(--p-primary-color);
    }
  }
}

.inbox-page__title-group {
  display: flex;
  align-items: center;
  gap: $space-2;
  flex-shrink: 0;
}

.inbox-page__title {
  margin: 0;
  font-size: $font-size-xl;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  line-height: 1.1;
}

.inbox-page__segmented {
  flex-shrink: 0;
}

.inbox-page__refresh {
  flex-shrink: 0;
}

// ── Two-pane body ─────────────────────────────────────────────────────────────
.inbox-page__body {
  flex: 1;
  min-height: 0;
  display: grid;
  grid-template-columns: minmax(360px, 2fr) 3fr;
  gap: $space-4;
  padding: $space-4 $space-5;
}

.inbox-page__pane {
  background: $surface-card;
  border: 1px solid $surface-200;
  border-radius: $radius-lg;
  box-shadow: $shadow-sm;
  overflow: hidden;
  display: flex;
  flex-direction: column;
  min-height: 0;

  .app-dark & {
    border-color: var(--p-surface-200);
  }
}

// ── Drafts: "pick a draft" empty state ──────────────────────────────────────────
.inbox-page__draft-empty {
  height: 100%;
  display: flex;
  flex-direction: column;
  align-items: center;
  justify-content: center;
  gap: $space-3;
  padding: $space-8 $space-5;
  text-align: center;
}

.inbox-page__draft-empty-icon {
  font-size: $font-size-icon-lg;
  color: var(--p-text-muted-color);
  opacity: 0.5;
}

.inbox-page__draft-empty-title {
  margin: 0;
  font-size: $font-size-sm;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
}

.inbox-page__draft-empty-hint {
  margin: 0;
  max-width: 260px;
  font-size: $font-size-sm;
  color: var(--p-text-muted-color);
}

// ── Responsive: fold to a single pane below lg (992px) ─────────────────────────
@media (max-width: 991.98px) {
  .inbox-page__body {
    grid-template-columns: 1fr;
  }

  // Show only the active pane on narrow screens.
  .inbox-page__body--list .inbox-page__pane--reading {
    display: none;
  }

  .inbox-page__body--detail .inbox-page__pane--list {
    display: none;
  }
}

// On wide screens both panes are always visible, so the mobile-only «К списку»
// back button in the reading pane AND the draft editor is inert — hide both
// (the draft editor uses .inbox-draft-editor__back, previously not covered).
@media (min-width: 992px) {
  .inbox-page__pane--reading :deep(.inbox-reading__back),
  .inbox-page__pane--reading :deep(.inbox-draft-editor__back) {
    display: none;
  }
}
</style>
