import { useLocalI18n } from '@/composables/useLocalI18n'
import { useActivateChatIdQueryParam } from '@/pages/shared/useActivateChatIdQueryParam'
import { useChatPage } from '@/pages/shared/useChatPage'
import en from '../locale/en.json'
import ru from '../locale/ru.json'

export const useAiChatPage = () => {
  const { t } = useLocalI18n({ en, ru })
  // Cross-tab handoff from mini-chat widget's expand button — when the user
  // expands a `quick_qa` chat. `useChatPage.initScope` guards the activation
  // against scope mismatch (a `report_generation` chat id landing here is
  // silently ignored).
  const pendingActivateChatId = useActivateChatIdQueryParam()
  const page = useChatPage({
    type: 'quick_qa',
    loadListFailedMessage: t('errors.loadListFailed'),
    errorSummary: t('common.error'),
    createMode: 'reset',
    pendingActivateChatId,
  })

  return {
    quickQaChats: page.chats,
    activeChatId: page.activeChatId,
    currentChat: page.currentChat,
    isSending: page.isSending,
    isLoadingChat: page.isLoadingChat,
    init: page.init,
    handleSelectChat: page.handleSelectChat,
    handleSend: page.handleSend,
    handleNewChat: page.handleCreateNew,
    handleActionMarker: page.handleActionMarker,
    chatToDelete: page.chatToDelete,
    isDeletingChat: page.isDeletingChat,
    requestDeleteChat: page.requestDeleteChat,
    cancelDeleteChat: page.cancelDeleteChat,
    confirmDeleteChat: page.confirmDeleteChat,
  }
}
