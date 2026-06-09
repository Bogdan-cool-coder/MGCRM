"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";
import { fetcher } from "@/lib/api";
import type { FinCalendarResponse, UserRole } from "@/lib/types";
import {
  CalendarToolbar,
  type CalendarView,
  type DirectionFilter,
} from "@/components/Finance/Calendar/CalendarToolbar";
import { MonthGrid } from "@/components/Finance/Calendar/MonthGrid";
import { WeekView } from "@/components/Finance/Calendar/WeekView";
import { ListView } from "@/components/Finance/Calendar/ListView";
import { DayDrilldown } from "@/components/Finance/Calendar/DayDrilldown";
import { addDays, addMonths, groupByDate, rangeForView } from "@/components/Finance/Calendar/helpers";

const ALLOWED_ROLES: UserRole[] = ["accountant", "cfo", "director", "admin"];

/** Skeleton сетки месяца: 7×5 ячеек */
function CalendarSkeleton() {
  return (
    <div className="card overflow-hidden animate-pulse">
      {/* День-недели */}
      <div className="grid grid-cols-7 border-b border-gray-100 dark:border-gray-800">
        {["Пн","Вт","Ср","Чт","Пт","Сб","Вс"].map((d) => (
          <div key={d} className="px-3 py-2 text-xs font-medium text-center text-gray-400 dark:text-gray-600">
            {d}
          </div>
        ))}
      </div>
      <div className="grid grid-cols-7">
        {Array.from({ length: 35 }).map((_, i) => (
          <div
            key={i}
            className="min-h-[96px] border-b border-r border-gray-100 dark:border-gray-800 p-2 space-y-1.5"
          >
            <div className="h-4 w-6 bg-gray-100 dark:bg-gray-800 rounded" />
            <div className="h-3 w-3/4 bg-gray-100 dark:bg-gray-800 rounded opacity-60" />
          </div>
        ))}
      </div>
    </div>
  );
}

export default function PaymentCalendarPage() {
  const [view, setView] = useState<CalendarView>("month");
  const [cursor, setCursor] = useState(() => new Date());
  const [direction, setDirection] = useState<DirectionFilter>("all");
  const [selectedDay, setSelectedDay] = useState<string | null>(null);

  const { from, to } = rangeForView(view, cursor);

  const swrKey = `/api/finance/calendar?date_from=${from}&date_to=${to}&direction=${direction}`;
  const { data, error, isLoading } = useSWR<FinCalendarResponse>(swrKey, fetcher);

  const events = useMemo(() => data?.events ?? [], [data]);
  const byDate = useMemo(() => groupByDate(events), [events]);
  const selectedEvents = selectedDay ? byDate.get(selectedDay) ?? [] : [];

  function shiftPeriod(dir: -1 | 1) {
    if (view === "week") {
      setCursor((c) => addDays(c, dir * 7));
    } else {
      setCursor((c) => addMonths(c, dir));
    }
  }

  return (
    <RoleGate
      allowed={ALLOWED_ROLES}
      fallback={
        <div className="p-8 text-center flex flex-col items-center gap-3">
          <i className="bi bi-lock text-4xl text-gray-300 dark:text-gray-600" />
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Платёжный календарь доступен только финансовым ролям
          </p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Платёжный календарь"
          description="Плановые и фактические поступления и списания по дням"
        />

        <div className="p-6 flex flex-col gap-4">
          <CalendarToolbar
            view={view}
            cursor={cursor}
            direction={direction}
            onViewChange={setView}
            onDirectionChange={setDirection}
            onPrev={() => shiftPeriod(-1)}
            onNext={() => shiftPeriod(1)}
            onToday={() => setCursor(new Date())}
          />

          {/* Skeleton */}
          {isLoading && <CalendarSkeleton />}

          {/* Ошибка */}
          {error && !isLoading && (
            <div className="card p-6 text-center text-sm text-danger">
              <i className="bi bi-exclamation-circle mr-1" />
              Не удалось загрузить календарь
            </div>
          )}

          {/* Пусто */}
          {!isLoading && !error && events.length === 0 && (
            <div className="card">
              <EmptyState
                icon="bi-calendar-x"
                title="Нет платежей в этом периоде"
                className="py-16"
              />
            </div>
          )}

          {/* Контент */}
          {!isLoading && !error && events.length > 0 && (
            <>
              {view === "month" && (
                <MonthGrid cursor={cursor} events={events} onSelectDay={setSelectedDay} />
              )}
              {view === "week" && (
                <WeekView cursor={cursor} events={events} onSelectDay={setSelectedDay} />
              )}
              {view === "list" && <ListView events={events} direction={direction} />}
            </>
          )}
        </div>
      </div>

      <DayDrilldown
        open={selectedDay !== null}
        date={selectedDay}
        events={selectedEvents}
        direction={direction}
        onClose={() => setSelectedDay(null)}
      />
    </RoleGate>
  );
}
