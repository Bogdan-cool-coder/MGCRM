"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { CalldownCallsResponse } from "@/lib/types";

interface CalldownStatsResponse {
  today_count: number;
  transcribed_count: number;
  error_count: number;
}

export function CalldownLogsTab() {
  const { data: stats } = useSWR<CalldownStatsResponse>(
    "/integrations/calldown/stats/today",
    fetcher,
  );
  const { data: recentCalls } = useSWR<CalldownCallsResponse>(
    "/integrations/calldown/calls?limit=5",
    fetcher,
  );

  const calls = recentCalls?.items ?? [];

  return (
    <div className="space-y-6">
      {/* KPI summary */}
      <div className="grid grid-cols-3 gap-4">
        <div className="card rounded-2xl shadow-elev-1 p-5 border border-gray-100 dark:border-gray-800">
          <div className="text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">
            {stats?.today_count ?? "—"}
          </div>
          <div className="text-xs text-gray-500 dark:text-gray-400 mt-1.5 flex items-center gap-1">
            <i className="bi bi-telephone" />
            Звонков сегодня
          </div>
        </div>
        <div className="card rounded-2xl shadow-elev-1 p-5 border border-gray-100 dark:border-gray-800">
          <div className="text-2xl font-bold tabular-nums text-success-700 dark:text-success-400">
            {stats?.transcribed_count ?? "—"}
          </div>
          <div className="text-xs text-gray-500 dark:text-gray-400 mt-1.5 flex items-center gap-1">
            <i className="bi bi-check-circle text-success-500" />
            Расшифровано
          </div>
        </div>
        <div className="card rounded-2xl shadow-elev-1 p-5 border border-gray-100 dark:border-gray-800">
          <div className="text-2xl font-bold tabular-nums text-danger-700 dark:text-danger-400">
            {stats?.error_count ?? "—"}
          </div>
          <div className="text-xs text-gray-500 dark:text-gray-400 mt-1.5 flex items-center gap-1">
            <i className="bi bi-x-circle text-danger-500" />
            Ошибок расшифровки
          </div>
        </div>
      </div>

      {/* Recent calls mini-table */}
      {calls.length > 0 && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
          <div className="px-4 py-3 border-b border-gray-100 dark:border-gray-700 text-sm font-semibold text-gray-900 dark:text-gray-100">
            Последние звонки
          </div>
          <div className="divide-y divide-gray-100 dark:divide-gray-800">
            {calls.map((call) => (
              <div key={call.id} className="flex items-center gap-4 px-4 py-3 text-sm hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors">
                <span className="text-gray-400 dark:text-gray-500 text-xs shrink-0 tabular-nums">
                  {new Date(call.created_at).toLocaleString("ru-RU", {
                    day: "2-digit",
                    month: "2-digit",
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                </span>
                <span
                  className={[
                    "inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium",
                    call.direction === "in"
                      ? "bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400"
                      : "bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400",
                  ].join(" ")}
                >
                  <i className={`bi ${call.direction === "in" ? "bi-telephone-inbound" : "bi-telephone-outbound"}`} />
                  {call.direction === "in" ? "Вх." : "Исх."}
                </span>
                <span className="font-mono text-xs text-gray-700 dark:text-gray-300">{call.phone ?? "—"}</span>
                <span className="text-xs text-gray-500 dark:text-gray-400">{call.owner_name ?? "—"}</span>
                {call.transcript_status === "done" && (
                  <span className="text-xs text-success-600 dark:text-success-400 ml-auto">
                    <i className="bi bi-check-circle-fill" />
                  </span>
                )}
                {call.transcript_status === "failed" && (
                  <span className="text-xs text-danger-600 dark:text-danger-400 ml-auto">
                    <i className="bi bi-x-circle-fill" />
                  </span>
                )}
              </div>
            ))}
          </div>
        </div>
      )}

      <div>
        <Link href="/admin/integrations/calldown/calls" className="btn-secondary">
          <i className="bi bi-box-arrow-up-right mr-1" />
          Смотреть полный журнал
        </Link>
      </div>
    </div>
  );
}
