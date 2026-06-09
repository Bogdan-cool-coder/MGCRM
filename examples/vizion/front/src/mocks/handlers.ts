import { http, HttpResponse, delay } from 'msw'
import type { MockResolverArgs } from 'msw'
import type {
  ChatAiContextDto,
  ChatDetailDto,
  ChatLastMessageDto,
  ChatListItemDto,
  ChatMessageMetadataDto,
  ChatMessageDto,
  ChatType,
} from '@/api/types/chats'
import {
  buildMockReportListResponse,
  buildMockReportResponse,
  canMockUserAccessChat,
  canMockUserCreateChats,
  canMockUserViewReport,
  filterMockReportsForCurrentUser,
  MOCK_REPORTS_WITH_DELAYED_FILTERS,
  mockChats,
  mockChatDetails,
  mockCurrentUser,
  mockReports,
  MOCK_DELAY,
  MOCK_AI_ERROR,
  MOCK_AI_RESPONSE_DELAY,
  getFallbackChatType,
  getMockReportById,
} from './data'

let chatIdCounter = Math.max(...mockChats.map((c) => c.id)) + 1
let messageIdCounter = 100
const reportDetailsRequestCount = new Map<number, number>()

const createChatMessage = (
  chatId: number,
  role: ChatMessageDto['role'],
  content: string,
  createdAt: string,
  metadata: ChatMessageMetadataDto | null = null,
): ChatMessageDto => ({
  id: messageIdCounter++,
  chat_id: chatId,
  user_id: 1,
  company_id: 1,
  role,
  content,
  metadata,
  created_at: createdAt,
  updated_at: createdAt,
})

const syncChatListItemFromDetail = (
  chatDetail: ChatDetailDto,
  lastMessage: ChatLastMessageDto | null,
): void => {
  const idx = mockChats.findIndex((chat) => chat.id === chatDetail.id)
  // Mini-chat aggregates in the mock world: keep the previous values when we just
  // refresh an existing list entry; for brand-new entries fall back to neutral defaults
  // that mirror what a freshly-created chat looks like on the backend.
  const prev = idx === -1 ? null : mockChats[idx]
  const chatListItem: ChatListItemDto = {
    id: chatDetail.id,
    type: chatDetail.type,
    scope_type: chatDetail.scope_type,
    title: chatDetail.title,
    report_id: chatDetail.report_id,
    created_at: chatDetail.created_at,
    updated_at: chatDetail.updated_at,
    last_message_at: lastMessage?.created_at ?? prev?.last_message_at ?? null,
    user_message_count:
      (prev?.user_message_count ?? 0) + (lastMessage?.role === 'user' ? 1 : 0),
    is_active_window: true,
    last_message: lastMessage,
  }

  if (idx === -1) {
    mockChats.unshift(chatListItem)
    return
  }

  mockChats.splice(idx, 1)
  mockChats.unshift(chatListItem)
}

const createMockChatDetail = (
  id: number,
  type: ChatType,
  createdAt: string,
  title: string | null = null,
): ChatDetailDto => ({
  id,
  user_id: 1,
  company_id: 1,
  type,
  // Mock scope mapping mirrors the real backend backfill: report_id IS NOT NULL →
  // 'report', else 'general'. This factory always creates report_id=null chats, so
  // the scope is always 'general' regardless of the AI `type`.
  scope_type: 'general',
  title,
  report_id: null,
  ai_context: null,
  report: null,
  created_at: createdAt,
  updated_at: createdAt,
  messages: [],
})

export const handlers = [
  // GET /api/reports — список отчетов
  http.get('/api/reports', async ({ request }: MockResolverArgs) => {
    await delay(MOCK_DELAY)

    const url = new URL(request.url)
    const companyId = url.searchParams.get('company_id')
    const availableReports = filterMockReportsForCurrentUser(mockReports)

    if (!companyId) {
      return HttpResponse.json(availableReports.map(buildMockReportListResponse))
    }

    return HttpResponse.json(
      availableReports
        .filter((report) => report.company_id === Number(companyId))
        .map(buildMockReportListResponse),
    )
  }),

  // GET /api/reports/:id — детальный отчет
  http.get('/api/reports/:id', async ({ params, request }: MockResolverArgs) => {
    await delay(MOCK_DELAY)

    const reportId = Number(params.id)
    const report = getMockReportById(reportId)

    if (!report) {
      return HttpResponse.json({ message: 'Not found' }, { status: 404 })
    }

    if (!canMockUserViewReport(report)) {
      return HttpResponse.json({ message: 'Forbidden' }, { status: 403 })
    }

    const url = new URL(request.url)
    const page = Number(url.searchParams.get('page') ?? '1')
    const perPage = Number(url.searchParams.get('per_page') ?? report.meta?.per_page ?? '50')
    const sortField = url.searchParams.get('sort[field]')
    const sortDirection = url.searchParams.get('sort[direction]')
    const currentRequestCount = reportDetailsRequestCount.get(reportId) ?? 0
    reportDetailsRequestCount.set(reportId, currentRequestCount + 1)

    const includeFilters =
      !MOCK_REPORTS_WITH_DELAYED_FILTERS.has(reportId) || currentRequestCount > 0

    return HttpResponse.json(
      buildMockReportResponse(report, {
        page: Number.isNaN(page) ? 1 : page,
        perPage: Number.isNaN(perPage) ? 50 : perPage,
        sort:
          sortField && (sortDirection === 'asc' || sortDirection === 'desc')
            ? { field: sortField, direction: sortDirection }
            : null,
        includeFilters,
      }),
    )
  }),

  // GET /api/chats — список чатов
  http.get('/api/chats', async () => {
    await delay(MOCK_DELAY)

    return HttpResponse.json(
      mockChats.filter((chat) => {
        const detail = mockChatDetails[chat.id]
        return detail ? canMockUserAccessChat(detail) : false
      }),
    )
  }),

  // POST /api/chats — создать чат
  http.post('/api/chats', async ({ request }: MockResolverArgs) => {
    await delay(MOCK_DELAY)

    if (!canMockUserCreateChats()) {
      return HttpResponse.json({ message: 'Forbidden' }, { status: 403 })
    }

    const body = (await request.json()) as { type?: string }
    const id = chatIdCounter++
    const now = new Date().toISOString()
    const chat = createMockChatDetail(
      id,
      (body.type as ChatType | undefined) ?? 'report_generation',
      now,
    )
    chat.user_id = mockCurrentUser.id
    chat.company_id = mockCurrentUser.company_id

    mockChatDetails[id] = chat
    mockChats.unshift({
      id,
      type: chat.type,
      scope_type: chat.scope_type,
      title: null,
      report_id: null,
      created_at: now,
      updated_at: now,
      last_message_at: null,
      user_message_count: 0,
      is_active_window: true,
      last_message: null,
    })

    return HttpResponse.json(chat, { status: 201 })
  }),

  // GET /api/chats/:id — детальный чат
  http.get('/api/chats/:id', async ({ params }: MockResolverArgs) => {
    await delay(MOCK_DELAY)
    const id = Number(params.id)
    const chat = mockChatDetails[id]
    if (!chat) return HttpResponse.json({ message: 'Not found' }, { status: 404 })
    if (!canMockUserAccessChat(chat)) {
      return HttpResponse.json({ message: 'Forbidden' }, { status: 403 })
    }
    return HttpResponse.json(chat)
  }),

  // DELETE /api/chats/:id
  http.delete('/api/chats/:id', async ({ params }: MockResolverArgs) => {
    await delay(MOCK_DELAY)
    const id = Number(params.id)
    const chat = mockChatDetails[id]
    if (!chat) return HttpResponse.json({ message: 'Not found' }, { status: 404 })
    if (!canMockUserAccessChat(chat)) {
      return HttpResponse.json({ message: 'Forbidden' }, { status: 403 })
    }

    if (chat.report_id) {
      const reportIndex = mockReports.findIndex((report) => report.id === chat.report_id)
      if (reportIndex !== -1) {
        mockReports.splice(reportIndex, 1)
      }
    }

    const idx = mockChats.findIndex((c) => c.id === id)
    if (idx !== -1) mockChats.splice(idx, 1)
    delete mockChatDetails[id]
    return HttpResponse.json({ message: 'Deleted' })
  }),

  // GET /api/chats/:id/messages
  http.get('/api/chats/:chatId/messages', async ({ params }: MockResolverArgs) => {
    await delay(MOCK_DELAY)
    const chatId = Number(params.chatId)
    const chat = mockChatDetails[chatId]
    if (!chat) return HttpResponse.json({ message: 'Not found' }, { status: 404 })
    if (!canMockUserAccessChat(chat)) {
      return HttpResponse.json({ message: 'Forbidden' }, { status: 403 })
    }

    return HttpResponse.json(
      [...(chat.messages ?? [])].sort((left, right) => left.created_at.localeCompare(right.created_at)),
    )
  }),

  // POST /api/chats/:id/messages — отправка сообщения
  http.post('/api/chats/:chatId/messages', async ({ request, params }: MockResolverArgs) => {
    const chatId = Number(params.chatId)
    const existingChat = mockChatDetails[chatId]
    if (existingChat && !canMockUserAccessChat(existingChat)) {
      return HttpResponse.json({ message: 'Forbidden' }, { status: 403 })
    }
    const body = (await request.json()) as { content: string }

    // Симулировать задержку AI
    await delay(MOCK_AI_RESPONSE_DELAY)

    if (MOCK_AI_ERROR) {
      const chat = mockChatDetails[chatId]
      const now = new Date().toISOString()
      const userMessage = createChatMessage(chatId, 'user', body.content, now)
      const errorAssistant: ChatMessageDto = {
        ...createChatMessage(
          chatId,
          'assistant',
          'Произошла ошибка при обработке запроса. Попробуйте ещё раз.',
          now,
          {
            error: { message: 'Mock AI provider rate limit' },
          },
        ),
        status: 'error',
        started_at: now,
        finished_at: now,
        events_count: 1,
      }

      const responseChat = {
        ...(chat ?? createMockChatDetail(chatId, getFallbackChatType(chatId), now)),
        messages: undefined,
      }

      return HttpResponse.json(
        {
          user_message: userMessage,
          assistant_message: errorAssistant,
          stream_url: `/api/chats/${chatId}/stream/${errorAssistant.id}`,
          chat: responseChat,
        },
        { status: 202 },
      )
    }

    const now = new Date().toISOString()
    const userMessage = createChatMessage(chatId, 'user', body.content, now)

    let chatDetail = mockChatDetails[chatId]
    if (!chatDetail) {
      chatDetail = createMockChatDetail(
        chatId,
        getFallbackChatType(chatId),
        now,
        body.content.slice(0, 80),
      )
      mockChatDetails[chatId] = chatDetail
    }

    if (!chatDetail.title) {
      chatDetail.title = body.content.slice(0, 80)
    }

    let aiContext: ChatAiContextDto = {
      last_tool_calls: ['probe_data'],
      total_steps: 2,
      probed_models: ['EstateDeals'],
      report_created: false,
    }

    const assistantToolCalls: ChatMessageMetadataDto['tool_calls'] = [
      { name: 'probe_data', arguments: '{"query":"chat_request"}' },
    ]

    if (chatDetail.type === 'report_generation' && !chatDetail.report_id) {
      const generatedReport = mockReports[0]
      if (generatedReport) {
        chatDetail.report_id = generatedReport.id
        chatDetail.report = generatedReport
        aiContext = {
          last_tool_calls: ['probe_data', 'create_report'],
          total_steps: 4,
          probed_models: ['EstateDeals'],
          report_created: true,
        }
        assistantToolCalls.push({
          name: 'create_report',
          arguments: `{"report_id":${generatedReport.id}}`,
        })
      }
    }

    // In the M4 async flow the assistant message is created with `status='pending'`
    // and `content=null`, then filled by the SSE-driven background job. The mock
    // skips that lifecycle and emits a fully-resolved assistant message (`status='done'`)
    // so the UI can render the response without a real stream subscription.
    const assistantMessage: ChatMessageDto = {
      ...createChatMessage(
        chatId,
        'assistant',
        `[Мок] Ответ на: «${body.content}»`,
        now,
        {
          finish_reason: 'stop',
          usage: {
            prompt_tokens: 1024,
            completion_tokens: 128,
            total_tokens: 1152,
          },
          tool_calls: assistantToolCalls,
          tool_results:
            chatDetail.type === 'report_generation' && chatDetail.report_id
              ? ['Data probed', 'Report created']
              : ['Data probed'],
        },
      ),
      status: 'done',
      started_at: now,
      finished_at: now,
      events_count: 0,
    }

    if (!chatDetail.messages) {
      chatDetail.messages = []
    }

    chatDetail.messages.push(userMessage, assistantMessage)
    chatDetail.updated_at = now
    chatDetail.ai_context = aiContext

    const lastMessage: ChatLastMessageDto = {
      role: assistantMessage.role,
      // ChatMessageDto.content is `string | null` since the M4 async flow added
      // pending assistant placeholders. In this sync mock the assistant message
      // is always fully resolved, so `content` is never null here — coerce for
      // the strict `ChatLastMessageDto.content: string` shape.
      content: assistantMessage.content ?? '',
      created_at: assistantMessage.created_at,
    }

    syncChatListItemFromDetail(chatDetail, lastMessage)

    const responseChat: ChatDetailDto = {
      id: chatDetail.id,
      user_id: chatDetail.user_id,
      company_id: chatDetail.company_id,
      type: chatDetail.type,
      scope_type: chatDetail.scope_type,
      title: chatDetail.title,
      report_id: chatDetail.report_id,
      ai_context: chatDetail.ai_context,
      report: chatDetail.report,
      created_at: chatDetail.created_at,
      updated_at: chatDetail.updated_at,
    }

    return HttpResponse.json(
      {
        user_message: userMessage,
        assistant_message: assistantMessage,
        stream_url: `/api/chats/${chatId}/stream/${assistantMessage.id}`,
        chat: responseChat,
      },
      { status: 202 },
    )
  }),

  http.put('/api/profile/home', async ({ request }: MockResolverArgs) => {
    await delay(MOCK_DELAY)

    const body = (await request.json()) as { path?: unknown }
    const path = typeof body.path === 'string' ? body.path : ''

    if (!path.startsWith('/') || path.startsWith('//')) {
      return HttpResponse.json({ message: 'Invalid path' }, { status: 422 })
    }

    mockCurrentUser.home_path = path

    return HttpResponse.json({ home_path: path })
  }),
]
