"use client";

import { CalendarLegend } from "./CalendarLegend";
import { monthTitle, weekTitle } from "./helpers";

export type CalendarView = "month" | "week" | "list";
export type DirectionFilter = "all" | "in" | "out";

interface Props {
  view: CalendarView;
  cursor: Date;
  direction: DirectionFilter;
  onViewChange: (v: CalendarView) => void;
  onDirectionChange: (d: DirectionFilter) => void;
  onPrev: () => void;
  onNext: () => void;
  onToday: () => void;
}

const VIEW_LABELS: Record<CalendarView, string> = {
  month: "Месяц",
  week: "Неделя",
  list: "Список",
};

export function CalendarToolbar({
  view,
  cursor,
  direction,
  onViewChange,
  onDirectionChange,
  onPrev,
  onNext,
  onToday,
}: Props) {
  const title = view === "week" ? weekTitle(cursor) : monthTitle(cursor);

  return (
    <div className="flex flex-col gap-3">
      <div className="flex items-center justify-between flex-wrap gap-3">
        {/* Навигация по периоду */}
        <div className="flex items-center gap-2">
          <button
            type="button"
            onClick={onPrev}
            aria-label="Назад"
            className="btn-ghost h-8 w-8 p-0 flex items-center justify-center"
          >
            <i className="bi bi-chevron-left" />
          </button>
          <button
            type="button"
            onClick={onNext}
            aria-label="Вперёд"
            className="btn-ghost h-8 w-8 p-0 flex items-center justify-center"
          >
            <i className="bi bi-chevron-right" />
          </button>
          <button type="button" onClick={onToday} className="btn-secondary text-sm">
            Сегодня
          </button>
          <span className="text-sm font-semibold text-gray-900 dark:text-gray-100 ml-1 capitalize">
            {title}
          </span>
        </div>

        <div className="flex items-center gap-2 flex-wrap">
          {/* Сегментный переключатель вида */}
          <div className="inline-flex rounded-md border border-gray-300 dark:border-gray-600 overflow-hidden">
            {(["month", "week", "list"] as const).map((v) => (
              <button
                key={v}
                type="button"
                onClick={() => onViewChange(v)}
                className={
                  "px-3 py-1.5 text-sm transition-colors " +
                  (view === v
                    ? "bg-primary text-white"
                    : "bg-white dark:bg-gray-800 text-gray-600 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700")
                }
              >
                {VIEW_LABELS[v]}
              </button>
            ))}
          </div>

          {/* Фильтр направления */}
          <select
            className="input text-sm h-8 py-0 max-w-[160px]"
            value={direction}
            onChange={(e) => onDirectionChange(e.target.value as DirectionFilter)}
          >
            <option value="all">Все платежи</option>
            <option value="in">Только поступления</option>
            <option value="out">Только списания</option>
          </select>
        </div>
      </div>

      <CalendarLegend />
    </div>
  );
}
