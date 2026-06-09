"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { DatePicker } from "@/components/ui/DatePicker";
import { EmptyState } from "@/components/EmptyState";
import type { APIToken, ApiRequestLog, ApiRequestLogsResponse } from "@/lib/types";

const PAGE_SIZE = 25;

const METHOD_BADGE: Record<string, string> = {
  GET: "bg-info/10 text-info",
  POST: "bg-success/10 text-success",
  PUT: "bg-warning/10 text-warning",
  PATCH: "bg-warning/10 text-warning",
  DELETE: "bg-danger/10 text-danger",
};

function statusColor(code: number): string {
  if (code < 300) return "text-success";
  if (code < 400) return "text-info";
  if (code < 500) return "text-warning";
  return "text-danger";
}

export function ApiRequestLogsTab() {
  const [tokenId, setTokenId] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [page, setPage] = useState(1);

  const { data: tokens } = useSWR<APIToken[]>("/api/api-tokens", fetcher);

  const params = new URLSearchParams({
    limit: String(PAGE_SIZE),
    offset: String((page - 1) * PAGE_SIZE),
  });
  if (tokenId) params.set("token_id", tokenId);
  if (statusFilter) params.set("status", statusFilter);
  if (dateFrom) params.set("from", dateFrom);
  if (dateTo) params.set("to", dateTo);

  const { data, error, isLoading } = useSWR<ApiRequestLogsResponse>(
    `/integrations/logs?${params.toString()}`,
    fetcher,
  );

  const logs: ApiRequestLog[] = data?.items ?? [];
  const total = data?.total ?? 0;
  const totalPages = Math.ceil(total / PAGE_SIZE);

  function handleExport() {
    const exportParams = new URLSearchParams(params);
    exportParams.set("format", "csv");
    window.open(`/api/integrations/logs/export?${exportParams.toString()}`, "_blank");
  }

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="flex flex-wrap gap-3 items-end">
        <div>
          <label className="label text-xs mb-1">Токен</label>
          <select
            className="input w-48"
            value={tokenId}
            onChange={(e) => { setTokenId(e.target.value); setPage(1); }}
          >
            <option value="">Все токены</option>
            {tokens?.map((t) => (
              <option key={t.id} value={String(t.id)}>{t.name}</option>
            ))}
          </select>
        </div>
        <div>
          <label className="label text-xs mb-1">Статус</label>
          <select
            className="input w-44"
            value={statusFilter}
            onChange={(e) => { setStatusFilter(e.target.value); setPage(1); }}
          >
            <option value="">Все статусы</option>
            <option value="2xx">2xx Успех</option>
            <option value="4xx">4xx Ошибка клиента</option>
            <option value="5xx">5xx Ошибка сервера</option>
          </select>
        </div>
        <div>
          <label className="label text-xs mb-1">Дата от</label>
          <DatePicker
            value={dateFrom || null}
            onChange={(v) => { setDateFrom(v ?? ""); setPage(1); }}
            placeholder="Дата от"
          />
        </div>
        <div>
          <label className="label text-xs mb-1">Дата до</label>
          <DatePicker
            value={dateTo || null}
            onChange={(v) => { setDateTo(v ?? ""); setPage(1); }}
            placeholder="Дата до"
          />
        </div>
        <button className="btn-ghost text-sm" onClick={handleExport}>
          <i className="bi bi-download mr-1" />
          Экспорт CSV
        </button>
      </div>

      {error && (
        <div className="rounded-md bg-danger/10 text-danger px-4 py-3 text-sm">
          Не удалось загрузить логи запросов
        </div>
      )}

      {isLoading && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800 animate-pulse">
          {[1, 2, 3, 4, 5].map((i) => (
            <div key={i} className="h-10 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
          ))}
        </div>
      )}

      {!isLoading && !error && logs.length === 0 && (
        <EmptyState icon="bi-activity" title="Нет запросов за этот период" />
      )}

      {!isLoading && !error && logs.length > 0 && (
        <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
          <div className="overflow-x-auto">
            <table className="min-w-full text-sm">
              <thead className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
                <tr>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Метод</th>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Путь</th>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Статус</th>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Время</th>
                  <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Дата</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                {logs.map((log) => (
                  <tr key={log.id} className="hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors duration-100">
                    <td className="px-4 py-2.5">
                      <span className={`badge text-xs rounded-full ${METHOD_BADGE[log.method] ?? "bg-gray-100 dark:bg-gray-800 text-gray-600 dark:text-gray-400"}`}>
                        {log.method}
                      </span>
                    </td>
                    <td className="px-4 py-2.5 font-mono text-xs text-gray-700 dark:text-gray-300 max-w-xs truncate">
                      {log.path}
                    </td>
                    <td className={`px-4 py-2.5 font-mono text-xs font-semibold tabular-nums ${statusColor(log.status_code)}`}>
                      {log.status_code}
                    </td>
                    <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 tabular-nums">
                      {log.duration_ms != null ? `${log.duration_ms} мс` : "—"}
                    </td>
                    <td className="px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 whitespace-nowrap">
                      {new Date(log.created_at).toLocaleString("ru-RU")}
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="px-4 py-3 border-t border-gray-100 dark:border-gray-700 flex items-center justify-center gap-1">
              <button
                className="btn-ghost text-xs"
                disabled={page <= 1}
                onClick={() => setPage((p) => p - 1)}
              >
                <i className="bi bi-chevron-left" />
              </button>
              {Array.from({ length: Math.min(totalPages, 5) }, (_, i) => i + 1).map((p) => (
                <button
                  key={p}
                  onClick={() => setPage(p)}
                  className={`w-8 h-8 rounded-lg text-xs transition-colors ${p === page ? "bg-primary text-white" : "btn-ghost"}`}
                >
                  {p}
                </button>
              ))}
              <button
                className="btn-ghost text-xs"
                disabled={page >= totalPages}
                onClick={() => setPage((p) => p + 1)}
              >
                <i className="bi bi-chevron-right" />
              </button>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
