"use client";

import type { FinCalendarEvent } from "@/lib/types";
import { EventChip } from "./EventChip";
import {
  WEEKDAY_SHORT,
  groupByDate,
  isSameDay,
  toISODate,
  weekDays,
} from "./helpers";

interface Props {
  cursor: Date;
  events: FinCalendarEvent[];
  onSelectDay: (iso: string) => void;
}

export function WeekView({ cursor, events, onSelectDay }: Props) {
  const days = weekDays(cursor);
  const byDate = groupByDate(events);
  const today = new Date();

  return (
    <div className="card overflow-hidden">
      <div className="grid grid-cols-7">
        {days.map((d, i) => {
          const iso = toISODate(d);
          const dayEvents = byDate.get(iso) ?? [];
          const isToday = isSameDay(d, today);

          return (
            <div
              key={iso}
              className="min-h-[320px] border-r border-gray-100 dark:border-gray-700 last:border-r-0 flex flex-col"
            >
              <button
                type="button"
                onClick={() => onSelectDay(iso)}
                className="px-2 py-2 border-b border-gray-100 dark:border-gray-700 text-left hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors"
              >
                <div className="text-xs font-semibold text-gray-500 dark:text-gray-400">
                  {WEEKDAY_SHORT[i]}
                </div>
                <div
                  className={
                    "text-sm mt-0.5 " +
                    (isToday
                      ? "inline-flex items-center justify-center h-6 w-6 rounded-full bg-primary text-white font-semibold"
                      : "text-gray-700 dark:text-gray-200")
                  }
                >
                  {d.getDate()}
                </div>
              </button>
              <div className="flex flex-col gap-1 p-1.5 flex-1">
                {dayEvents.map((e, idx) => (
                  <EventChip
                    key={`${e.source_type}-${e.source_id}-${idx}`}
                    event={e}
                    onClick={() => onSelectDay(iso)}
                  />
                ))}
                {dayEvents.length === 0 && (
                  <span className="text-[10px] text-gray-300 dark:text-gray-600 px-1 mt-1">—</span>
                )}
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
