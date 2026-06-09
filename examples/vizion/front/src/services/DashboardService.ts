import { dashboardsApi } from '@/api/dashboards'
import {
  mapDashboardDataDtoToData,
  mapDashboardDtoToDashboard,
  mapDashboardListItemDtoToItem,
} from '@/entities/dashboard'
import type {
  Dashboard,
  DashboardData,
  DashboardListItem,
} from '@/entities/dashboard'
import type {
  AttachWidgetRequest,
  CreateDashboardRequest,
  DashboardLayoutItem,
  PeriodRange,
  UpdateDashboardRequest,
} from '@/api/types/dashboards'

export class DashboardService {
  async fetchAllDashboards(): Promise<DashboardListItem[]> {
    return (await dashboardsApi.fetchDashboards()).map(mapDashboardListItemDtoToItem)
  }

  async fetchDashboard(id: number): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(await dashboardsApi.fetchDashboard(id))
  }

  async createDashboard(data: CreateDashboardRequest): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(await dashboardsApi.createDashboard(data))
  }

  async updateDashboard(id: number, data: UpdateDashboardRequest): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(await dashboardsApi.updateDashboard(id, data))
  }

  async deleteDashboard(id: number): Promise<void> {
    await dashboardsApi.deleteDashboard(id)
  }

  async publishDashboard(id: number): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(await dashboardsApi.publishDashboard(id))
  }

  async unpublishDashboard(id: number): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(await dashboardsApi.unpublishDashboard(id))
  }

  async attachWidget(dashboardId: number, data: AttachWidgetRequest): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(await dashboardsApi.attachWidget(dashboardId, data))
  }

  async detachWidget(dashboardId: number, widgetId: number): Promise<void> {
    await dashboardsApi.detachWidget(dashboardId, widgetId)
  }

  async updateLayout(dashboardId: number, widgets: DashboardLayoutItem[]): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(
      await dashboardsApi.updateLayout(dashboardId, { widgets }),
    )
  }

  async fetchDashboardData(dashboardId: number, range?: PeriodRange): Promise<DashboardData> {
    return mapDashboardDataDtoToData(
      await dashboardsApi.fetchDashboardData(dashboardId, range),
    )
  }

  async cloneDashboard(dashboardId: number): Promise<Dashboard> {
    return mapDashboardDtoToDashboard(await dashboardsApi.cloneDashboard(dashboardId))
  }
}
