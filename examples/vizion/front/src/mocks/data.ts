import type {
  ChatAiContextDto,
  ChatDetailDto,
  ChatListItemDto,
  ChatMessageMetadataDto,
  ChatMessageDto,
  ChatType,
} from '@/api/types/chats'
import type {
  ReportChartDto,
  ReportColumnDto,
  ReportDto,
  ReportMetaDto,
  ReportSortOption,
  ReportTableRowDto,
} from '@/api/types/reports'
import type { UserDto } from '@/api/types/users'
import type { CompanyDto } from '@/api/types/companies'

// Фиктивные данные для отладки чатов
// Редактируй здесь чтобы симулировать разные состояния

const mockReportColumns: ReportColumnDto[] = [
  {
    field: 'agent',
    header: { ru: 'Агент', en: 'Agent' },
    sortable: true,
  },
  {
    field: 'deals',
    header: { ru: 'Сделки', en: 'Deals' },
    type: 'number',
    sortable: true,
  },
  {
    field: 'amount',
    header: { ru: 'Сумма', en: 'Amount' },
    type: 'number',
    sortable: true,
  },
]

const baseMockReportRows: ReportTableRowDto[] = [
  { agent: 'Иван Петров', deals: 12, amount: 18500000 },
  { agent: 'Анна Смирнова', deals: 9, amount: 13200000 },
  { agent: 'Максим Орлов', deals: 7, amount: 10400000 },
  { agent: 'Елена Козлова', deals: 15, amount: 22100000 },
  { agent: 'Дмитрий Волков', deals: 6, amount: 9300000 },
  { agent: 'Ольга Романова', deals: 11, amount: 16700000 },
  { agent: 'Сергей Лебедев', deals: 8, amount: 11800000 },
  { agent: 'Мария Федорова', deals: 10, amount: 14900000 },
  { agent: 'Павел Новиков', deals: 13, amount: 19400000 },
  { agent: 'Наталья Белова', deals: 5, amount: 8200000 },
]

const mockReportRows: ReportTableRowDto[] = Array.from({ length: 6 }, (_, batchIndex) =>
  baseMockReportRows.map((row, rowIndex) => ({
    agent: `${String(row.agent)} ${batchIndex + 1}`,
    deals: Number(row.deals ?? 0) + ((batchIndex + rowIndex) % 4),
    amount: Number(row.amount ?? 0) + batchIndex * 850000 + rowIndex * 125000,
  })),
).flat()

const createReportMeta = (total: number, page = 1, perPage = 50): ReportMetaDto => ({
  total,
  page,
  per_page: perPage,
  last_page: Math.max(1, Math.ceil(total / perPage)),
})

const createDealsChart = (rows: ReportTableRowDto[]): ReportChartDto => ({
  type: 'bar',
  labels: rows.map((row) => String(row.agent ?? '')),
  datasets: [
    {
      label: 'Сумма',
      data: rows.map((row) => Number(row.amount ?? 0)),
    },
  ],
})

const computeTotals = (
  rows: ReportTableRowDto[],
  columns: ReportColumnDto[],
): Record<string, number> => {
  const numericFields = columns
    .filter((col) => col.type === 'number' || col.type === 'currency')
    .map((col) => col.field)

  return Object.fromEntries(
    numericFields.map((field) => [
      field,
      rows.reduce((sum, row) => sum + (Number(row[field]) || 0), 0),
    ]),
  )
}

const mockReport: ReportDto = {
  id: 42,
  title: { ru: 'Отчёт по сделкам', en: 'Deals Report' },
  description: { ru: 'Сводка по сделкам за квартал', en: 'Quarterly deals summary' },
  columns: mockReportColumns,
  rows: mockReportRows,
  meta: createReportMeta(mockReportRows.length),
  chart: createDealsChart(mockReportRows),
  filters_available: {
    agent: {
      type: 'text',
      label: { ru: 'Агент', en: 'Agent' },
    },
  },
  filters_applied: {},
  config: {},
  is_system: false,
  is_published: true,
  user_id: 1,
  company_id: 1,
  totals: computeTotals(mockReportRows, mockReportColumns),
  created_at: '2026-01-15T10:00:00Z',
  updated_at: '2026-01-15T10:00:00Z',
  author: { id: 1, name: 'Иван Иванов', email: 'ivan@example.com' },
}

export const mockReports: ReportDto[] = [
  mockReport,
  {
    id: 43,
    title: { ru: 'Воронка продаж', en: 'Sales Funnel' },
    description: { ru: 'Стадии по сделкам', en: 'Deal stages overview' },
    columns: [
      { field: 'stage', header: { ru: 'Стадия', en: 'Stage' } },
      { field: 'count', header: { ru: 'Количество', en: 'Count' }, type: 'number' },
    ],
    rows: [
      { stage: 'Новый лид', count: 42 },
      { stage: 'Переговоры', count: 19 },
      { stage: 'Квалификация', count: 27 },
      { stage: 'Подготовка КП', count: 15 },
      { stage: 'Согласование договора', count: 11 },
      { stage: 'Сделка закрыта', count: 8 },
      { stage: 'Повторная продажа', count: 5 },
    ],
    meta: createReportMeta(7),
    chart: {
      type: 'pie',
      labels: ['Новый лид', 'Переговоры', 'Сделка закрыта'],
      datasets: [
        {
          label: 'Количество',
          data: [42, 19, 27, 15, 11, 8, 5],
        },
      ],
    },
    filters_available: {},
    filters_applied: {},
    config: {},
    is_system: true,
    is_published: true,
    user_id: 1,
    company_id: 1,
    totals: { count: 127 },
    created_at: '2026-01-10T08:00:00Z',
    updated_at: '2026-01-10T08:00:00Z',
    // System reports have no author projection (backend contract).
    author: null,
  },
]

const createChatMessage = (
  id: number,
  chatId: number,
  role: ChatMessageDto['role'],
  content: string,
  createdAt: string,
  metadata: ChatMessageMetadataDto | null = null,
): ChatMessageDto => ({
  id,
  chat_id: chatId,
  user_id: 1,
  company_id: 1,
  role,
  content,
  metadata,
  created_at: createdAt,
  updated_at: createdAt,
})

const mockQuickQaContext: ChatAiContextDto = {
  last_tool_calls: ['probe_data'],
  total_steps: 1,
  probed_models: ['EstateDeals'],
  report_created: false,
}

const mockReportContext: ChatAiContextDto = {
  last_tool_calls: ['probe_data', 'create_report'],
  total_steps: 3,
  probed_models: ['EstateDeals'],
  report_created: true,
}

export const mockChats: ChatListItemDto[] = [
  {
    id: 1,
    type: 'quick_qa',
    scope_type: 'general',
    title: 'Анализ продаж',
    report_id: null,
    created_at: '2026-04-10T09:00:00.000000Z',
    updated_at: '2026-04-10T09:05:00.000000Z',
    last_message_at: '2026-04-10T09:05:00.000000Z',
    user_message_count: 1,
    is_active_window: true,
    last_message: { role: 'assistant', content: 'Средняя цена квартиры составляет 8.2 млн руб.', created_at: '2026-04-10T09:05:00.000000Z' },
  },
  {
    id: 2,
    type: 'quick_qa',
    scope_type: 'general',
    title: null,
    report_id: null,
    created_at: '2026-04-10T10:00:00.000000Z',
    updated_at: '2026-04-10T10:00:00.000000Z',
    last_message_at: null,
    user_message_count: 0,
    is_active_window: true,
    last_message: null,
  },
  {
    id: 3,
    type: 'report_generation',
    // Backfill rule: report_id IS NOT NULL → scope_type='report' (never 'report_generation').
    scope_type: 'report',
    title: 'Отчёт по сделкам',
    report_id: 42,
    created_at: '2026-04-09T08:00:00.000000Z',
    updated_at: '2026-04-09T08:30:00.000000Z',
    last_message_at: '2026-04-09T08:30:00.000000Z',
    user_message_count: 1,
    is_active_window: false,
    last_message: { role: 'assistant', content: 'Отчёт создан успешно.', created_at: '2026-04-09T08:30:00.000000Z' },
  },
]

export const mockChatDetails: Record<number, ChatDetailDto> = {
  1: {
    id: 1,
    user_id: 1,
    company_id: 1,
    type: 'quick_qa',
    scope_type: 'general',
    title: 'Анализ продаж',
    report_id: null,
    ai_context: mockQuickQaContext,
    report: null,
    created_at: '2026-04-10T09:00:00.000000Z',
    updated_at: '2026-04-10T09:05:00.000000Z',
    messages: [
      createChatMessage(
        1,
        1,
        'user',
        'Какая средняя цена квартиры?',
        '2026-04-10T09:00:00.000000Z',
      ),
      createChatMessage(
        2,
        1,
        'assistant',
        'Средняя цена квартиры составляет 8.2 млн руб. по данным за последний квартал.',
        '2026-04-10T09:05:00.000000Z',
        {
          finish_reason: 'stop',
          usage: {
            prompt_tokens: 1240,
            completion_tokens: 46,
            total_tokens: 1286,
          },
          tool_calls: [{ name: 'probe_data', arguments: '{\"metric\":\"avg_price\"}' }],
          tool_results: ['Average price calculated successfully'],
        },
      ),
    ],
  },
  2: {
    id: 2,
    user_id: 1,
    company_id: 1,
    type: 'quick_qa',
    scope_type: 'general',
    title: null,
    report_id: null,
    ai_context: null,
    report: null,
    created_at: '2026-04-10T10:00:00.000000Z',
    updated_at: '2026-04-10T10:00:00.000000Z',
    messages: [],
  },
  3: {
    id: 3,
    user_id: 1,
    company_id: 1,
    type: 'report_generation',
    // Backfill rule: report_id IS NOT NULL → scope_type='report' (never 'report_generation').
    scope_type: 'report',
    title: 'Отчёт по сделкам',
    report_id: 42,
    ai_context: mockReportContext,
    report: mockReport,
    created_at: '2026-04-09T08:00:00.000000Z',
    updated_at: '2026-04-09T08:30:00.000000Z',
    messages: [
      createChatMessage(
        10,
        3,
        'user',
        'Создай отчёт по сделкам за квартал',
        '2026-04-09T08:00:00.000000Z',
      ),
      createChatMessage(
        11,
        3,
        'assistant',
        'Отчёт создан успешно. Вы можете открыть его по кнопке выше.',
        '2026-04-09T08:30:00.000000Z',
        {
          finish_reason: 'stop',
          usage: {
            prompt_tokens: 4388,
            completion_tokens: 218,
            total_tokens: 4606,
          },
          tool_calls: [
            { name: 'probe_data', arguments: '{\"model\":\"EstateDeals\"}' },
            { name: 'create_report', arguments: '{\"report_type\":\"deals\"}' },
          ],
          tool_results: ['Data found', 'Report created'],
        },
      ),
    ],
  },
}

// Полный CompanyDto-стуб — match the shape returned by `CompanyResource` on
// the backend (id, name, is_system + optional crm_url / macrodata_*). Keep in
// sync with `api/types/companies.ts`.
export const mockCompany: CompanyDto = {
  id: 1,
  name: 'Mock Company',
  is_system: false,
  currency_code: 'RUB',
  timezone: 'Europe/Moscow',
  crm_url: null,
}

export const mockCurrentUser: UserDto = {
  id: 1,
  name: 'Mock Analyst',
  email: 'analyst@example.com',
  role: 'analyst',
  locale: 'ru',
  home_path: '/reports',
  company_id: 1,
  active_company_id: 1,
  active_company: mockCompany,
  company_accesses: [
    {
      company_id: 1,
      role: 'analyst',
    },
  ],
}

// Симулировать задержку сети (мс)
export const MOCK_DELAY = 400

// Симулировать ошибку AI при отправке сообщения
export const MOCK_AI_ERROR = false

// Симулировать медленный ответ AI (мс)
export const MOCK_AI_RESPONSE_DELAY = 1500

export const getFallbackChatType = (chatId: number): ChatType =>
  chatId % 2 === 0 ? 'quick_qa' : 'report_generation'

export const getMockReportById = (id: number): ReportDto | undefined =>
  mockReports.find((report) => report.id === id)

export const canMockUserCreateChats = (): boolean =>
  ['superadmin', 'admin', 'analyst'].includes(mockCurrentUser.role)

export const canMockUserAccessChat = (chat: ChatDetailDto): boolean => {
  if (mockCurrentUser.role === 'superadmin') {
    return true
  }

  if (mockCurrentUser.role === 'admin') {
    return chat.company_id === mockCurrentUser.company_id
  }

  return chat.user_id === mockCurrentUser.id
}

export const canMockUserViewReport = (report: ReportDto): boolean => {
  if (mockCurrentUser.role === 'superadmin') {
    return true
  }

  if (report.company_id !== mockCurrentUser.company_id && !report.is_system) {
    return false
  }

  if (!report.is_system && mockCurrentUser.role === 'viewer') {
    return Boolean(report.is_published)
  }

  return true
}

export const filterMockReportsForCurrentUser = (reports: ReportDto[]): ReportDto[] => {
  if (mockCurrentUser.role === 'superadmin') {
    return reports
  }

  if (mockCurrentUser.role === 'admin') {
    return reports.filter(
      (report) => report.is_system || report.company_id === mockCurrentUser.company_id,
    )
  }

  if (mockCurrentUser.role === 'analyst') {
    return reports.filter(
      (report) =>
        report.is_system ||
        (report.company_id === mockCurrentUser.company_id &&
          (report.user_id === mockCurrentUser.id || report.is_published)),
    )
  }

  return reports.filter(
    (report) =>
      report.is_system ||
      (report.company_id === mockCurrentUser.company_id && report.is_published),
  )
}

export const buildMockReportListResponse = (report: ReportDto): ReportDto => ({
  id: report.id,
  title: report.title,
  description: report.description,
  config: report.config,
  is_system: report.is_system,
  is_published: report.is_published,
  user_id: report.user_id,
  company_id: report.company_id,
  chat_message_id: report.chat_message_id,
  created_at: report.created_at,
  updated_at: report.updated_at,
  author: report.author,
})

export const MOCK_REPORTS_WITH_DELAYED_FILTERS = new Set<number>()

const compareReportValues = (left: ReportTableRowDto[string], right: ReportTableRowDto[string]): number => {
  if (left == null && right == null) return 0
  if (left == null) return 1
  if (right == null) return -1

  if (typeof left === 'number' && typeof right === 'number') {
    return left - right
  }

  return String(left).localeCompare(String(right), 'ru', { numeric: true, sensitivity: 'base' })
}

export const buildMockReportResponse = (
  report: ReportDto,
  options?: {
    page?: number
    perPage?: number
    sort?: ReportSortOption | null
    includeFilters?: boolean
  },
): ReportDto => {
  // Mock data only contains flat rows — cast is safe here
  const rows = [...(report.rows ?? [])] as ReportTableRowDto[]
  const page = Math.max(1, options?.page ?? 1)
  const perPage = Math.max(1, options?.perPage ?? report.meta?.per_page ?? 50)
  const sort = options?.sort

  if (sort?.field && rows.length > 0 && rows.some((row) => sort.field in row)) {
    rows.sort((leftRow, rightRow) => {
      const comparison = compareReportValues(leftRow[sort.field]!, rightRow[sort.field]!)
      return sort.direction === 'desc' ? -comparison : comparison
    })
  }

  const total = rows.length
  const start = (page - 1) * perPage
  const paginatedRows = rows.slice(start, start + perPage)

  const totals =
    report.totals != null
      ? report.totals
      : report.columns
        ? computeTotals(rows, report.columns)
        : undefined

  return {
    ...report,
    rows: paginatedRows,
    meta: createReportMeta(total, page, perPage),
    filters_available: options?.includeFilters === false ? {} : report.filters_available,
    totals,
  }
}
