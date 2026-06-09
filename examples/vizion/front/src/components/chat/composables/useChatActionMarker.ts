import { useReportGenerationModalStore } from '@/stores/reportGenerationModal'
import { useWidgetGenerationModalStore } from '@/stores/widgetGenerationModal'
import { useDocumentGenerationModalStore } from '@/stores/documentGenerationModal'
import { canUseDocuments } from '@/shared/auth/capabilities'
import type { ChatActionMarker } from '@/utils/markdown'

/**
 * Shared handler for the action-marker CTA emitted by an assistant bubble.
 *
 * The quick_qa AI can suggest two redirects (per backend contract,
 * `chats_frontend.md` §Action-маркеры): a fenced ```json``` block inside the
 * message body with `{ action, prompt, label }`. `extractActionMarker`
 * (`@/utils/markdown`) parses it; the bubble hides the raw block and shows a
 * CTA button. This composable maps the parsed marker to the right modal:
 *
 *  - `redirect_to_report_generation` → opens the report-generation modal;
 *  - `redirect_to_widget_generation` → opens the widget-generation modal;
 *  - `redirect_to_document_generation` → opens the document-generation modal.
 *
 * (Note: the backend does not emit `redirect_to_document_generation` yet — M7
 * left it for later — so this branch is dormant until the AI starts producing
 * the marker. Wiring it now keeps the handler symmetric and future-ready.)
 *
 * All open in create-mode with the rich prompt pre-filled into the modal's
 * input (NOT auto-sent — the user reviews / tweaks before submitting). The
 * chat is created lazily on the first send inside the modal.
 *
 * Used by both the full-screen chat page (`useChatPage`) and the mini-chat
 * widget (`MiniChatWidget`), so the behaviour is identical everywhere and the
 * routing logic is not duplicated. Note: this is deliberately NOT gated on
 * `chat.type` — the marker is recognised purely from its parsed payload, so it
 * works even when the mini-chat's inline-create snapshot has `type === undefined`.
 *
 * `onComplete()` always runs so the CTA button leaves its loading state.
 */
export const useChatActionMarker = () => {
  const reportModalStore = useReportGenerationModalStore()
  const widgetModalStore = useWidgetGenerationModalStore()
  const documentModalStore = useDocumentGenerationModalStore()

  const handleActionMarker = ({
    marker,
    onComplete,
  }: {
    marker: ChatActionMarker
    onComplete: () => void
  }): void => {
    try {
      if (marker.action === 'redirect_to_widget_generation') {
        widgetModalStore.open({ mode: 'create', prefillPrompt: marker.prompt })
      } else if (marker.action === 'redirect_to_document_generation') {
        // Document generation is gated behind the Documents feature flag. When
        // it is OFF the marker is a no-op (the CTA simply settles) so a chat can
        // never fall into the document flow even if the AI emits the marker.
        if (canUseDocuments()) {
          documentModalStore.open({ prefillPrompt: marker.prompt })
        }
      } else {
        reportModalStore.open({ mode: 'create', prefillPrompt: marker.prompt })
      }
    } finally {
      onComplete()
    }
  }

  return { handleActionMarker }
}
