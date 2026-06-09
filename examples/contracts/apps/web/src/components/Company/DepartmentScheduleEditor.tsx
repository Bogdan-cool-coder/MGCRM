"use client";

import { useState } from "react";
import type { Department, WorkSchedule } from "@/lib/types";
import { WorkDayRow } from "./WorkDayRow";

interface Props {
  department: Department;
  schedules: WorkSchedule[];
  onChange: (updated: WorkSchedule[]) => void;
  defaultOpen?: boolean;
}

const DAYS = [1, 2, 3, 4, 5, 6, 7];

function buildDefaultSchedules(departmentId: number): WorkSchedule[] {
  return DAYS.map((day) => ({
    scope: "department" as const,
    department_id: departmentId,
    day_of_week: day,
    is_working: day <= 5, // Mon-Fri
    start_time: day <= 5 ? "09:00" : null,
    end_time: day <= 5 ? "18:00" : null,
    meeting_slot_min: day <= 5 ? 30 : null,
  }));
}

export function DepartmentScheduleEditor({ department, schedules, onChange, defaultOpen = false }: Props) {
  const [expanded, setExpanded] = useState(defaultOpen);

  // Ensure we have all 7 days, filling gaps with defaults
  const fullSchedules: WorkSchedule[] = DAYS.map((day) => {
    const found = schedules.find((s) => s.day_of_week === day && s.department_id === department.id);
    if (found) return found;
    const def = buildDefaultSchedules(department.id).find((s) => s.day_of_week === day);
    return def!;
  });

  function handleDayChange(updated: WorkSchedule) {
    const next = fullSchedules.map((s) =>
      s.day_of_week === updated.day_of_week ? updated : s
    );
    onChange(next);
  }

  return (
    <div className="rounded-2xl border border-gray-100 dark:border-gray-700 shadow-elev-1 overflow-hidden bg-white dark:bg-gray-900">
      {/* Accordion header */}
      <button
        type="button"
        className="flex items-center gap-2 w-full py-3.5 px-4 font-semibold text-primary hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors"
        onClick={() => setExpanded(!expanded)}
        aria-expanded={expanded}
      >
        <i
          className={[
            "bi text-sm transition-transform duration-200",
            expanded ? "bi-chevron-down" : "bi-chevron-right",
          ].join(" ")}
        />
        {department.name}
      </button>

      {expanded && (
        <div className="border-t border-gray-100 dark:border-gray-700 overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-800/50">
              <tr>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-32">День</th>
                <th className="text-center px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Рабочий</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Начало</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Конец</th>
                <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Слот (мин)</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
              {fullSchedules.map((s) => (
                <WorkDayRow
                  key={s.day_of_week}
                  schedule={s}
                  onChange={handleDayChange}
                />
              ))}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
