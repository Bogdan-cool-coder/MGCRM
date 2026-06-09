<template>
  <ChatPageShell
    :chats="quickQaChats"
    :active-chat-id="activeChatId"
    :current-chat="currentChat"
    :is-sending="isSending"
    :is-loading-chat="isLoadingChat"
    :create-label="t('newChat')"
    :show-chats-label="t('showChats')"
    :hide-chats-label="t('hideChats')"
    :empty-message="t('emptyChat')"
    empty-icon="pi pi-comments"
    :placeholder="t('placeholder')"
    :chat-to-delete="chatToDelete"
    :is-deleting-chat="isDeletingChat"
    enable-action-marker
    @select="handleSelectChat"
    @create="handleNewChat"
    @delete="requestDeleteChat"
    @submit="handleSend"
    @cancel-delete="cancelDeleteChat"
    @confirm-delete="confirmDeleteChat"
    @action="handleActionMarker"
  />
</template>

<script setup lang="ts">
import { useAiChatPage } from './composables/useAiChatPage'
import { useLocalI18n } from '@/composables/useLocalI18n'
import { ChatPageShell } from '@/components/chat'
import en from './locale/en.json'
import ru from './locale/ru.json'

const { t } = useLocalI18n({ en, ru })

const {
  quickQaChats,
  activeChatId,
  currentChat,
  isSending,
  isLoadingChat,
  handleSelectChat,
  handleSend,
  handleNewChat,
  handleActionMarker,
  chatToDelete,
  isDeletingChat,
  requestDeleteChat,
  cancelDeleteChat,
  confirmDeleteChat,
} = useAiChatPage()
</script>
