import { widgetsApi } from '@/api/widgets'
import {
  mapWidgetDataDtoToData,
  mapWidgetDtoToWidget,
  mapWidgetListItemDtoToItem,
} from '@/entities/widget'
import type { Widget, WidgetData, WidgetListItem } from '@/entities/widget'
import type {
  CreateWidgetRequest,
  PreviewWidgetRequest,
  UpdateWidgetRequest,
} from '@/api/types/widgets'
import type { PeriodRange } from '@/api/types/dashboards'
import type { WidgetConfigDto } from '@/entities/widget'

export class WidgetService {
  async fetchAllWidgets(): Promise<WidgetListItem[]> {
    return (await widgetsApi.fetchWidgets()).map(mapWidgetListItemDtoToItem)
  }

  async fetchWidget(id: number): Promise<Widget> {
    return mapWidgetDtoToWidget(await widgetsApi.fetchWidget(id))
  }

  async createWidget(data: CreateWidgetRequest): Promise<Widget> {
    return mapWidgetDtoToWidget(await widgetsApi.createWidget(data))
  }

  async updateWidget(id: number, data: UpdateWidgetRequest): Promise<Widget> {
    return mapWidgetDtoToWidget(await widgetsApi.updateWidget(id, data))
  }

  async deleteWidget(id: number, options?: { force?: boolean }): Promise<void> {
    await widgetsApi.deleteWidget(id, options)
  }

  async publishWidget(id: number): Promise<Widget> {
    return mapWidgetDtoToWidget(await widgetsApi.publishWidget(id))
  }

  async unpublishWidget(id: number): Promise<Widget> {
    return mapWidgetDtoToWidget(await widgetsApi.unpublishWidget(id))
  }

  async fetchWidgetData(id: number, range?: PeriodRange): Promise<WidgetData> {
    return mapWidgetDataDtoToData(await widgetsApi.fetchWidgetData(id, range))
  }

  /**
   * Computes chart-ready data for an unsaved config (variant preview cards in
   * the widget-generation modal). Never persists a widget.
   */
  async previewWidget(config: WidgetConfigDto, range?: PeriodRange): Promise<WidgetData> {
    const payload: PreviewWidgetRequest = range
      ? { config, period_from: range.from, period_to: range.to }
      : { config }
    return mapWidgetDataDtoToData(await widgetsApi.previewWidget(payload))
  }
}
