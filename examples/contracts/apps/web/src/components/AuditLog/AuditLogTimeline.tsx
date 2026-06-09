"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { AuditLogEntry } from "./AuditLogEntry";
import type { AuditAction, AuditEntityType, AuditLogEntry as AuditLogEntryType, AuditLogResponse } from "@/lib/types";

interface AuditLogTimelineProps {
  entityType: AuditEntityType;
  entityId: number;
  initialLimit?: number;
}

type ActionFilter = AuditAction | "all";

const ACTION_FILTER_OPTIONS: { value: ActionFilter; label: string }[] = [
  { value: "all", label: "Все действия" },
  { value: "create", label: "Создание" },
  { value: "update", label: "Изменения" },
  { value: "delete", label: "Удаление" },
  { value: "merge", label: "Объединение" },
  { value: "extra_fields_change", label: "Доп. поля" },
  { value: "bulk_action", label: "Массовые действия" },
];

function groupByDate(items: AuditLogEntryType[]): { date: string; entries: AuditLogEntryType[] }[] {
  const map = new Map<string, AuditLogEntryType[]>();
  for (const item of items) {
    const date = new Date(item.occurred_at).toLocaleDateString("ru-RU", {
      day: "2-digit",
      month: "2-digit",
      year: "numeric",
    });
    if (!map.has(date)) map.set(date, []);
    map.get(date)!.push(item);
  }
  return Array.from(map.entries()).map(([date, entries]) => ({ date, entries }));
}

export function AuditLogTimeline({
  entityType,
  entityId,
  initialLimit = 20,
}: AuditLogTimelineProps) {
  const [limit, setLimit] = useState(initialLimit);
  const [actionFilter, setActionFilter] = useState<ActionFilter>("all");

  const queryParams = new URLSearchParams({
    limit: String(limit),
    offset: "0",
  });
  if (actionFilter !== "all") queryParams.set("action", actionFilter);

  const swrKey = `/audit/${entityType}/${entityId}?${queryParams.toString()}`;
  const { data, isLoading, error } = useSWR<AuditLogResponse>(swrKey, fetcher);

  const items = data?.items ?? [];
  const total = data?.total ?? 0;
  const groups = groupByDate(items);
  const hasMore = items.length < total;

  return (
    <div>
      {/* Filter bar */}
      <div className="flex items-center gap-3 mb-4">
        <select
          className="input text-sm py-1.5"
          value={actionFilter}
          onChange={(e) => {
            setActionFilter(e.target.value as ActionFilter);
            setLimit(initialLimit);
          }}
        >
          {ACTION_FILTER_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
        <button
          className="btn-ghost text-sm py-1.5 opacity-50 cursor-not-allowed"
          disabled
          title="Скоро"
        >
          <i className="bi bi-download mr-1" />
          Экспорт
        </button>
      </div>

      {/* Loading skeleton */}
      {isLoading && (
        <div className="animate-pulse space-y-3">
          {[1, 2, 3, 4, 5].map((i) => {
            const widths = ["w-3/4", "w-2/3", "w-4/5", "w-1/2", "w-2/3"];
            return (
              <div key={i} className={`h-5 bg-gray-200 rounded ${widths[(i - 1) % widths.length]}`} />
            );
          })}
        </div>
      )}

      {/* Error */}
      {error && (
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
          Не удалось загрузить историю изменений
        </div>
      )}

      {/* Empty */}
      {!isLoading && !error && items.length === 0 && (
        <div className="text-sm text-gray-500 text-center py-8">
          Изменений пока не зафиксировано
        </div>
      )}

      {/* Groups */}
      {!isLoading && groups.length > 0 && (
        <div className="space-y-4">
          {groups.map(({ date, entries }) => (
            <div key={date}>
              <div className="text-xs text-gray-400 uppercase tracking-wide mb-2 flex items-center gap-2">
                <div className="h-px bg-gray-200 flex-1" />
                {date}
                <div className="h-px bg-gray-200 flex-1" />
              </div>
              <div className="divide-y divide-gray-100">
                {entries.map((entry) => (
                  <AuditLogEntry key={entry.id} entry={entry} />
                ))}
              </div>
            </div>
          ))}

          {/* Show more */}
          {hasMore && (
            <div className="text-center">
              <button
                onClick={() => setLimit((l) => l + initialLimit)}
                className="btn-ghost text-sm"
              >
                Показать ещё
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
