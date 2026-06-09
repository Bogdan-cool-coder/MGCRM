"use client";

/**
 * GridDashboard — react-grid-layout v2 обёртка для дашборда.
 * В режиме редактирования: drag, resize, toggle видимости.
 * В обычном режиме: статичная сетка без интерактивности.
 *
 * react-grid-layout v2: нет WidthProvider — используем useContainerWidth хук.
 * Layout = readonly LayoutItem[], ResponsiveLayouts для breakpoints.
 */

import "react-grid-layout/css/styles.css";
import "react-resizable/css/styles.css";

import { useMemo, useCallback } from "react";
import { ResponsiveGridLayout, useContainerWidth, verticalCompactor } from "react-grid-layout";
import type { LayoutItem, ResponsiveLayouts, Layout } from "react-grid-layout";
import { DASHBOARD_WIDGETS, type DashboardWidgetConfig, type DashboardWidgetId } from "@/lib/dashboardLayout";

const WIDGET_META = new Map(DASHBOARD_WIDGETS.map((w) => [w.id, w]));

interface Props {
  layout: DashboardWidgetConfig[];
  editMode: boolean;
  /** Вызывается при изменении layout (drag/resize) в edit mode */
  onLayoutChange: (updated: DashboardWidgetConfig[]) => void;
  /** Рендер содержимого виджета по id */
  renderWidget: (id: DashboardWidgetId) => React.ReactNode;
}

function toLayoutItems(items: DashboardWidgetConfig[]): LayoutItem[] {
  return items.map((w) => ({
    i: w.id,
    x: w.x,
    y: w.y,
    w: w.w,
    h: w.h,
    minW: WIDGET_META.get(w.id)?.minW ?? 2,
    minH: WIDGET_META.get(w.id)?.minH ?? 2,
  }));
}

function toLayoutItemsMd(items: DashboardWidgetConfig[]): LayoutItem[] {
  return items.map((w) => ({
    i: w.id,
    x: Math.min(w.x, 5),
    y: w.y,
    w: Math.min(w.w, 6),
    h: w.h,
    minW: Math.min(WIDGET_META.get(w.id)?.minW ?? 2, 6),
    minH: WIDGET_META.get(w.id)?.minH ?? 2,
  }));
}

function toLayoutItemsSm(items: DashboardWidgetConfig[]): LayoutItem[] {
  let y = 0;
  return items.map((w) => {
    const meta = WIDGET_META.get(w.id);
    const h = meta?.minH ?? w.h;
    const item: LayoutItem = { i: w.id, x: 0, y, w: 1, h };
    y += h;
    return item;
  });
}

export function GridDashboard({ layout, editMode, onLayoutChange, renderWidget }: Props) {
  const { width, containerRef } = useContainerWidth({ initialWidth: 1200, measureBeforeMount: false });

  const visibleItems = useMemo(() => layout.filter((w) => w.visible), [layout]);

  const responsiveLayouts: ResponsiveLayouts = useMemo(
    () => ({
      lg: toLayoutItems(visibleItems),
      md: toLayoutItemsMd(visibleItems),
      sm: toLayoutItemsSm(visibleItems),
    }),
    [visibleItems],
  );

  const handleLayoutChange = useCallback(
    (_currentLayout: Layout, allLayouts: ResponsiveLayouts) => {
      if (!editMode) return;
      const lgCurrent = allLayouts["lg"] ?? _currentLayout;
      const byId = new Map<string, LayoutItem>(lgCurrent.map((l) => [l.i, l]));

      const updated: DashboardWidgetConfig[] = layout.map((w) => {
        const rgl = byId.get(w.id);
        if (rgl && w.visible) {
          return { ...w, x: rgl.x, y: rgl.y, w: rgl.w, h: rgl.h };
        }
        return w;
      });
      onLayoutChange(updated);
    },
    [editMode, layout, onLayoutChange],
  );

  // React 18 useRef возвращает RefObject<T|null>, библиотека ожидает RefObject<T>.
  // Приводим тип — на рантайме одно и то же, разница только в типах.
  const divRef = containerRef as React.RefObject<HTMLDivElement>;

  return (
    <div ref={divRef}>
      <ResponsiveGridLayout
        width={width}
        className={editMode ? "rgl-edit-mode" : "rgl-view-mode"}
        layouts={responsiveLayouts}
        breakpoints={{ lg: 1200, md: 768, sm: 0 }}
        cols={{ lg: 12, md: 6, sm: 1 }}
        rowHeight={60}
        compactor={verticalCompactor}
        dragConfig={{ enabled: editMode, handle: ".rgl-drag-handle", threshold: 5, bounded: false }}
        resizeConfig={{ enabled: editMode, handles: ["se"] }}
        margin={[12, 12]}
        containerPadding={[0, 0]}
        onLayoutChange={handleLayoutChange}
      >
        {visibleItems.map((w) => (
          <div key={w.id} className="rgl-widget-wrapper">
            {editMode && (
              <div
                className="rgl-drag-handle absolute top-0 left-0 right-0 z-10 flex items-center justify-between px-3 py-1.5
                  bg-gray-100/80 dark:bg-gray-800/80 backdrop-blur-sm border-b border-gray-200/60 dark:border-gray-700/60
                  rounded-t-xl cursor-grab active:cursor-grabbing select-none"
              >
                <div className="flex items-center gap-1.5 text-xs text-gray-500 dark:text-gray-400 font-medium">
                  <i className="bi bi-grip-horizontal text-base" />
                  {WIDGET_META.get(w.id)?.label ?? w.id}
                </div>
                <button
                  type="button"
                  className="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-500 dark:text-gray-400
                    transition-colors"
                  title={w.visible ? "Скрыть виджет" : "Показать виджет"}
                  aria-label={w.visible ? "Скрыть виджет" : "Показать виджет"}
                  onMouseDown={(e) => e.stopPropagation()}
                  onClick={(e) => {
                    e.stopPropagation();
                    const ev = new CustomEvent("rgl:toggle-widget", { detail: { id: w.id } });
                    window.dispatchEvent(ev);
                  }}
                >
                  <i className={`bi ${w.visible ? "bi-eye" : "bi-eye-slash"} text-sm`} />
                </button>
              </div>
            )}
            <div className={"h-full overflow-auto " + (editMode ? "pt-8" : "")}>
              {renderWidget(w.id)}
            </div>
          </div>
        ))}
      </ResponsiveGridLayout>
    </div>
  );
}
