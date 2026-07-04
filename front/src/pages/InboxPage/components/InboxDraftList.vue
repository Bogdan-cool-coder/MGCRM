<template>
  <div class="inbox-draft-list">
    <!-- New-draft action -->
    <div class="inbox-draft-list__actions">
      <Button
        icon="pi pi-plus"
        :label="t('inbox.drafts.new')"
        severity="secondary"
        outlined
        size="small"
        @click="emit('new')"
      />
    </div>

    <div class="inbox-draft-list__scroll">
      <!-- Loading -->
      <template v-if="loading">
        <div v-for="n in 6" :key="n" class="inbox-draft-list__skeleton-row">
          <Skeleton shape="circle" size="34px" />
          <div class="inbox-draft-list__skeleton-lines">
            <Skeleton height="16px" width="60%" class="mb-1" />
            <Skeleton height="14px" width="85%" />
          </div>
        </div>
      </template>

      <!-- Error -->
      <div v-else-if="error" class="inbox-draft-list__state">
        <Message severity="error" :closable="false">
          {{ t('inbox.drafts.loadError') }}
          <Button
            :label="t('inbox.error.retry')"
            text
            size="small"
            class="ms-2"
            @click="emit('refresh')"
          />
        </Message>
      </div>

      <!-- Empty -->
      <div v-else-if="drafts.length === 0" class="inbox-draft-list__state inbox-draft-list__state--empty">
        <i class="pi pi-file-edit inbox-draft-list__empty-icon" />
        <p class="inbox-draft-list__empty-title">{{ t('inbox.drafts.emptyTitle') }}</p>
        <p class="inbox-draft-list__empty-body">{{ t('inbox.drafts.emptyBody') }}</p>
      </div>

      <!-- Draft rows -->
      <template v-else>
        <InboxDraftRow
          v-for="draft in drafts"
          :key="draft.id"
          :draft="draft"
          :selected="draft.id === selectedDraftId"
          @open="emit('open', $event)"
        />
      </template>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import Button from 'primevue/button'
import Message from 'primevue/message'
import Skeleton from 'primevue/skeleton'
import InboxDraftRow from './InboxDraftRow.vue'
import type { InboxDraft } from '@/api/inbox'

defineProps<{
  drafts: InboxDraft[]
  loading: boolean
  error: unknown
  selectedDraftId: number | null
}>()

const emit = defineEmits<{
  open: [draft: InboxDraft]
  new: []
  refresh: []
}>()

const { t } = useI18n()
</script>

<style lang="scss" scoped>
.inbox-draft-list {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
}

.inbox-draft-list__actions {
  display: flex;
  justify-content: flex-end;
  padding: $space-2 $space-4;
  border-bottom: 1px solid $surface-200;
  flex-shrink: 0;

  .app-dark & {
    border-bottom-color: var(--p-surface-200);
  }
}

.inbox-draft-list__scroll {
  flex: 1;
  overflow-y: auto;
  min-height: 0;
  // Thin themed scrollbar so long draft lists show scroll position (was hidden).
  scrollbar-width: thin;
  scrollbar-color: $surface-300 transparent;

  &::-webkit-scrollbar {
    width: 8px;
  }

  &::-webkit-scrollbar-thumb {
    background: $surface-300;
    border-radius: $radius-sm;

    .app-dark & {
      background: var(--p-surface-300);
    }
  }
}

.inbox-draft-list__skeleton-row {
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

.inbox-draft-list__skeleton-lines {
  flex: 1;
  min-width: 0;
}

.inbox-draft-list__state {
  padding: $space-8 $space-4;

  &--empty {
    display: flex;
    flex-direction: column;
    align-items: center;
    text-align: center;
  }
}

.inbox-draft-list__empty-icon {
  font-size: $font-size-icon-lg;
  color: var(--p-text-muted-color);
  opacity: 0.4;
}

.inbox-draft-list__empty-title {
  margin-top: $space-3;
  margin-bottom: $space-1;
  font-weight: $font-weight-semibold;
  color: var(--p-text-color);
  font-size: $font-size-sm;
}

.inbox-draft-list__empty-body {
  color: var(--p-text-muted-color);
  font-size: $font-size-sm;
  margin: 0;
}
</style>
