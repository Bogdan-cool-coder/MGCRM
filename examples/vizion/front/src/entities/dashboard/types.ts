import type { LocalizedText } from '@/shared/types'
import type {
  DashboardAuthorDto,
  DashboardWidgetPivotDto,
} from '@/api/types/dashboards'
import type { WidgetConfigDto } from '@/api/types/widgets'

export type DashboardAuthor = DashboardAuthorDto

/**
 * Grid placement / visibility of a widget on a dashboard. `x`/`y`/`w`/`h` map
 * directly to `grid-layout-plus` item coordinates.
 */
export interface DashboardWidgetPivot {
  x: number
  y: number
  w: number
  h: number
  sort: number
  visible: boolean
}

export const mapPivotDtoToPivot = (dto: DashboardWidgetPivotDto): DashboardWidgetPivot => ({
  x: dto.x,
  y: dto.y,
  w: dto.w,
  h: dto.h,
  sort: dto.sort,
  visible: dto.visible,
})

/**
 * A widget instance positioned on a dashboard — the widget entity plus its
 * placement pivot for that dashboard.
 */
export interface DashboardWidget {
  id: number
  name: LocalizedText
  config: WidgetConfigDto
  isSystem: boolean
  isPublished: boolean
  userId: number | null
  pivot: DashboardWidgetPivot
}

/**
 * Library list item — camelCase mirror of `DashboardListItemDto`.
 */
export interface DashboardListItem {
  id: number
  name: LocalizedText
  isSystem: boolean
  isPublished: boolean
  userId: number | null
  widgetsCount: number
  author: DashboardAuthor | null
}

/**
 * Full dashboard detail — camelCase mirror of `DashboardDto`.
 */
export interface Dashboard {
  id: number
  name: LocalizedText
  isSystem: boolean
  isPublished: boolean
  userId: number | null
  author: DashboardAuthor | null
  createdAt: string
  updatedAt: string
  widgets: DashboardWidget[]
}

/**
 * Per-widget chart data on a dashboard, keyed by widget id. `periodFrom` /
 * `periodTo` echo the applied inclusive month range (`null` when no range was
 * sent — backend then falls back to the temporal default).
 */
export interface DashboardData {
  widgets: Record<number, import('@/entities/widget').WidgetData>
  periodFrom: string | null
  periodTo: string | null
}
