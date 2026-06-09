import type {
  ChatAiContextDto,
  ChatDetailDto,
  ChatListItemDto,
  ChatMessageDto,
  ChatMessageErrorDto,
  ChatMessageMetadataDto,
  ChatUsageDto,
} from '@/api/types/chats'
import type {
  ChatAiContext,
  ChatDetail,
  ChatListItem,
  ChatMessage,
  ChatMessageError,
  ChatMessageMetadata,
  ChatUsage,
} from './types'
import { mapReportDtoToReport } from '@/entities/report'
import { getLocalizedText } from '@/utils/localization'

const mapChatUsageDtoToUsage = (dto: ChatUsageDto): ChatUsage => ({
  promptTokens: dto.prompt_tokens,
  completionTokens: dto.completion_tokens,
  totalTokens: dto.total_tokens,
})

/**
 * Backend error shape changed with M4 from a plain string to
 * `{exception_class, message}`. Normalize both forms to the object shape.
 */
const mapChatMessageError = (
  raw: ChatMessageErrorDto | string | null | undefined,
): ChatMessageError | null => {
  if (raw == null) return null
  if (typeof raw === 'string') return { message: raw }
  return {
    exceptionClass: raw.exception_class,
    message: raw.message,
  }
}

const mapChatMetadataDtoToMetadata = (
  dto: ChatMessageMetadataDto | null | undefined,
): ChatMessageMetadata | null => {
  if (!dto) return null

  return {
    ...dto,
    finishReason: dto.finish_reason,
    usage: dto.usage ? mapChatUsageDtoToUsage(dto.usage) : null,
    toolCalls: dto.tool_calls ?? null,
    toolResults: dto.tool_results ?? null,
    error: mapChatMessageError(dto.error),
  }
}

const mapChatAiContextDtoToContext = (dto: ChatAiContextDto | null | undefined): ChatAiContext | null => {
  if (!dto) return null

  return {
    ...dto,
    lastToolCalls: dto.last_tool_calls,
    totalSteps: dto.total_steps,
    probedModels: dto.probed_models,
    reportCreated: dto.report_created,
  }
}

export const mapChatMessageDtoToMessage = (dto: ChatMessageDto): ChatMessage => ({
  id: dto.id,
  chatId: dto.chat_id,
  role: dto.role,
  content: dto.content,
  status: dto.status,
  startedAt: dto.started_at ?? null,
  finishedAt: dto.finished_at ?? null,
  eventsCount: dto.events_count,
  metadata: mapChatMetadataDtoToMetadata(dto.metadata),
  createdAt: dto.created_at,
  isOptimistic: false,
})

export const mapChatListItemDtoToItem = (dto: ChatListItemDto): ChatListItem => ({
  id: dto.id,
  type: dto.type,
  scopeType: dto.scope_type,
  title: dto.title,
  reportId: dto.report_id,
  widgetId: dto.widget_id ?? null,
  dashboardId: dto.dashboard_id ?? null,
  documentId: dto.document_id ?? null,
  updatedAt: dto.updated_at,
  lastMessageAt: dto.last_message_at,
  userMessageCount: dto.user_message_count,
  isActiveWindow: dto.is_active_window,
  lastMessage: dto.last_message
    ? { role: dto.last_message.role, content: dto.last_message.content }
    : null,
})

export const mapChatDetailDtoToDetail = (dto: ChatDetailDto): ChatDetail => ({
  id: dto.id,
  type: dto.type,
  scopeType: dto.scope_type,
  title: dto.title,
  reportId: dto.report_id,
  widgetId: dto.widget_id ?? null,
  dashboardId: dto.dashboard_id ?? null,
  documentId: dto.document_id ?? null,
  updatedAt: dto.updated_at,
  // Mini-chat aggregates are optional on the wire — pass them through unchanged so
  // consumers see `undefined` on legacy responses and a concrete value on /resume + inline-create.
  lastMessageAt: dto.last_message_at ?? null,
  userMessageCount: dto.user_message_count,
  isActiveWindow: dto.is_active_window,
  aiContext: mapChatAiContextDtoToContext(dto.ai_context),
  messages: (dto.messages ?? []).map(mapChatMessageDtoToMessage),
  report: dto.report
    ? {
        ...mapReportDtoToReport(dto.report),
        title: getLocalizedText(dto.report.title),
        description: dto.report.description ? getLocalizedText(dto.report.description) : undefined,
      }
    : null,
})
