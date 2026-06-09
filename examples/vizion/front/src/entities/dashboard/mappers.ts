import type {
  DashboardDataDto,
  DashboardDto,
  DashboardListItemDto,
  DashboardWidgetDto,
} from '@/api/types/dashboards'
import { mapWidgetDataDtoToData } from '@/entities/widget'
import type {
  Dashboard,
  DashboardData,
  DashboardListItem,
  DashboardWidget,
} from './types'
import { mapPivotDtoToPivot } from './types'

export const mapDashboardWidgetDtoToWidget = (dto: DashboardWidgetDto): DashboardWidget => ({
  id: dto.id,
  name: dto.name,
  config: dto.config,
  isSystem: dto.is_system,
  isPublished: dto.is_published,
  userId: dto.user_id,
  pivot: mapPivotDtoToPivot(dto.pivot),
})

export const mapDashboardListItemDtoToItem = (
  dto: DashboardListItemDto,
): DashboardListItem => ({
  id: dto.id,
  name: dto.name,
  isSystem: dto.is_system,
  isPublished: dto.is_published,
  userId: dto.user_id,
  widgetsCount: dto.widgets_count,
  author: dto.author,
})

export const mapDashboardDtoToDashboard = (dto: DashboardDto): Dashboard => ({
  id: dto.id,
  name: dto.name,
  isSystem: dto.is_system,
  isPublished: dto.is_published,
  userId: dto.user_id,
  author: dto.author,
  createdAt: dto.created_at,
  updatedAt: dto.updated_at,
  widgets: (dto.widgets ?? []).map(mapDashboardWidgetDtoToWidget),
})

export const mapDashboardDataDtoToData = (dto: DashboardDataDto): DashboardData => {
  const widgets: DashboardData['widgets'] = {}
  for (const [key, value] of Object.entries(dto.widgets)) {
    const id = Number(key)
    if (!Number.isFinite(id)) continue
    widgets[id] = mapWidgetDataDtoToData(value)
  }
  return {
    widgets,
    periodFrom: dto.meta.period_from,
    periodTo: dto.meta.period_to,
  }
}
