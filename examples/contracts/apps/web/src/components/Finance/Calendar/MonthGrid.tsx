"use client";

import type { FinCalendarEvent } from "@/lib/types";
import { EventChip } from "./EventChip";
import {
  WEEKDAY_SHORT,
  groupByDate,
  isSameDay,
  monthGridDays,
  toISODate,
} from "./helpers";

interface Props {
  cursor: Date;
  events: FinCalendarEvent[];
  onSelectDay: (iso: string) => void;
}

const MAX_CHIPS = 3;

export function MonthGrid({ cursor, events, onSelectDay }: Props) {
  const days = monthGridDays(cursor);
  const byDate = groupByDate(events);
  const today = new Date();
  const curMonth = cursor.getMonth();

  return (
    <div className="card overflow-hidden">
      {/* Заголовки дней недели */}
      <div className="grid grid-cols-7 border-b border-gray-200 dark:border-gray-700">
        {WEEKDAY_SHORT.map((w) => (
          <div
            key={w}
            className="px-2 py-1.5 text-center text-xs font-semibold text-gray-500 dark:text-gray-400"
          >
            {w}
          </div>
        ))}
      </div>

      <div className="grid grid-cols-7">
        {days.map((d) => {
          const iso = toISODate(d);
          const dayEvents = byDate.get(iso) ?? [];
          const outOfMonth = d.getMonth() !== curMonth;
          const isToday = isSameDay(d, today);
          const extra = dayEvents.length - MAX_CHIPS;

          return (
            <button
              key={iso}
              type="button"
              onClick={() => onSelectDay(iso)}
              className={
                "min-h-[96px] border-b border-r border-gray-100 dark:border-gray-700 p-1.5 flex flex-col gap-1 text-left align-top transition-colors hover:bg-gray-50 dark:hover:bg-gray-700/40 " +
                (outOfMonth ? "bg-gray-50/50 dark:bg-gray-900/30" : "")
              }
            >
              <div className="flex items-center justify-between">
                <span
                  className={
                    "inline-flex items-center justify-center text-xs h-5 min-w-[20px] px-1 rounded-full " +
                    (isToday
                      ? "bg-primary text-white font-semibold"
                      : outOfMonth
                        ? "text-gray-300 dark:text-gray-600"
                        : "text-gray-600 dark:text-gray-300")
                  }
                >
                  {d.getDate()}
                </span>
                {dayEvents.length > 0 && (
                  <span className="text-[10px] text-gray-400 dark:text-gray-500">
                    {dayEvents.length}
                  </span>
                )}
              </div>

              <div className="flex flex-col gap-0.5">
                {dayEvents.slice(0, MAX_CHIPS).map((e, idx) => (
                  <EventChip
                    key={`${e.source_type}-${e.source_id}-${idx}`}
                    event={e}
                    onClick={() => onSelectDay(iso)}
                  />
                ))}
                {extra > 0 && (
                  <span className="text-[10px] text-gray-400 dark:text-gray-500 px-1">
                    +{extra} ещё
                  </span>
                )}
              </div>
            </button>
          );
        })}
      </div>
    </div>
  );
}
