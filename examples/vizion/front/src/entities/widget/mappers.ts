import type {
  WidgetDataDto,
  WidgetDto,
  WidgetListItemDto,
} from '@/api/types/widgets'
import type { Widget, WidgetData, WidgetListItem } from './types'

export const mapWidgetListItemDtoToItem = (dto: WidgetListItemDto): WidgetListItem => ({
  id: dto.id,
  name: dto.name,
  config: dto.config,
  isSystem: dto.is_system,
  isPublished: dto.is_published,
  userId: dto.user_id,
  usedInDashboardsCount: dto.used_in_dashboards_count,
  author: dto.author,
})

export const mapWidgetDtoToWidget = (dto: WidgetDto): Widget => ({
  id: dto.id,
  name: dto.name,
  config: dto.config,
  isSystem: dto.is_system,
  isPublished: dto.is_published,
  userId: dto.user_id,
  chatMessageId: dto.chat_message_id,
  chatId: dto.chat_id,
  usedInDashboardsCount: dto.used_in_dashboards_count,
  author: dto.author,
  createdAt: dto.created_at,
  updatedAt: dto.updated_at,
})

export const mapWidgetDataDtoToData = (dto: WidgetDataDto): WidgetData => ({
  labels: dto.labels,
  datasets: dto.datasets,
  periodFrom: dto.meta.period_from,
  periodTo: dto.meta.period_to,
  periodApplied: dto.meta.period_applied,
  rowCount: dto.meta.row_count,
})
