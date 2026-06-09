import { inject } from 'vue'
import { ReportService } from './ReportService'
import { ChatService } from './ChatService'
import { AuthService } from './AuthService'
import { UserService } from './UserService'
import { CompanyService } from './CompanyService'
import { WidgetService } from './WidgetService'
import { DashboardService } from './DashboardService'
import { DocumentService } from './DocumentService'
import { PromotionService } from './PromotionService'
import { BrandingService } from './BrandingService'
import { MacroDataLookupService } from './MacroDataLookupService'
import type { Report } from '@/entities/report'
import type { ReportItem } from '@/entities/report'

export type Services = {
  authService: AuthService
  userService: UserService
  companyService: CompanyService
  reportService: ReportService
  chatService: ChatService
  widgetService: WidgetService
  dashboardService: DashboardService
  documentService: DocumentService
  promotionService: PromotionService
  brandingService: BrandingService
  macroDataLookupService: MacroDataLookupService
}

export const SERVICES_KEY = Symbol('services')

export const createServices = (): Services => {
  return {
    authService: new AuthService(),
    userService: new UserService(),
    companyService: new CompanyService(),
    reportService: new ReportService(),
    chatService: new ChatService(),
    widgetService: new WidgetService(),
    dashboardService: new DashboardService(),
    documentService: new DocumentService(),
    promotionService: new PromotionService(),
    brandingService: new BrandingService(),
    macroDataLookupService: new MacroDataLookupService(),
  }
}

export const useServices = () => {
  const services = inject<Services>(SERVICES_KEY)
  if (!services) {
    throw new Error(
      'Services not provided. Make sure to call app.provide(SERVICES_KEY, services) in main.ts',
    )
  }
  return services
}

export {
  AuthService,
  UserService,
  CompanyService,
  ReportService,
  ChatService,
  WidgetService,
  DashboardService,
  DocumentService,
  PromotionService,
  BrandingService,
  MacroDataLookupService,
  type ReportItem,
}
export type { Report }
