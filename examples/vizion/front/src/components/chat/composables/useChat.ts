import { ref } from 'vue'
import { useLocalI18n } from '@/composables/useLocalI18n'
import type { ChatDetail } from '@/entities/chat'
import { useChatQueries } from './useChatQueries'
import { useChatMessaging } from './useChatMessaging'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export const useChat = () => {
  const { t } = useLocalI18n({ en, ru })
  const currentChat = ref<ChatDetail | null>(null)
  const chatMessaging = useChatMessaging({ currentChat, t })
  const chatQueries = useChatQueries({
    currentChat,
    t,
    onChatLoaded: chatMessaging.resumeActiveStream,
  })

  const clearScope = (): void => {
    chatQueries.clearQueryScope()
    chatMessaging.clearMessagingScope()
    currentChat.value = null
  }

  return {
    currentChat,
    isSending: chatMessaging.isSending,
    isLoadingChat: chatQueries.isLoadingChat,
    fetchChats: chatQueries.fetchChats,
    loadChat: chatQueries.loadChat,
    createAndOpenChat: chatQueries.createAndOpenChat,
    deleteChat: chatQueries.deleteChat,
    sendMessage: chatMessaging.sendMessage,
    resetChat: chatQueries.resetChat,
    clearScope,
  }
}
