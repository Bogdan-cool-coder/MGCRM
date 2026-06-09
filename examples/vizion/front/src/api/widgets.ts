import { apiClient } from '@/api/client'
import type { PeriodRange } from '@/api/types/dashboards'
import type {
  CreateWidgetRequest,
  PreviewWidgetRequest,
  UpdateWidgetRequest,
  WidgetDataDto,
  WidgetDto,
  WidgetListItemDto,
} from '@/api/types/widgets'

export interface WidgetsApi {
  /**
   * Library — system + published + the caller's personal widgets for the
   * active company (resolved by backend middleware, no `company_id` param).
   */
  fetchWidgets(): Promise<WidgetListItemDto[]>
  fetchWidget(_id: number): Promise<WidgetDto>
  createWidget(_data: CreateWidgetRequest): Promise<WidgetDto>
  updateWidget(_id: number, _data: UpdateWidgetRequest): Promise<WidgetDto>
  /**
   * Delete a widget entity. Without `force` and while the widget is still
   * referenced by a dashboard → 409 (`WidgetInUseErrorDto`). With `force: true`
   * the backend cascade-detaches it from every dashboard, then deletes it.
   * 403 for system widgets.
   */
  deleteWidget(_id: number, _options?: { force?: boolean }): Promise<void>
  publishWidget(_id: number): Promise<WidgetDto>
  unpublishWidget(_id: number): Promise<WidgetDto>
  /** Chart.js-shaped data for a single widget, optionally for a `YYYY-MM` range. */
  fetchWidgetData(_id: number, _range?: PeriodRange): Promise<WidgetDataDto>
  /** Chart-ready data for an unsaved config — drives the variant preview cards. */
  previewWidget(_data: PreviewWidgetRequest): Promise<WidgetDataDto>
}

export const widgetsApi: WidgetsApi = {
  async fetchWidgets(): Promise<WidgetListItemDto[]> {
    const response = await apiClient.get<WidgetListItemDto[]>('/api/widgets')
    return response.data
  },

  async fetchWidget(id: number): Promise<WidgetDto> {
    const response = await apiClient.get<WidgetDto>(`/api/widgets/${id}`)
    return response.data
  },

  async createWidget(data: CreateWidgetRequest): Promise<WidgetDto> {
    const response = await apiClient.post<WidgetDto>('/api/widgets', data)
    return response.data
  },

  async updateWidget(id: number, data: UpdateWidgetRequest): Promise<WidgetDto> {
    const response = await apiClient.put<WidgetDto>(`/api/widgets/${id}`, data)
    return response.data
  },

  async deleteWidget(id: number, options?: { force?: boolean }): Promise<void> {
    await apiClient.delete(`/api/widgets/${id}`, {
      params: options?.force ? { force: true } : undefined,
    })
  },

  async publishWidget(id: number): Promise<WidgetDto> {
    const response = await apiClient.post<WidgetDto>(`/api/widgets/${id}/publish`)
    return response.data
  },

  async unpublishWidget(id: number): Promise<WidgetDto> {
    const response = await apiClient.post<WidgetDto>(`/api/widgets/${id}/unpublish`)
    return response.data
  },

  async fetchWidgetData(id: number, range?: PeriodRange): Promise<WidgetDataDto> {
    const response = await apiClient.get<WidgetDataDto>(`/api/widgets/${id}/data`, {
      params: range ? { period_from: range.from, period_to: range.to } : undefined,
    })
    return response.data
  },

  async previewWidget(data: PreviewWidgetRequest): Promise<WidgetDataDto> {
    const response = await apiClient.post<WidgetDataDto>('/api/widgets/preview', data)
    return response.data
  },
}
