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

// «Мои задачи» и «Мои поручения» объединены под одним чипом «Мои»
// Пользователь выбирает режим через выпадающий мини-селект при нажатии
const PRESETS: PresetConfig[] = [
  { key: "overdue",      label: "Просроченные",       icon: "bi-clock-history", dotColor: "text-danger" },
  { key: "today",        label: "Сегодня",             icon: "bi-calendar-day" },
  { key: "this_week",    label: "Эта неделя",          icon: "bi-calendar-week" },
  { key: "next_week",    label: "Следующая",           icon: "bi-calendar-range" },
  { key: "future",       label: "Позже",               icon: "bi-calendar2-plus" },
  { key: "pinned",       label: "Закреплённые",        icon: "bi-pin-fill", dotColor: "text-orange-400" },
  { key: "done_unclosed",label: "Готово/не закрыто",   icon: "bi-check2-all", dotColor: "text-success" },
  { key: "all",          label: "Все задачи",          icon: "bi-list-task" },
];

// Объединённая группа «Мои»
const MY_PRESETS: { key: TaskPreset; label: string }[] = [
  { key: "mine",      label: "Мои задачи" },
  { key: "my_orders", label: "Мои поручения" },
];

interface Props {
  active: TaskPreset;
  onChange: (preset: TaskPreset) => void;
}

export function TaskPresetBar({ active, onChange }: Props) {
  const { data: counts } = useSWR<PresetCounts>(
    "/activities/counts-by-preset",
    fetcher,
    { refreshInterval: 30_000 }
  );

  function getCount(key: TaskPreset): number | undefined {
    if (!counts || key === "all") return undefined;
    return counts[key as keyof PresetCounts];
  }

  const isMyActive = active === "mine" || active === "my_orders";
  const myCount =
    counts != null
      ? (counts.mine ?? 0) + (counts.my_orders ?? 0)
      : undefined;

  return (
    <div className="flex items-center gap-1.5 px-4 py-2.5 border-b border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800 overflow-x-auto scrollbar-none">
      {/* Объединённый чип «Мои» с inline-select */}
      <div className="relative shrink-0">
        {isMyActive ? (
          <select
            className={
              "appearance-none text-xs font-medium px-3 py-1.5 rounded-full border cursor-pointer " +
              "bg-primary text-white border-primary pr-6"
            }
            value={active}
            onChange={(e) => onChange(e.target.value as TaskPreset)}
            title="Выбрать вид"
          >
            {MY_PRESETS.map((p) => (
              <option key={p.key} value={p.key} className="text-gray-900">
                {p.label}
              </option>
            ))}
          </select>
        ) : (
          <button
            onClick={() => onChange("mine")}
            className={
              "flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full border transition-colors shrink-0 " +
              "border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 " +
              "hover:border-primary hover:text-primary dark:hover:text-primary"
            }
          >
            <i className="bi bi-person-check" />
            <span>Мои</span>
            {myCount != null && myCount > 0 && (
              <span className="bg-primary text-white text-[10px] font-bold rounded-full px-1 min-w-[16px] text-center">
                {myCount}
              </span>
            )}
          </button>
        )}
      </div>

      {/* Разделитель */}
      <div className="h-4 w-px bg-gray-200 dark:bg-gray-600 shrink-0" />

      {/* Остальные пресеты */}
      {PRESETS.map((p) => {
        const isActive = active === p.key;
        const count = getCount(p.key);
        return (
          <button
            key={p.key}
            onClick={() => onChange(p.key)}
            className={
              "flex items-center gap-1.5 text-xs font-medium px-3 py-1.5 rounded-full border transition-colors shrink-0 " +
              (isActive
                ? "bg-primary text-white border-primary"
                : "border-gray-300 dark:border-gray-600 text-gray-700 dark:text-gray-300 " +
                  "hover:border-primary hover:text-primary dark:hover:text-primary")
            }
          >
            <i
              className={
                `bi ${p.icon} ` +
                (isActive ? "" : (p.dotColor ?? ""))
              }
            />
            <span>{p.label}</span>
            {count != null && count > 0 && (
              <span
                className={
                  "text-[10px] font-bold tabular-nums rounded-full px-1 min-w-[16px] text-center " +
                  (isActive
                    ? "bg-white/25 text-white"
                    : "bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400")
                }
              >
                {count}
              </span>
            )}
          </button>
        );
      })}
    </div>
  );
}
