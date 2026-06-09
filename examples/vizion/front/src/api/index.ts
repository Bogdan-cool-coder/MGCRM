// API Layer
export { authApi } from '@/api/auth'
export type {
  AuthApi,
  IframeAuthRequest,
  LoginRequest,
  LoginResponse,
} from '@/api/auth'

export { usersApi } from './users'
export type {
  CreateUserRequest,
  UpdateUserRequest,
  UserIframeLinkResponse,
  UserDto,
  UsersApi,
} from './users'

export { profileApi } from './profile'
export type { ProfileApi, SetHomePathRequest, SetHomePathResponse } from './profile'

export { companiesApi } from './companies'
export type {
  CompaniesApi,
  CompanyDto,
  CreateCompanyRequest,
  UpdateCompanyRequest,
} from './companies'

export { macrodataMappingsApi } from './macrodataMappings'
export type {
  MacrodataMappingsApi,
  MacrodataMappingDto,
  MacrodataMappingUpsertItem,
  MacrodataProbeResultDto,
} from './macrodataMappings'

export { reportsApi } from './reports'
export type {
  FetchReportOptions,
  Report,
  ReportsApi,
  UpdateReportRequest,
} from './reports'

export { reportPreferencesApi } from './reportPreferences'
export type { ReportPreferencesApi } from './reportPreferences'
export type {
  ReportColumnOrderPreference,
  ReportPreferences,
  ReportPreferencesPatch,
} from './types/reportPreferences'

export { widgetsApi } from './widgets'
export type { WidgetsApi } from './widgets'
export type {
  CreateWidgetRequest,
  UpdateWidgetRequest,
  WidgetChartType,
  WidgetConfigDto,
  WidgetDataDto,
  WidgetDto,
  WidgetListItemDto,
} from './types/widgets'

export { dashboardsApi } from './dashboards'
export type { DashboardsApi } from './dashboards'
export type {
  AttachWidgetRequest,
  CreateDashboardRequest,
  DashboardDataDto,
  DashboardDto,
  DashboardLayoutItem,
  DashboardListItemDto,
  DashboardWidgetDto,
  UpdateDashboardLayoutRequest,
  UpdateDashboardRequest,
} from './types/dashboards'

export { chatsApi } from './chats'
export type {
  ChatDetailDto,
  ChatListItemDto,
  ChatMessageDto,
  ChatMessageRole,
  ChatType,
  CreateChatRequest,
  SendMessageRequest,
  SendMessageResponseDto,
} from './types/chats'

export { documentsApi } from './documents'
export type { DocumentsApi } from './documents'
export type {
  CreateDocumentTemplateRequest,
  DocumentGenerateParams,
  DocumentPreviewParams,
  DocumentPreviewResponseDto,
  DocumentTemplateConfigDto,
  DocumentTemplateDto,
  DocumentTemplateListItemDto,
  DocumentTemplateType,
  GenerateDocumentResponseDto,
  GeneratedDocumentDto,
  GeneratedDocumentFormat,
  GeneratedDocumentStatus,
  UpdateDocumentTemplateRequest,
} from './types/documents'

export { brandingApi } from './branding'
export type { BrandingApi } from './branding'
export type {
  BrandingColorsDto,
  BrandingDto,
  BrandingFontsDto,
  UpdateBrandingRequest,
} from './types/branding'

export { promotionsApi } from './promotions'
export type { PromotionsApi } from './promotions'
export type {
  CreatePromotionRequest,
  PromotionDiscountType,
  PromotionDto,
  UpdatePromotionRequest,
} from './types/promotions'

export { macroDataApi } from './macrodata'
export type { MacroDataApi } from './macrodata'
export type {
  EstateSellDetailDto,
  EstateSellOptionDto,
  MacroDataSchemaDto,
  MacroDataSchemaFieldDto,
} from './types/macrodata'
