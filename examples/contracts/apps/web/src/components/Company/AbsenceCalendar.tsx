"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { EmptyState } from "@/components/EmptyState";
import type { CalendarVacation, VacationType } from "@/lib/types";

const TYPE_CLASSES: Record<VacationType, string> = {
  vacation: "bg-info/30 border border-info/50",
  sick_leave: "bg-warning/30 border border-warning/50",
  day_off: "bg-gray-200 border border-gray-300 dark:bg-gray-600 dark:border-gray-500",
  business_trip: "bg-primary/20 border border-primary/40",
};

function daysInMonth(year: number, month: number): number {
  return new Date(year, month + 1, 0).getDate();
}

function toDateStr(year: number, month: number, day: number): string {
  return `${year}-${String(month + 1).padStart(2, "0")}-${String(day).padStart(2, "0")}`;
}

function isoToDateStr(iso: string): string {
  return iso.slice(0, 10);
}

interface AbsenceRow {
  userId: number;
  userName: string;
  vacations: CalendarVacation[];
}

export function AbsenceCalendar() {
  const today = new Date();
  const [year, setYear] = useState(today.getFullYear());
  const [month, setMonth] = useState(today.getMonth()); // 0-based
  const [departmentId, setDepartmentId] = useState("");

  const from = toDateStr(year, month, 1);
  const lastDay = daysInMonth(year, month);
  const to = toDateStr(year, month, lastDay);

  const queryStr = departmentId ? `?from=${from}&to=${to}&department_id=${departmentId}` : `?from=${from}&to=${to}`;
  const { data, error, isLoading } = useSWR<CalendarVacation[]>(
    `/admin/vacations/calendar${queryStr}`,
    fetcher
  );

  const { data: departments } = useSWR("/departments", fetcher);

  // Group by user
  const rows = useMemo<AbsenceRow[]>(() => {
    if (!data) return [];
    const map = new Map<number, AbsenceRow>();
    for (const v of data) {
      if (!map.has(v.user_id)) {
        map.set(v.user_id, { userId: v.user_id, userName: v.user_name ?? `#${v.user_id}`, vacations: [] });
      }
      map.get(v.user_id)!.vacations.push(v);
    }
    return Array.from(map.values());
  }, [data]);

  const days = Array.from({ length: lastDay }, (_, i) => i + 1);

  function prevMonth() {
    if (month === 0) { setMonth(11); setYear(y => y - 1); }
    else setMonth(m => m - 1);
  }
  function nextMonth() {
    if (month === 11) { setMonth(0); setYear(y => y + 1); }
    else setMonth(m => m + 1);
  }
  function goToday() {
    setYear(today.getFullYear());
    setMonth(today.getMonth());
  }

  const MONTH_LABELS = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
    "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];

  function isDayWeekend(day: number): boolean {
    const d = new Date(year, month, day);
    return d.getDay() === 0 || d.getDay() === 6;
  }

  function isDayToday(day: number): boolean {
    return today.getFullYear() === year && today.getMonth() === month && today.getDate() === day;
  }

  // Check if a vacation covers a specific day
  function getVacationForDay(vacations: CalendarVacation[], day: number): CalendarVacation | null {
    const dayStr = toDateStr(year, month, day);
    return vacations.find((v) => isoToDateStr(v.start_date) <= dayStr && dayStr <= isoToDateStr(v.end_date)) ?? null;
  }

  return (
    <div>
      {/* Controls */}
      <div className="flex flex-wrap items-center gap-3 mb-4">
        <select
          className="input w-48"
          value={departmentId}
          onChange={(e) => setDepartmentId(e.target.value)}
        >
          <option value="">Все отделы</option>
          {Array.isArray(departments) && departments.map((d: { id: number; name: string }) => (
            <option key={d.id} value={String(d.id)}>{d.name}</option>
          ))}
        </select>
        <div className="flex items-center gap-1">
          <button className="btn-ghost text-sm" onClick={prevMonth}>
            <i className="bi bi-chevron-left" />
          </button>
          <span className="text-sm font-medium px-2 min-w-[140px] text-center">
            {MONTH_LABELS[month]} {year}
          </span>
          <button className="btn-ghost text-sm" onClick={nextMonth}>
            <i className="bi bi-chevron-right" />
          </button>
        </div>
        <button className="btn-ghost text-sm" onClick={goToday}>Сегодня</button>
      </div>

      {error && (
        <div className="flex items-center gap-2 text-danger text-sm mb-3 rounded-lg border border-danger/20 bg-danger/5 px-4 py-2.5">
          <i className="bi bi-exclamation-triangle-fill shrink-0" />
          Не удалось загрузить календарь отсутствий
        </div>
      )}

      {isLoading && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden">
          <div className="space-y-2 p-4">
            {[1, 2, 3, 4].map((i) => (
              <div
                key={i}
                className="animate-pulse rounded-md bg-gray-100 dark:bg-gray-800"
                style={{ height: "32px" }}
              />
            ))}
          </div>
        </div>
      )}

      {!isLoading && !error && rows.length === 0 && (
        <div className="card rounded-2xl shadow-elev-1 p-8">
          <EmptyState
            icon="bi-calendar-x"
            title="В этом периоде отсутствий нет"
            description="Отпуска, больничные и командировки появятся здесь"
          />
        </div>
      )}

      {!isLoading && rows.length > 0 && (
        <div className="card rounded-2xl shadow-elev-1 overflow-x-auto">
          <div className="min-w-max">
            {/* Header: day numbers */}
            <div className="flex border-b border-gray-100 dark:border-gray-700">
              <div className="w-40 shrink-0 px-3 py-2 text-xs font-semibold text-gray-500">Сотрудник</div>
              {days.map((day) => (
                <div
                  key={day}
                  className={[
                    "w-8 shrink-0 text-center py-2 text-xs",
                    isDayToday(day) ? "bg-primary/10 font-bold text-primary" : "",
                    isDayWeekend(day) ? "bg-gray-50 dark:bg-gray-700/30 text-gray-400" : "text-gray-500",
                  ].join(" ")}
                >
                  {day}
                </div>
              ))}
            </div>

            {/* Rows */}
            {rows.map((row) => (
              <div key={row.userId} className="flex border-b border-gray-50 dark:border-gray-700/50 hover:bg-gray-50/50 dark:hover:bg-gray-700/10">
                <div className="w-40 shrink-0 px-3 py-2 text-sm font-medium text-gray-700 dark:text-gray-300 truncate">
                  {row.userName}
                </div>
                {days.map((day) => {
                  const vac = getVacationForDay(row.vacations, day);
                  return (
                    <div
                      key={day}
                      className={[
                        "w-8 shrink-0 h-8 relative",
                        isDayToday(day) ? "bg-primary/5" : "",
                        isDayWeekend(day) ? "bg-gray-50 dark:bg-gray-700/20" : "",
                      ].join(" ")}
                    >
                      {vac && (
                        <div
                          className={`absolute inset-0.5 rounded text-xs font-medium flex items-center px-0.5 truncate cursor-pointer ${TYPE_CLASSES[vac.vacation_type]}`}
                          title={`${row.userName} — ${vac.vacation_type} (${vac.start_date} — ${vac.end_date})${vac.substitute_name ? ` → ${vac.substitute_name}` : ""}`}
                        />
                      )}
                    </div>
                  );
                })}
              </div>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
