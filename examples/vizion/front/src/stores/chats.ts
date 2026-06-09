import { defineStore } from 'pinia'
import type { ChatDetail, ChatListItem, ChatType } from '@/entities/chat'

export const useChatsStore = defineStore('chats', {
  state: () => ({
    chats: [] as ChatListItem[],
    activeReportGenerationChatId: null as number | null,
    activeQuickQaChatId: null as number | null,
  }),

  getters: {
    getChats(): ChatListItem[] {
      return this.chats
    },
    getActiveReportGenerationChatId(): number | null {
      return this.activeReportGenerationChatId
    },
    getActiveQuickQaChatId(): number | null {
      return this.activeQuickQaChatId
    },
    getActiveChatId(): number | null {
      return this.activeQuickQaChatId ?? this.activeReportGenerationChatId
    },
    getReportGenerationChats(): ChatListItem[] {
      return this.chats.filter((c) => c.type === 'report_generation')
    },
    getQuickQaChats(): ChatListItem[] {
      return this.chats.filter((c) => c.type === 'quick_qa')
    },
  },

  actions: {
    setChats(chats: ChatListItem[]): void {
      this.chats = chats
      this.reconcileActiveChats()
    },

    reconcileActiveChats(): void {
      const hasReportGenerationActiveChat = this.chats.some(
        (chat) =>
          chat.type === 'report_generation' &&
          chat.id === this.activeReportGenerationChatId,
      )
      if (!hasReportGenerationActiveChat) {
        this.activeReportGenerationChatId = null
      }

      const hasQuickQaActiveChat = this.chats.some(
        (chat) => chat.type === 'quick_qa' && chat.id === this.activeQuickQaChatId,
      )
      if (!hasQuickQaActiveChat) {
        this.activeQuickQaChatId = null
      }
    },

    prependChat(chat: ChatListItem): void {
      this.chats = [chat, ...this.chats.filter((item) => item.id !== chat.id)]
    },

    setActive(type: ChatType, id: number | null): void {
      if (type === 'report_generation') {
        this.activeReportGenerationChatId = id
        return
      }

      this.activeQuickQaChatId = id
    },

    clearActive(type: ChatType): void {
      this.setActive(type, null)
    },

    removeChat(id: number): void {
      this.chats = this.chats.filter((chat) => chat.id !== id)
      this.reconcileActiveChats()
    },

    syncChatListItemFromDetail(chat: ChatDetail): void {
      const lastMessage =
        chat.messages.length > 0 ? chat.messages[chat.messages.length - 1] ?? null : null

      this.prependChat({
        id: chat.id,
        type: chat.type,
        title: chat.title,
        reportId: chat.reportId,
        updatedAt: chat.updatedAt,
        lastMessage: lastMessage
          ? { role: lastMessage.role, content: lastMessage.content }
          : null,
      })
      this.setActive(chat.type, chat.id)
    },

    clear(): void {
      this.chats = []
      this.activeReportGenerationChatId = null
      this.activeQuickQaChatId = null
    },
  },
})
