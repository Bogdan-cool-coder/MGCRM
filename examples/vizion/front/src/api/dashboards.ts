import { apiClient } from '@/api/client'
import type {
  AttachWidgetRequest,
  CreateDashboardRequest,
  DashboardDataDto,
  DashboardDto,
  DashboardListItemDto,
  PeriodRange,
  UpdateDashboardLayoutRequest,
  UpdateDashboardRequest,
} from '@/api/types/dashboards'

export interface DashboardsApi {
  /** Library — system + published + personal for the active company. */
  fetchDashboards(): Promise<DashboardListItemDto[]>
  fetchDashboard(_id: number): Promise<DashboardDto>
  createDashboard(_data: CreateDashboardRequest): Promise<DashboardDto>
  /** 403 for system dashboards (clone instead). */
  updateDashboard(_id: number, _data: UpdateDashboardRequest): Promise<DashboardDto>
  /** owner / admin only; 403 for system dashboards. */
  deleteDashboard(_id: number): Promise<void>
  /** Publish to the whole company. admin / superadmin; 403 for system dashboards. Returns the dashboard payload WITHOUT `widgets`. */
  publishDashboard(_id: number): Promise<DashboardDto>
  /** Unpublish. admin / superadmin; 403 for system dashboards. Returns the dashboard payload WITHOUT `widgets`. */
  unpublishDashboard(_id: number): Promise<DashboardDto>
  /** Attach a widget reference. Returns the full refreshed dashboard. 409 on duplicate. */
  attachWidget(_dashboardId: number, _data: AttachWidgetRequest): Promise<DashboardDto>
  /** Detach a widget reference (the widget entity is untouched). 404 if not attached. */
  detachWidget(_dashboardId: number, _widgetId: number): Promise<void>
  /** Batch persist grid placement / visibility. */
  updateLayout(_dashboardId: number, _data: UpdateDashboardLayoutRequest): Promise<DashboardDto>
  /** Per-widget Chart.js data, keyed by widget id; only visible widgets. */
  fetchDashboardData(_dashboardId: number, _range?: PeriodRange): Promise<DashboardDataDto>
  /** Clone any visible dashboard into a personal copy (+ pivot links). */
  cloneDashboard(_dashboardId: number): Promise<DashboardDto>
}

export const dashboardsApi: DashboardsApi = {
  async fetchDashboards(): Promise<DashboardListItemDto[]> {
    const response = await apiClient.get<DashboardListItemDto[]>('/api/dashboards')
    return response.data
  },

  async fetchDashboard(id: number): Promise<DashboardDto> {
    const response = await apiClient.get<DashboardDto>(`/api/dashboards/${id}`)
    return response.data
  },

  async createDashboard(data: CreateDashboardRequest): Promise<DashboardDto> {
    const response = await apiClient.post<DashboardDto>('/api/dashboards', data)
    return response.data
  },

  async updateDashboard(id: number, data: UpdateDashboardRequest): Promise<DashboardDto> {
    const response = await apiClient.put<DashboardDto>(`/api/dashboards/${id}`, data)
    return response.data
  },

  async deleteDashboard(id: number): Promise<void> {
    await apiClient.delete(`/api/dashboards/${id}`)
  },

  async publishDashboard(id: number): Promise<DashboardDto> {
    const response = await apiClient.post<DashboardDto>(`/api/dashboards/${id}/publish`)
    return response.data
  },

  async unpublishDashboard(id: number): Promise<DashboardDto> {
    const response = await apiClient.post<DashboardDto>(`/api/dashboards/${id}/unpublish`)
    return response.data
  },

  async attachWidget(dashboardId: number, data: AttachWidgetRequest): Promise<DashboardDto> {
    const response = await apiClient.post<DashboardDto>(
      `/api/dashboards/${dashboardId}/widgets`,
      data,
    )
    return response.data
  },

  async detachWidget(dashboardId: number, widgetId: number): Promise<void> {
    await apiClient.delete(`/api/dashboards/${dashboardId}/widgets/${widgetId}`)
  },

  async updateLayout(
    dashboardId: number,
    data: UpdateDashboardLayoutRequest,
  ): Promise<DashboardDto> {
    const response = await apiClient.put<DashboardDto>(
      `/api/dashboards/${dashboardId}/layout`,
      data,
    )
    return response.data
  },

  async fetchDashboardData(
    dashboardId: number,
    range?: PeriodRange,
  ): Promise<DashboardDataDto> {
    const response = await apiClient.get<DashboardDataDto>(
      `/api/dashboards/${dashboardId}/data`,
      { params: range ? { period_from: range.from, period_to: range.to } : undefined },
    )
    return response.data
  },

  async cloneDashboard(dashboardId: number): Promise<DashboardDto> {
    const response = await apiClient.post<DashboardDto>(`/api/dashboards/${dashboardId}/clone`)
    return response.data
  },
}
