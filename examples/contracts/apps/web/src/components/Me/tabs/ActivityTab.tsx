"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { EmptyState } from "@/components/EmptyState";
import { FtmBadge } from "@/components/FTM/FtmBadge";
import type { Activity, ActivityKind } from "@/lib/types";

interface Props {
  userId?: number;
}

const KIND_ICONS: Record<ActivityKind, string> = {
  call: "bi-telephone",
  meeting: "bi-calendar-event",
  task: "bi-check2-square",
  note: "bi-chat-left-text",
};

const KIND_COLORS: Record<ActivityKind, string> = {
  call: "bg-success-50 dark:bg-success-500/10 text-success-600",
  meeting: "bg-info-50 dark:bg-info-500/10 text-info-600",
  task: "bg-primary/5 dark:bg-white/5 text-primary dark:text-gray-300",
  note: "bg-warning-50 dark:bg-warning-500/10 text-warning-600",
};

const KIND_OPTIONS = [
  { value: "", label: "Все" },
  { value: "call", label: "Звонки" },
  { value: "meeting", label: "Встречи" },
  { value: "task", label: "Задачи" },
  { value: "note", label: "Заметки" },
];

const PERIOD_OPTIONS = [
  { value: "today", label: "Сегодня" },
  { value: "week", label: "Эта неделя" },
  { value: "month", label: "Этот месяц" },
];

interface ActivityWithFtm extends Activity {
  is_first_time_meeting?: boolean;
  ftm_counted?: boolean;
}

function groupByDate(activities: ActivityWithFtm[]) {
  const map = new Map<string, ActivityWithFtm[]>();
  for (const a of activities) {
    const key = a.created_at.slice(0, 10);
    if (!map.has(key)) map.set(key, []);
    map.get(key)!.push(a);
  }
  return map;
}

function formatDate(d: string) {
  return new Date(d).toLocaleDateString("ru-RU", {
    day: "numeric",
    month: "long",
    year: "numeric",
  });
}

export function ActivityTab({ userId }: Props) {
  const [kind, setKind] = useState("");
  const [period, setPeriod] = useState("week");
  const [ftmOnly, setFtmOnly] = useState(false);

  const params = new URLSearchParams();
  if (kind) params.set("kind", kind);
  if (period) params.set("period", period);
  if (ftmOnly) params.set("ftm_only", "true");
  if (userId) params.set("user_id", String(userId));

  const { data: activities, isLoading, error } = useSWR<ActivityWithFtm[]>(
    `/me/activities?${params.toString()}`,
    fetcher,
  );

  const grouped = activities ? groupByDate(activities) : new Map();
  const dates = Array.from(grouped.keys()).sort((a, b) => b.localeCompare(a));

  let itemIndex = 0;

  return (
    <div className="space-y-5">
      {/* Фильтры */}
      <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 px-4 py-3 flex flex-wrap items-center gap-3">
        <select
          className="input w-36"
          value={kind}
          onChange={(e) => setKind(e.target.value)}
          aria-label="Тип активности"
        >
          {KIND_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
        <select
          className="input w-40"
          value={period}
          onChange={(e) => setPeriod(e.target.value)}
          aria-label="Период"
        >
          {PERIOD_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
        <label className="flex items-center gap-2 cursor-pointer text-sm text-gray-600 dark:text-gray-400 select-none">
          <input
            type="checkbox"
            className="rounded border-gray-300 text-primary focus:ring-primary"
            checked={ftmOnly}
            onChange={(e) => setFtmOnly(e.target.checked)}
          />
          Только FTM
        </label>
      </div>

      {isLoading && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 space-y-2 animate-pulse">
          {[1, 2, 3, 4, 5].map((i) => (
            <div key={i} className="h-12 bg-gray-100 dark:bg-gray-700 rounded-xl" />
          ))}
        </div>
      )}

      {!isLoading && error && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-8">
          <EmptyState icon="bi-exclamation-circle" title="Не удалось загрузить активность" />
        </div>
      )}

      {!isLoading && !error && dates.length === 0 && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-8">
          <EmptyState
            icon="bi-clock-history"
            title="Нет активностей за период"
            description="Попробуйте изменить фильтры или выбрать другой период"
          />
        </div>
      )}

      {!isLoading && !error && dates.length > 0 && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 overflow-hidden">
          {dates.map((date) => (
            <div key={date}>
              {/* Date group header */}
              <div className="px-5 py-2.5 bg-gray-50 dark:bg-gray-700/50 border-b border-gray-100 dark:border-gray-700">
                <span className="text-xs font-semibold text-gray-400 dark:text-gray-500 uppercase tracking-wider">
                  {formatDate(date)}
                </span>
              </div>
              <div className="divide-y divide-gray-50 dark:divide-gray-700/50">
                {grouped.get(date)?.map((a: ActivityWithFtm) => {
                  const delay = `${0.25 + (itemIndex++ % 8) * 0.04}s`;
                  const iconColor = KIND_COLORS[a.kind] ?? "bg-gray-100 text-gray-400";
                  return (
                    <div
                      key={a.id}
                      className="blur-fade flex items-center gap-4 py-3 px-5 hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
                      style={{ "--blur-fade-duration": delay } as React.CSSProperties}
                    >
                      <span className={`w-8 h-8 grid place-items-center rounded-lg shrink-0 ${iconColor}`}>
                        <i className={`bi ${KIND_ICONS[a.kind]} text-sm`} aria-hidden="true" />
                      </span>
                      <div className="flex-1 min-w-0">
                        <div className="text-sm font-medium truncate">{a.title}</div>
                        {a.body && (
                          <div className="text-xs text-gray-500 truncate">{a.body}</div>
                        )}
                      </div>
                      <div className="shrink-0 flex items-center gap-2">
                        {a.due_at && (
                          <span className="text-xs text-gray-400">
                            {new Date(a.due_at).toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" })}
                          </span>
                        )}
                        {a.kind === "meeting" && a.is_first_time_meeting && (
                          <FtmBadge counted={!!a.ftm_counted} />
                        )}
                      </div>
                    </div>
                  );
                })}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
