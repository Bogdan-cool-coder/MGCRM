<template>
  <div class="chat-page">
    <div class="chat-page__card">
      <div class="chat-page__toolbar">
        <Button
          class="chat-page__sidebar-toggle"
          :label="isSidebarOpen ? hideChatsLabel : showChatsLabel"
          :icon="isSidebarOpen ? 'pi pi-times' : 'pi pi-bars'"
          severity="secondary"
          text
          @click="toggleSidebar"
        />
      </div>

      <button
        v-if="isMobileSidebar && isSidebarOpen"
        type="button"
        class="chat-page__backdrop"
        :aria-label="hideChatsLabel"
        @click="closeSidebar"
      />

      <aside
        :class="[
          'chat-page__sidebar',
          {
            'chat-page__sidebar--mobile': isMobileSidebar,
            'chat-page__sidebar--open': isSidebarOpen,
          },
        ]"
      >
        <ChatSidebarList
          :chats="chats"
          :active-chat-id="activeChatId"
          :create-label="createLabel"
          :disabled="isSending"
          @select="handleSidebarSelect"
          @create="handleSidebarCreate"
          @delete="emit('delete', $event)"
        />
      </aside>

      <div class="chat-page__main">
        <ChatReportBanner
          v-if="currentChat?.reportId"
          :report-id="currentChat.reportId"
          :report-title="currentChat.report?.title"
        />

        <div class="chat-page__content">
          <LoadingState v-if="isLoadingChat" />
          <EmptyState
            v-else-if="!currentChat"
            :message="emptyMessage"
            :icon="emptyIcon"
          />
          <ChatMessageList
            v-else
            :messages="currentChat.messages"
            :is-sending="isSending"
            :enable-action-marker="enableActionMarker"
            @action="emit('action', $event)"
          />
        </div>

        <ChatInput
          :disabled="isSending"
          :placeholder="placeholder"
          @submit="emit('submit', $event)"
        />
      </div>
    </div>

    <DeleteConfirmModal
      v-model:visible="isDeleteModalVisible"
      :item-name="chatToDelete?.title || undefined"
      :loading="isDeletingChat"
      @cancel="emit('cancelDelete')"
      @confirm="emit('confirmDelete')"
    />
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import Button from 'primevue/button'
import type { ChatDetail, ChatListItem } from '@/entities/chat'
import type { ChatActionMarker } from '@/utils/markdown'
import DeleteConfirmModal from '@/components/modals/DeleteConfirmModal.vue'
import EmptyState from '@/components/states/EmptyState.vue'
import LoadingState from '@/components/states/LoadingState.vue'
import ChatInput from './ChatInput.vue'
import ChatMessageList from './ChatMessageList.vue'
import ChatReportBanner from './ChatReportBanner.vue'
import ChatSidebarList from './ChatSidebarList.vue'
import { useResponsiveChatSidebar } from './composables/useResponsiveChatSidebar'

interface Props {
  chats: ChatListItem[]
  activeChatId: number | null
  currentChat: ChatDetail | null
  isSending: boolean
  isLoadingChat: boolean
  createLabel: string
  showChatsLabel: string
  hideChatsLabel: string
  emptyMessage: string
  emptyIcon: string
  placeholder: string
  chatToDelete: ChatListItem | null
  isDeletingChat: boolean
  enableActionMarker?: boolean
}

const props = withDefaults(defineProps<Props>(), {
  enableActionMarker: false,
})

const emit = defineEmits<{
  select: [id: number]
  create: []
  delete: [id: number]
  submit: [content: string]
  cancelDelete: []
  confirmDelete: []
  action: [payload: { marker: ChatActionMarker; onComplete: () => void }]
}>()

const {
  isMobileSidebar,
  isSidebarOpen,
  closeSidebar,
  toggleSidebar,
} = useResponsiveChatSidebar()

const handleSidebarSelect = async (id: number) => {
  closeSidebar()
  emit('select', id)
}

const handleSidebarCreate = () => {
  closeSidebar()
  emit('create')
}

const isDeleteModalVisible = computed({
  get: () => Boolean(props.chatToDelete),
  set: (value: boolean) => {
    if (!value) {
      emit('cancelDelete')
    }
  },
})
</script>

<style lang="scss" scoped>
.chat-page {
  display: flex;
  flex-direction: column;
  height: 100%;
  min-height: 0;
  padding: 0.75rem;

  &__card {
    position: relative;
    display: flex;
    flex: 1;
    min-height: 0;
    background: $surface-0;
    border-radius: $card-border-radius;
    box-shadow: $shadow-md;
    overflow: hidden;
  }

  &__backdrop {
    display: none;
  }

  &__sidebar {
    width: 220px;
    flex-shrink: 0;
    border-right: 1px solid $surface-200;
    overflow-y: auto;
    display: flex;
    flex-direction: column;
  }

  &__toolbar {
    display: none;
  }

  &__main {
    flex: 1;
    display: flex;
    flex-direction: column;
    min-width: 0;
    min-height: 0;
    overflow: hidden;
  }

  &__content {
    flex: 1;
    min-height: 0;
    display: flex;
    flex-direction: column;
    overflow: hidden;

    :deep(.empty-state) {
      flex: 1;
      border: none;
      background: transparent;
      border-radius: 0;
    }

    :deep(.loading-state) {
      flex: 1;
    }
  }
}

@media (max-width: 960px) {
  .chat-page {
    padding: 0.5rem;

    &__backdrop {
      display: block;
      position: absolute;
      inset: 0;
      z-index: 1;
      border: none;
      background: rgba(15, 23, 42, 0.32);
      padding: 0;
      cursor: pointer;
    }

    &__toolbar {
      display: flex;
      position: absolute;
      top: 0.5rem;
      left: 0.75rem;
      z-index: 3;
      justify-content: flex-start;
      flex-shrink: 0;
    }

    &__sidebar {
      position: absolute;
      top: 0;
      left: 0;
      bottom: 0;
      width: min(18rem, calc(100% - 2.5rem));
      max-width: 100%;
      background: $surface-0;
      z-index: 2;
      border-right: 1px solid $surface-200;
      box-shadow: $shadow-lg;
      padding-top: 3.5rem;
      transform: translateX(-100%);
      transition: transform $transition-fast;

      &--open {
        transform: translateX(0);
      }
    }

    &__sidebar-toggle {
      align-self: flex-start;
      background: rgba($surface-0, 0.94);
      backdrop-filter: blur(8px);
      border-radius: 999px;
      box-shadow: $shadow-md;
    }
  }
}
</style>
