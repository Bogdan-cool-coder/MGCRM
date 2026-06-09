import type { Ref } from 'vue'
import { useChatsStore } from '@/stores/chats'
import { useServices } from '@/services'
import { useNotifications } from '@/composables/useNotifications'
import { useAsyncResource } from '@/composables/async/useAsyncResource'
import type { ChatDetail, ChatType } from '@/entities/chat'
import { toChatListItem } from './chatHelpers'

interface UseChatQueriesOptions {
  currentChat: Ref<ChatDetail | null>
  t: (_key: string) => string
  /**
   * Called after a chat is loaded and committed to `currentChat`. Used by the
   * messaging layer to reconnect to an in-flight assistant stream when the
   * chat is opened with a `pending` / `running` assistant message.
   */
  onChatLoaded?: (_chatId: number) => void
}

export const useChatQueries = (options: UseChatQueriesOptions) => {
  const chatsStore = useChatsStore()
  const { chatService } = useServices()
  const { notifyApiError } = useNotifications()

  const chatLoadResource = useAsyncResource<ChatDetail | null>(null)
  const chatListLoadResource = useAsyncResource<void>(undefined)
  const isLoadingChat = chatLoadResource.loading

  const loadChat = async (
    id: number,
    loadOptions?: { preserveOnError?: boolean; type?: ChatType },
  ): Promise<boolean> => {
    const preserveOnError = loadOptions?.preserveOnError ?? true

    try {
      const chat = await chatLoadResource.run(async () => await chatService.fetchChat(id), {
        commit: (nextChat) => {
          if (!nextChat) return
          options.currentChat.value = nextChat
          chatsStore.setActive(loadOptions?.type ?? nextChat.type, id)
          options.onChatLoaded?.(nextChat.id)
        },
      })
      return Boolean(chat)
    } catch (error) {
      notifyApiError(error, options.t('errors.loadChatFailed'), options.t('common.error'))
      if (!preserveOnError) {
        const chatType = loadOptions?.type ?? options.currentChat.value?.type
        options.currentChat.value = null
        if (chatType) {
          chatsStore.clearActive(chatType)
        }
      }
      return false
    }
  }

  /**
   * Creates a new chat and opens it, surfacing it as the page's
   * `currentChat.value`.
   *
   * On success returns `{ ok: true; chatId }`, on failure `{ ok: false }`
   * — the previous boolean signature is preserved at call sites that only
   * care about `.ok`.
   */
  const createAndOpenChat = async (
    type: ChatType,
  ): Promise<{ ok: true; chatId: number } | { ok: false }> => {
    try {
      const chat = await chatService.createChat(type)
      chatsStore.prependChat(toChatListItem(chat))
      options.currentChat.value = chat
      chatsStore.setActive(type, chat.id)
      return { ok: true, chatId: chat.id }
    } catch (error) {
      notifyApiError(error, options.t('errors.createChatFailed'), options.t('common.error'))
      return { ok: false }
    }
  }

  const fetchChats = async (): Promise<void> => {
    await chatListLoadResource.run(async () => {
      chatsStore.setChats(await chatService.fetchChats())
    })
  }

  const deleteChat = async (id: number): Promise<void> => {
    await chatService.deleteChat(id)
    chatsStore.removeChat(id)

    if (options.currentChat.value?.id === id) {
      options.currentChat.value = null
    }
  }

  const resetChat = (type?: ChatType): void => {
    options.currentChat.value = null
    if (type) {
      chatsStore.clearActive(type)
    }
  }

  const clearQueryScope = (): void => {
    chatLoadResource.invalidate()
    chatListLoadResource.invalidate()
    chatLoadResource.reset(null)
    chatsStore.clear()
  }

  return {
    isLoadingChat,
    loadChat,
    createAndOpenChat,
    fetchChats,
    deleteChat,
    resetChat,
    clearQueryScope,
  }
}
