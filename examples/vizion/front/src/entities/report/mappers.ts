import type { ReportDto } from '@/api/types/reports'
import { isReportFiltersApplied, isReportFiltersAvailable } from './filters'
import type { Report } from './types'

export const mapReportDtoToReport = (reportDto: ReportDto): Report => {
  return {
    ...reportDto,
    filters_available: isReportFiltersAvailable(reportDto.filters_available)
      ? reportDto.filters_available
      : undefined,
    filters_applied: isReportFiltersApplied(reportDto.filters_applied)
      ? reportDto.filters_applied
      : undefined,
  }
}
