import type { LocalizedText } from '@/shared/types'
import type {
  WidgetAuthorDto,
  WidgetChartType,
  WidgetConfigDto,
} from '@/api/types/widgets'

export type { WidgetChartType, WidgetConfigDto }
export type WidgetAuthor = WidgetAuthorDto

/**
 * Library list item — entity-layer (camelCase) mirror of `WidgetListItemDto`.
 */
export interface WidgetListItem {
  id: number
  name: LocalizedText
  config: WidgetConfigDto
  isSystem: boolean
  isPublished: boolean
  userId: number | null
  usedInDashboardsCount: number
  author: WidgetAuthor | null
}

/**
 * Full widget detail — camelCase mirror of `WidgetDto`.
 */
export interface Widget {
  id: number
  name: LocalizedText
  config: WidgetConfigDto
  isSystem: boolean
  isPublished: boolean
  userId: number | null
  chatMessageId: number | null
  /** Resume target for the widget-generation edit modal. */
  chatId: number | null
  usedInDashboardsCount: number
  author: WidgetAuthor | null
  createdAt: string
  updatedAt: string
}

export interface WidgetDataset {
  /** May be a technical alias or a localized `{ru, en}` object — see `WidgetDatasetDto.label`. */
  label: LocalizedText
  data: number[]
}

/**
 * Chart-ready data for a widget — camelCase mirror of `WidgetDataDto`.
 */
export interface WidgetData {
  labels: string[]
  datasets: WidgetDataset[]
  periodFrom: string | null
  periodTo: string | null
  periodApplied: boolean
  rowCount: number
}
