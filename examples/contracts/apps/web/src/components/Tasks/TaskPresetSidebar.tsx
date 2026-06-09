"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";

export type TaskPreset =
  | "pinned"
  | "overdue"
  | "done_unclosed"
  | "today"
  | "this_week"
  | "next_week"
  | "future"
  | "mine"
  | "my_orders"
  | "all";

interface PresetCounts {
  pinned: number;
  overdue: number;
  done_unclosed: number;
  today: number;
  this_week: number;
  next_week: number;
  future: number;
  mine: number;
  my_orders: number;
}

interface PresetConfig {
  key: TaskPreset;
  label: string;
  icon: string;
  dotColor?: string;
}

const PRESETS: PresetConfig[] = [
  { key: "pinned", label: "Закреплённые", icon: "bi-pin-fill", dotColor: "bg-orange-400" },
  { key: "overdue", label: "Просроченные", icon: "bi-clock-history", dotColor: "bg-danger" },
  { key: "done_unclosed", label: "Готово, не закрыто", icon: "bi-check2-all", dotColor: "bg-success" },
  { key: "today", label: "Сегодня", icon: "bi-calendar-day" },
  { key: "this_week", label: "Эта неделя", icon: "bi-calendar-week" },
  { key: "next_week", label: "Следующая неделя", icon: "bi-calendar-range" },
  { key: "future", label: "Будущие", icon: "bi-calendar2-plus" },
  { key: "mine", label: "Мои задачи", icon: "bi-person-check" },
  { key: "my_orders", label: "Мои поручения", icon: "bi-person-up" },
  { key: "all", label: "Все задачи", icon: "bi-list-task" },
];

interface Props {
  active: TaskPreset;
  onChange: (preset: TaskPreset) => void;
}

export function TaskPresetSidebar({ active, onChange }: Props) {
  const { data: counts } = useSWR<PresetCounts>(
    "/activities/counts-by-preset",
    fetcher,
    { refreshInterval: 30_000 }
  );

  function getCount(key: TaskPreset): number | undefined {
    if (!counts || key === "all") return undefined;
    return counts[key as keyof PresetCounts];
  }

  return (
    <div className="w-[240px] shrink-0 border-r border-gray-200 dark:border-gray-700 p-3 space-y-0.5 overflow-y-auto">
      {PRESETS.map((p) => {
        const isActive = active === p.key;
        const count = getCount(p.key);
        return (
          <button
            key={p.key}
            onClick={() => onChange(p.key)}
            className={
              "flex items-center gap-2 w-full px-3 py-2 rounded-md text-sm transition-colors " +
              (isActive
                ? "bg-primary text-white"
                : "text-gray-700 dark:text-gray-300 hover:bg-gray-100 dark:hover:bg-gray-700")
            }
          >
            <i className={`bi ${p.icon} text-sm shrink-0`} />
            <span className="flex-1 text-left">{p.label}</span>
            {count != null && count > 0 && (
              <span className="text-[10px] font-bold tabular-nums">{count}</span>
            )}
            {p.dotColor && count != null && count > 0 && (
              <span className={`w-2 h-2 rounded-full ${p.dotColor} shrink-0`} />
            )}
          </button>
        );
      })}
    </div>
  );
}
