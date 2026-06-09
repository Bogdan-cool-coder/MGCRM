"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { UserSelect } from "@/components/UserSelect";
import { DatePicker } from "@/components/ui/DatePicker";
import type { Department, TaskCategory } from "@/lib/types";

export interface TaskFilters {
  statuses: string[];
  priorities: string[];
  categoryIds: string[];
  responsibleId: string;
  createdById: string;
  departmentId: string;
  dueFrom: string;
  dueTo: string;
  myTasks: boolean;
  myOrders: boolean;
  includeClosed: boolean;
}

interface Props {
  open: boolean;
  filters: TaskFilters;
  onChange: (filters: TaskFilters) => void;
  onClose: () => void;
}

const STATUSES = [
  { value: "new",         label: "Новая" },
  { value: "in_progress", label: "В работе" },
  { value: "done",        label: "Выполнена" },
  { value: "rejected",    label: "Отклонена" },
];

const PRIORITIES = [
  { value: "critical", label: "Критический" },
  { value: "high",     label: "Высокий" },
  { value: "normal",   label: "Нормальный" },
  { value: "low",      label: "Низкий" },
];

export function TaskFiltersPanel({ open, filters, onChange, onClose }: Props) {
  const { data: categories } = useSWR<TaskCategory[]>("/task-categories", fetcher);
  const { data: departments } = useSWR<Department[]>("/departments", fetcher);

  function toggleMulti(field: "statuses" | "priorities" | "categoryIds", value: string) {
    const current = filters[field];
    const next = current.includes(value)
      ? current.filter((v) => v !== value)
      : [...current, value];
    onChange({ ...filters, [field]: next });
  }

  function resetFilters() {
    onChange({
      statuses: [],
      priorities: [],
      categoryIds: [],
      responsibleId: "",
      createdById: "",
      departmentId: "",
      dueFrom: "",
      dueTo: "",
      myTasks: false,
      myOrders: false,
      includeClosed: false,
    });
  }

  if (!open) return null;

  return (
    <div className="border-b border-gray-100 dark:border-gray-700 bg-gray-50 dark:bg-gray-800/60 px-4 py-3">
      {/* Компактная горизонтальная сетка фильтров */}
      <div className="flex flex-wrap gap-x-6 gap-y-3">

        {/* Статус */}
        <div className="flex flex-col gap-1.5 min-w-[160px]">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Статус
          </span>
          <div className="flex flex-wrap gap-1">
            {STATUSES.map((s) => {
              const active = filters.statuses.includes(s.value);
              return (
                <button
                  key={s.value}
                  onClick={() => toggleMulti("statuses", s.value)}
                  className={
                    "text-[11px] px-2 py-0.5 rounded border transition-colors " +
                    (active
                      ? "bg-primary text-white border-primary"
                      : "border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:border-primary hover:text-primary")
                  }
                >
                  {s.label}
                </button>
              );
            })}
          </div>
        </div>

        {/* Приоритет */}
        <div className="flex flex-col gap-1.5 min-w-[160px]">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Приоритет
          </span>
          <div className="flex flex-wrap gap-1">
            {PRIORITIES.map((p) => {
              const active = filters.priorities.includes(p.value);
              return (
                <button
                  key={p.value}
                  onClick={() => toggleMulti("priorities", p.value)}
                  className={
                    "text-[11px] px-2 py-0.5 rounded border transition-colors " +
                    (active
                      ? "bg-primary text-white border-primary"
                      : "border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:border-primary hover:text-primary")
                  }
                >
                  {p.label}
                </button>
              );
            })}
          </div>
        </div>

        {/* Категории */}
        {categories && categories.length > 0 && (
          <div className="flex flex-col gap-1.5 min-w-[160px]">
            <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
              Категории
            </span>
            <div className="flex flex-wrap gap-1">
              {categories.map((c) => {
                const active = filters.categoryIds.includes(String(c.id));
                return (
                  <button
                    key={c.id}
                    onClick={() => toggleMulti("categoryIds", String(c.id))}
                    className={
                      "text-[11px] px-2 py-0.5 rounded border transition-colors " +
                      (active
                        ? "bg-primary text-white border-primary"
                        : "border-gray-300 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:border-primary hover:text-primary")
                    }
                  >
                    {c.name}
                  </button>
                );
              })}
            </div>
          </div>
        )}

        {/* Исполнитель */}
        <div className="flex flex-col gap-1.5 min-w-[180px] max-w-[220px]">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Исполнитель
          </span>
          <UserSelect
            value={filters.responsibleId}
            onChange={(v) => onChange({ ...filters, responsibleId: v })}
            placeholder="Любой"
          />
        </div>

        {/* Постановщик */}
        <div className="flex flex-col gap-1.5 min-w-[180px] max-w-[220px]">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Постановщик
          </span>
          <UserSelect
            value={filters.createdById}
            onChange={(v) => onChange({ ...filters, createdById: v })}
            placeholder="Любой"
          />
        </div>

        {/* Отдел */}
        <div className="flex flex-col gap-1.5 min-w-[160px] max-w-[200px]">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Отдел
          </span>
          <select
            className="input text-xs py-1"
            value={filters.departmentId}
            onChange={(e) => onChange({ ...filters, departmentId: e.target.value })}
          >
            <option value="">Любой</option>
            {departments?.map((d) => (
              <option key={d.id} value={String(d.id)}>{d.name}</option>
            ))}
          </select>
        </div>

        {/* Период дедлайна */}
        <div className="flex flex-col gap-1.5 min-w-[200px]">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Дедлайн
          </span>
          <div className="flex items-center gap-1.5">
            <DatePicker
              value={filters.dueFrom || null}
              onChange={(v) => onChange({ ...filters, dueFrom: v ?? "" })}
              placeholder="От"
              className="flex-1 min-w-0"
            />
            <span className="text-gray-400 text-xs shrink-0">—</span>
            <DatePicker
              value={filters.dueTo || null}
              onChange={(v) => onChange({ ...filters, dueTo: v ?? "" })}
              placeholder="До"
              className="flex-1 min-w-0"
            />
          </div>
        </div>

        {/* Флаги */}
        <div className="flex flex-col gap-1.5 justify-end">
          <span className="text-[10px] font-semibold uppercase tracking-wider text-gray-400 dark:text-gray-500">
            Опции
          </span>
          <div className="flex flex-wrap items-center gap-3">
            <label className="flex items-center gap-1.5 cursor-pointer text-xs text-gray-700 dark:text-gray-300">
              <input
                type="checkbox"
                checked={filters.includeClosed}
                onChange={(e) => onChange({ ...filters, includeClosed: e.target.checked })}
                className="w-3.5 h-3.5"
              />
              Включить закрытые
            </label>
          </div>
        </div>
      </div>

      {/* Footer: сброс + закрыть */}
      <div className="flex items-center gap-3 mt-3 pt-2.5 border-t border-gray-200 dark:border-gray-700">
        <button onClick={resetFilters} className="btn-ghost text-xs py-1 px-2">
          <i className="bi bi-x-circle mr-1" />
          Сбросить
        </button>
        <button onClick={onClose} className="btn-ghost text-xs py-1 px-2 ml-auto text-gray-400">
          <i className="bi bi-chevron-up mr-1" />
          Свернуть
        </button>
      </div>
    </div>
  );
}
