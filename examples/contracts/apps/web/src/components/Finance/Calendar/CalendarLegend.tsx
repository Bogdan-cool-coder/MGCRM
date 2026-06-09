"use client";

export function CalendarLegend() {
  return (
    <div className="flex items-center flex-wrap gap-x-4 gap-y-1 text-xs text-gray-500 dark:text-gray-400">
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2.5 w-2.5 rounded-sm bg-green-100 dark:bg-green-900/40 ring-1 ring-green-400" />
        Поступление (+)
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2.5 w-2.5 rounded-sm bg-red-100 dark:bg-red-900/40 ring-1 ring-red-400" />
        Списание (−)
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2.5 w-2.5 rounded-sm ring-1 ring-warning" />
        Просрочено
      </span>
      <span className="inline-flex items-center gap-1.5">
        <span className="inline-block h-2.5 w-2.5 rounded-sm bg-gray-300 dark:bg-gray-600 opacity-60" />
        Оплачено
      </span>
    </div>
  );
}
