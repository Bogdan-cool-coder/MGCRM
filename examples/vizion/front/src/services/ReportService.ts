import {
  reportsApi,
  type UpdateReportRequest,
  type FetchReportOptions,
} from '@/api/reports'
import {
  mapReportDtoToReport,
  mapReportToItem,
  type Report,
  type ReportChart,
  type ReportItem,
  type ReportTableRow,
} from '@/entities/report'
import type { ReportSortOption } from '@/api/types'

const isReportChartData = (value: unknown): value is ReportChart => {
  return typeof value === 'object' && value !== null
}

export class ReportService {
  async fetchReports(): Promise<Report[]> {
    return (await reportsApi.fetchReports()).map(mapReportDtoToReport)
  }

  async fetchReport(id: number, options?: FetchReportOptions): Promise<Report> {
    return mapReportDtoToReport(await reportsApi.fetchReport(id, options))
  }

  async updateReport(id: number, data: UpdateReportRequest): Promise<Report> {
    return mapReportDtoToReport(await reportsApi.updateReport(id, data))
  }

  async publishReport(id: number): Promise<Report> {
    return mapReportDtoToReport(await reportsApi.publishReport(id))
  }

  async unpublishReport(id: number): Promise<Report> {
    return mapReportDtoToReport(await reportsApi.unpublishReport(id))
  }

  async deleteReport(id: number): Promise<void> {
    return await reportsApi.deleteReport(id)
  }

  /**
   * Persist the per-user report ordering for the active company. Returns the
   * backend-confirmed order so the caller can reconcile if the server
   * adjusted it. Empty array resets to the company default.
   */
  async updateReportsOrder(order: number[]): Promise<number[]> {
    const response = await reportsApi.updateReportsOrder(order)
    return response.order
  }

  transformReports(reports: Report[]): ReportItem[] {
    return reports.map(mapReportToItem)
  }

  async fetchAllReports(): Promise<ReportItem[]> {
    const reports = await this.fetchReports()
    return this.transformReports(reports)
  }

  async findReportById(id: number, options?: FetchReportOptions): Promise<ReportItem | null> {
    const report = await this.fetchReport(id, options)
    return mapReportToItem(report)
  }

  /**
   * Fetch report with filters applied
   */
  async fetchReportWithFilters(
    id: number,
    filters: Record<string, unknown>,
    page?: number,
    per_page?: number,
    sort?: ReportSortOption | null,
  ): Promise<ReportItem> {
    const report = await this.fetchReport(id, {
      page,
      per_page,
      filters,
      sort: sort ?? undefined,
    })
    return mapReportToItem(report)
  }

  /**
   * Extract table data from chart config
   */
  extractTableData(
    chartConfig: Report['config'] | Report['chart_config'] | ReportChart,
  ): ReportTableRow[] {
    if (!chartConfig) return []

    let chartData: unknown = chartConfig

    if ('demo_data' in chartConfig && chartConfig.demo_data?.chart) {
      chartData = chartConfig.demo_data.chart
    } else if ('data' in chartConfig && chartConfig.data) {
      chartData = chartConfig.data
    }

    if (!isReportChartData(chartData)) return []

    const labels = chartData.labels
    const datasets = chartData.datasets

    // Transform chart data into table rows
    return labels.map((label, index) => {
      const row: ReportTableRow = { '#': index + 1, Название: label }
      datasets.forEach((dataset) => {
        row[dataset.label] = dataset.data[index] ?? null
      })
      return row
    })
  }
}
