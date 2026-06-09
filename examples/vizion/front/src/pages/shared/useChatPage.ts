import { computed, ref, type Ref } from 'vue'
import { useChat } from '@/components/chat'
import { useChatActionMarker } from '@/components/chat/composables/useChatActionMarker'
import { useNotifications } from '@/composables/useNotifications'
import { useCompanySelection } from '@/pages/shared/useCompanySelection'
import { useChatsStore } from '@/stores/chats'
import type { ChatListItem } from '@/entities/chat'

type ChatPageCreateMode = 'reset' | 'create'

interface UseChatPageOptions {
  type: ChatPageType
  loadListFailedMessage: string
  errorSummary: string
  createMode?: ChatPageCreateMode
  /**
   * One-shot ref holding a chat id to activate on first `initScope`. Used by
   * cross-tab handoffs (e.g. mini-chat widget's `window.open('/ai-chat?activate=N')`
   * — see `useAiChatPage`). We rely on a ref instead of `history.state`
   * because `vue-router`'s own `replaceState` calls during navigation
   * silently clobber any custom state written before mount. The ref's value
   * is read once, then nulled to prevent replay on remount.
   */
  pendingActivateChatId?: Ref<number | null>
}

/**
 * Full-screen chat pages exist only for `quick_qa` (`/ai-chat`) and historically
 * `report_generation`. `widget_generation` (like report_generation today) lives
 * exclusively in a modal driven by its own isolated composable, never as a
 * standalone page — so it has no entry here. The map is therefore `Partial`.
 */
type ChatPageType = 'quick_qa' | 'report_generation'

const CHAT_COLLECTIONS_BY_TYPE: Record<
  ChatPageType,
  {
    getChats: (_store: ReturnType<typeof useChatsStore>) => ChatListItem[]
    getActiveChatId: (_store: ReturnType<typeof useChatsStore>) => number | null
  }
> = {
  quick_qa: {
    getChats: (store) => store.getQuickQaChats,
    getActiveChatId: (store) => store.getActiveQuickQaChatId,
  },
  report_generation: {
    getChats: (store) => store.getReportGenerationChats,
    getActiveChatId: (store) => store.getActiveReportGenerationChatId,
  },
}

export const useChatPage = (options: UseChatPageOptions) => {
  const chatsStore = useChatsStore()
  const { handleActionMarker } = useChatActionMarker()
  const chat = useChat()
  const { notifyApiError } = useNotifications()
  const chatToDelete = ref<ChatListItem | null>(null)
  const isDeletingChat = ref(false)
  const collection = CHAT_COLLECTIONS_BY_TYPE[options.type]

  const chats = computed(() => collection.getChats(chatsStore))
  const activeChatId = computed(() => collection.getActiveChatId(chatsStore))

  const activeChat = computed(
    () => chats.value.find((item) => item.id === activeChatId.value) ?? null,
  )

  const initScope = async () => {
    try {
      chat.clearScope()
      await chat.fetchChats()

      // Cross-tab handoff via a one-shot ref (e.g. mini-chat widget's
      // `?activate=N` query param parsed in `useAiChatPage`). Takes
      // precedence over the default active-chat restore because tab-to-tab
      // navigation can't share window.history state at all.
      const pendingActivateRef = options.pendingActivateChatId
      const pendingActivateId = pendingActivateRef?.value ?? null
      if (pendingActivateRef && pendingActivateId !== null) {
        pendingActivateRef.value = null
        const candidate = chats.value.find((item) => item.id === pendingActivateId)
        if (candidate && candidate.type === options.type) {
          await chat.loadChat(pendingActivateId, { type: options.type })
          return
        }
      }

      if (activeChat.value) {
        await chat.loadChat(activeChat.value.id, { type: options.type })
      }
    } catch (error) {
      notifyApiError(error, options.loadListFailedMessage, options.errorSummary)
    }
  }

  const clearScope = async () => {
    chatToDelete.value = null
    isDeletingChat.value = false
    chat.clearScope()
  }

  const { guardCompanySelection } = useCompanySelection({
    onEnterCompanyScope: initScope,
    onLeaveCompanyScope: clearScope,
  })

  const init = async () => {
    await guardCompanySelection()
  }

  const handleSend = async (content: string) => {
    if (!chat.currentChat.value) {
      const result = await chat.createAndOpenChat(options.type)
      if (!result.ok) return
    }

    await chat.sendMessage(content)
  }

  const handleSelectChat = (id: number) => {
    if (chat.isSending.value) return
    return chat.loadChat(id, { type: options.type })
  }

  const handleCreateNew = () => {
    if (chat.isSending.value) return

    if (options.createMode === 'create') {
      return chat.createAndOpenChat(options.type)
    }

    chat.resetChat(options.type)
  }

  const requestDeleteChat = (id: number) => {
    if (chat.isSending.value) return
    chatToDelete.value = chats.value.find((item) => item.id === id) ?? null
  }

  const cancelDeleteChat = () => {
    chatToDelete.value = null
  }

  const confirmDeleteChat = async () => {
    const target = chatToDelete.value
    if (!target) return

    isDeletingChat.value = true

    try {
      if (chat.currentChat.value?.id === target.id) {
        chat.resetChat(options.type)
      }

      await chat.deleteChat(target.id)
      chatToDelete.value = null
    } catch (error) {
      notifyApiError(error, options.loadListFailedMessage, options.errorSummary)
    } finally {
      isDeletingChat.value = false
    }
  }

  return {
    chats,
    activeChatId,
    currentChat: chat.currentChat,
    isSending: chat.isSending,
    isLoadingChat: chat.isLoadingChat,
    init,
    handleSelectChat,
    handleCreateNew,
    handleSend,
    handleActionMarker,
    chatToDelete,
    isDeletingChat,
    requestDeleteChat,
    cancelDeleteChat,
    confirmDeleteChat,
  }
}
