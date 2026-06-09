"use client";

/**
 * ReportFilterBar — общий фильтр для отчётов Ф1.
 * Юрлицо (обязательное) + период (date_from / date_to).
 * URL-синхронизация: вызывает onChange при изменении, родитель — строит URL.
 */

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { DatePicker } from "@/components/ui/DatePicker";
import type { FinLegalEntity } from "@/lib/types";

export interface ReportFilters {
  entity: string;  // legal_entity_id как строка
  date_from: string;
  date_to: string;
}

export const DEFAULT_REPORT_FILTERS: ReportFilters = {
  entity: "",
  date_from: "",
  date_to: "",
};

interface Props {
  filters: ReportFilters;
  onChange: (f: ReportFilters) => void;
  /** Скрыть поля дат (для отчётов на дату: ОСВ, AR/AP) */
  hideDates?: boolean;
  /** Показать поле «на дату» вместо диапазона */
  onDateMode?: boolean;
}

export function ReportFilterBar({ filters, onChange, hideDates, onDateMode }: Props) {
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  function set(field: keyof ReportFilters, value: string) {
    onChange({ ...filters, [field]: value });
  }

  return (
    <div className="card p-4 mb-4">
      <div className="flex flex-wrap gap-2 items-center">
        <select
          className="input text-sm w-auto min-w-[180px]"
          value={filters.entity}
          onChange={(e) => set("entity", e.target.value)}
        >
          <option value="">— Выберите юрлицо —</option>
          {entities?.map((e) => (
            <option key={e.id} value={String(e.id)}>
              {e.name}
            </option>
          ))}
        </select>

        {!hideDates && !onDateMode && (
          <>
            <div className="flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
              с
              <DatePicker
                value={filters.date_from}
                onChange={(v) => set("date_from", v ?? "")}
                placeholder="Начало периода"
                className="w-auto"
              />
            </div>
            <div className="flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
              по
              <DatePicker
                value={filters.date_to}
                onChange={(v) => set("date_to", v ?? "")}
                placeholder="Конец периода"
                className="w-auto"
              />
            </div>
          </>
        )}

        {onDateMode && (
          <div className="flex items-center gap-1 text-sm text-gray-500 dark:text-gray-400">
            на дату
            <DatePicker
              value={filters.date_to}
              onChange={(v) => set("date_to", v ?? "")}
              placeholder="На дату"
              className="w-auto"
            />
          </div>
        )}

        {filters.entity && (
          <span className="text-xs text-gray-400 dark:text-gray-500 ml-auto">
            <i className="bi bi-info-circle mr-1" />
            Данные в функциональной валюте юрлица
          </span>
        )}
      </div>
    </div>
  );
}
