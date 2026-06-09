import type {
  ReportChartConfigDto,
  ReportChartDefinitionDto,
  ReportChartDto,
  ReportColumnDto,
  ReportConfigDto,
  ReportDatasetDto,
  ReportDemoDataDto,
  ReportDto,
  ReportGroupMetaDto,
  ReportGroupByDto,
  ReportMetaDto,
} from '@/api/types/reports'
import type { ReportFiltersApplied, ReportFiltersAvailable } from './filters'
import type { LocalizedText } from '@/shared/types'

export type ReportTableCellValue = string | number | boolean | null
// A data row may also contain _badge_<field> objects (Record<string, unknown>)
// alongside regular cell values
export type ReportTableRow = Record<string, ReportTableCellValue | Record<string, unknown>>
export type ReportTotalsRow = Record<string, ReportTableCellValue>

export interface ReportGroupRow {
  group_key: string
  group_meta: ReportGroupMetaDto
  children_count: number
  has_children: boolean
}

export type ReportAnyRow = ReportTableRow | ReportGroupRow

export interface ReportColumn extends ReportColumnDto {
  header: LocalizedText
}

export interface ReportMeta extends ReportMetaDto {}

export interface ReportDataset extends ReportDatasetDto {}

export interface ReportChart extends ReportChartDto {
  datasets: ReportDataset[]
}

export interface ReportChartDefinition extends ReportChartDefinitionDto {
  label?: LocalizedText
}

export interface ReportDemoData extends Omit<ReportDemoDataDto, 'chart'> {
  rows: ReportTableRow[]
  chart?: ReportChart
}

export interface ReportConfig extends Omit<ReportConfigDto, 'columns' | 'chart' | 'demo_data'> {
  columns?: ReportColumn[]
  chart?: ReportChartDefinition
  demo_data?: ReportDemoData
  group_by?: ReportGroupByDto
}

export interface ReportChartConfig extends Omit<ReportChartConfigDto, 'data'> {
  data: ReportChart
}

export interface Report
  extends Omit<
    ReportDto,
    'columns' | 'rows' | 'meta' | 'chart' | 'filters_available' | 'filters_applied' | 'config' | 'chart_config'
  > {
  title: LocalizedText
  description?: LocalizedText
  columns?: ReportColumn[]
  rows?: ReportAnyRow[]
  meta?: ReportMeta
  chart?: ReportChart
  filters_available?: ReportFiltersAvailable
  filters_applied?: ReportFiltersApplied
  config?: ReportConfig
  chart_config?: ReportChartConfig
  totals?: ReportTotalsRow
}
