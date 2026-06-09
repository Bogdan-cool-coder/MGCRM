"use client";

import { useState } from "react";
import { UserSelect } from "@/components/UserSelect";

export interface DealFilters {
  q: string;
  owner_id: string;
  city: string;
  country: string;
  source: string;
  product: string;
  tag: string;
  task_kind: string;
  no_tasks: boolean;
  amount_from: string;
  amount_to: string;
  show_lost: boolean;
  show_cold: boolean;
  stage_ids: string[];
}

export const DEFAULT_DEAL_FILTERS: DealFilters = {
  q: "",
  owner_id: "",
  city: "",
  country: "",
  source: "",
  product: "",
  tag: "",
  task_kind: "",
  no_tasks: false,
  amount_from: "",
  amount_to: "",
  show_lost: false,
  show_cold: false,
  stage_ids: [],
};

/** Считает активные фильтры, кроме поиска (q выведен в тулбар) */
function countActive(f: DealFilters): number {
  let n = 0;
  if (f.owner_id) n++;
  if (f.city) n++;
  if (f.country) n++;
  if (f.source) n++;
  if (f.product) n++;
  if (f.tag) n++;
  if (f.task_kind) n++;
  if (f.no_tasks) n++;
  if (f.amount_from || f.amount_to) n++;
  if (f.show_lost) n++;
  if (f.show_cold) n++;
  if (f.stage_ids.length) n++;
  return n;
}

/** Активные фильтры без owner_id и city (они в «Ещё фильтры») */
function countVisibleActive(f: DealFilters): number {
  let n = 0;
  if (f.country) n++;
  if (f.source) n++;
  if (f.product) n++;
  if (f.tag) n++;
  if (f.task_kind) n++;
  if (f.no_tasks) n++;
  if (f.amount_from || f.amount_to) n++;
  if (f.owner_id) n++;
  if (f.city) n++;
  if (f.stage_ids.length) n++;
  return n;
}

interface Props {
  filters: DealFilters;
  onChange: (f: DealFilters) => void;
  /** Если true — поиск уже вынесен наружу (в тулбар страницы) и здесь не рендерится */
  hideSearch?: boolean;
}

const TASK_KIND_OPTIONS = [
  { value: "", label: "Любой" },
  { value: "call", label: "Звонок" },
  { value: "meeting", label: "Встреча" },
  { value: "task", label: "Задача" },
  { value: "note", label: "Заметка" },
];

const COUNTRY_OPTIONS = [
  { value: "", label: "Любая страна" },
  { value: "kz", label: "Казахстан" },
  { value: "uz", label: "Узбекистан" },
  { value: "ru", label: "Россия" },
  { value: "by", label: "Беларусь" },
];

const SOURCE_OPTIONS = [
  { value: "", label: "Любой источник" },
  { value: "own_contact", label: "Свой контакт" },
  { value: "cold_call", label: "Холодный звонок" },
  { value: "partner", label: "Партнёр" },
  { value: "internet", label: "Из интернета" },
  { value: "lead", label: "Лид-заявка" },
];

export function DealFiltersBar({ filters, onChange, hideSearch }: Props) {
  const [expanded, setExpanded] = useState(false);
  const activeCount = countActive(filters);
  const hiddenCount = countVisibleActive(filters);

  function reset() {
    onChange(DEFAULT_DEAL_FILTERS);
  }

  function set<K extends keyof DealFilters>(key: K, value: DealFilters[K]) {
    onChange({ ...filters, [key]: value });
  }

  return (
    <div className="bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700 px-4 py-2">
      <div className="flex items-center gap-2 flex-wrap">
        {/* Search — показываем только если не вынесен в тулбар */}
        {!hideSearch && (
          <div className="relative">
            <i className="bi bi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm pointer-events-none" />
            <input
              type="text"
              className="input pl-8 w-48 text-sm py-1.5"
              placeholder="Поиск…"
              value={filters.q}
              onChange={(e) => set("q", e.target.value)}
            />
          </div>
        )}

        {/* Tumblers — всегда в видимой строке */}
        <label className="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none">
          <input
            type="checkbox"
            className="w-3.5 h-3.5"
            checked={filters.show_lost}
            onChange={(e) => set("show_lost", e.target.checked)}
          />
          Проиграна
        </label>
        <label className="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none">
          <input
            type="checkbox"
            className="w-3.5 h-3.5"
            checked={filters.show_cold}
            onChange={(e) => set("show_cold", e.target.checked)}
          />
          Холодные
        </label>

        {/* More filters toggle */}
        <button
          onClick={() => setExpanded((v) => !v)}
          className="btn-secondary text-sm flex items-center gap-1.5"
        >
          <i className="bi bi-sliders" />
          Ещё фильтры
          {hiddenCount > 0 && (
            <span className="bg-primary text-white text-[10px] font-bold rounded-full px-1.5 py-0.5 min-w-[18px] text-center">
              {hiddenCount}
            </span>
          )}
          <i className={`bi ${expanded ? "bi-chevron-up" : "bi-chevron-down"} text-xs`} />
        </button>

        {/* Reset */}
        {activeCount > 0 && (
          <button
            onClick={reset}
            className="btn-ghost text-sm flex items-center gap-1 text-gray-500"
          >
            <i className="bi bi-x-circle text-xs" />
            Сбросить
          </button>
        )}

        {/* Active badge */}
        {activeCount > 0 && (
          <span className="text-xs text-primary font-medium">
            {activeCount} активных
          </span>
        )}
      </div>

      {/* Expanded panel — город, ответственный и остальные скрытые фильтры */}
      {expanded && (
        <div className="mt-2 pt-2 border-t border-gray-100 dark:border-gray-700 flex flex-wrap gap-2 items-end">
          {/* City */}
          <div>
            <label className="label text-xs mb-0.5">Город</label>
            <input
              type="text"
              className="input w-32 text-sm py-1.5"
              placeholder="Город"
              value={filters.city}
              onChange={(e) => set("city", e.target.value)}
            />
          </div>

          {/* Owner */}
          <div>
            <label className="label text-xs mb-0.5">Ответственный</label>
            <div className="w-44">
              <UserSelect
                value={filters.owner_id}
                onChange={(v) => set("owner_id", v)}
                placeholder="Ответственный"
                className="input text-sm py-1.5"
              />
            </div>
          </div>

          {/* Country */}
          <div>
            <label className="label text-xs mb-0.5">Страна</label>
            <select
              className="input text-sm py-1.5 w-36"
              value={filters.country}
              onChange={(e) => set("country", e.target.value)}
            >
              {COUNTRY_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>

          {/* Source */}
          <div>
            <label className="label text-xs mb-0.5">Источник</label>
            <select
              className="input text-sm py-1.5 w-40"
              value={filters.source}
              onChange={(e) => set("source", e.target.value)}
            >
              {SOURCE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>

          {/* Product */}
          <div>
            <label className="label text-xs mb-0.5">Продукт</label>
            <input
              type="text"
              className="input text-sm py-1.5 w-32"
              placeholder="MacroCRM…"
              value={filters.product}
              onChange={(e) => set("product", e.target.value)}
            />
          </div>

          {/* Tag */}
          <div>
            <label className="label text-xs mb-0.5">Тег</label>
            <input
              type="text"
              className="input text-sm py-1.5 w-28"
              placeholder="тег"
              value={filters.tag}
              onChange={(e) => set("tag", e.target.value)}
            />
          </div>

          {/* Task kind */}
          <div>
            <label className="label text-xs mb-0.5">Тип задачи</label>
            <select
              className="input text-sm py-1.5 w-32"
              value={filters.task_kind}
              onChange={(e) => set("task_kind", e.target.value)}
            >
              {TASK_KIND_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>

          {/* Amount range */}
          <div>
            <label className="label text-xs mb-0.5">Бюджет, от</label>
            <input
              type="number"
              className="input text-sm py-1.5 w-28"
              placeholder="0"
              value={filters.amount_from}
              onChange={(e) => set("amount_from", e.target.value)}
            />
          </div>
          <div>
            <label className="label text-xs mb-0.5">до</label>
            <input
              type="number"
              className="input text-sm py-1.5 w-28"
              placeholder="∞"
              value={filters.amount_to}
              onChange={(e) => set("amount_to", e.target.value)}
            />
          </div>

          {/* No tasks */}
          <label className="flex items-center gap-1.5 text-sm text-gray-600 dark:text-gray-400 cursor-pointer select-none self-end pb-1">
            <input
              type="checkbox"
              className="w-3.5 h-3.5"
              checked={filters.no_tasks}
              onChange={(e) => set("no_tasks", e.target.checked)}
            />
            Только без задач
          </label>
        </div>
      )}
    </div>
  );
}
