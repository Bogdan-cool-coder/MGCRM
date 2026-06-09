"use client";

import { EmptyState } from "@/components/EmptyState";
import type { CalldownCall, TranscriptStatus } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

interface Props {
  calls: CalldownCall[];
  isLoading: boolean;
  onCallClick: (call: CalldownCall) => void;
}

function formatDuration(sec: number | null): string {
  if (sec === null) return "—";
  const m = Math.floor(sec / 60);
  const s = sec % 60;
  return `${m}:${s.toString().padStart(2, "0")}`;
}

function TranscriptBadge({ status }: { status: TranscriptStatus }) {
  if (!status) {
    return <span className="text-gray-400 dark:text-gray-500 text-xs">—</span>;
  }
  if (status === "done") {
    return (
      <span className="text-xs inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400">
        <i className="bi bi-check-circle" />
        Транскрипция
      </span>
    );
  }
  if (status === "pending") {
    return (
      <span className="text-xs text-gray-400 dark:text-gray-500 inline-flex items-center gap-1">
        <i className="bi bi-hourglass-split" />
        Расшифровка…
      </span>
    );
  }
  if (status === "failed") {
    return (
      <span className="text-xs inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-medium bg-danger-50 text-danger-700 dark:bg-danger-500/10 dark:text-danger-400">
        <i className="bi bi-x-circle" />
        Ошибка
      </span>
    );
  }
  return null;
}

export function CallsTable({ calls, isLoading, onCallClick }: Props) {
  if (isLoading) {
    return (
      <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800 animate-pulse">
        {[1, 2, 3, 4, 5].map((i) => (
          <div key={i} className="h-14 border-b border-gray-100 dark:border-gray-800 last:border-0 bg-white dark:bg-gray-900" />
        ))}
      </div>
    );
  }

  if (calls.length === 0) {
    return (
      <EmptyState
        icon="bi-telephone-x"
        title="Нет звонков за этот период"
        description="Настрой Calldown для автоматической записи звонков"
        cta={
          <a href="/admin/integrations/calldown" className="btn-primary">
            <i className="bi bi-gear mr-1" />
            Настроить Calldown
          </a>
        }
      />
    );
  }

  return (
    <div className="card rounded-2xl shadow-elev-1 overflow-hidden border border-gray-100 dark:border-gray-800">
      <div className="overflow-x-auto">
        <table className="min-w-full text-sm">
          <thead className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900">
            <tr>
              <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Дата/время</th>
              <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Номер</th>
              <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Направление</th>
              <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Длит.</th>
              <th className="px-4 py-2.5 text-left text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Менеджер</th>
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {calls.map((call) => (
              <tr
                key={call.id}
                className="group hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] cursor-pointer transition-colors duration-100"
                onClick={() => onCallClick(call)}
              >
                <td className="px-4 py-2.5 text-xs text-gray-600 dark:text-gray-400">
                  {formatDateTime(call.created_at)}
                </td>
                <td className="px-4 py-2.5 font-mono text-xs text-gray-700 dark:text-gray-300">
                  {call.phone ?? "—"}
                </td>
                <td className="px-4 py-2.5">
                  {call.direction === "in" ? (
                    <span className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-400">
                      <i className="bi bi-telephone-inbound" />
                      Входящий
                    </span>
                  ) : (
                    <span className="inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-medium bg-warning-50 text-warning-700 dark:bg-warning-500/10 dark:text-warning-400">
                      <i className="bi bi-telephone-outbound" />
                      Исходящий
                    </span>
                  )}
                </td>
                <td className="px-4 py-2.5 text-gray-600 dark:text-gray-400 font-mono text-xs tabular-nums">
                  {formatDuration(call.duration_sec)}
                </td>
                <td className="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                  <div className="text-sm">{call.owner_name ?? "—"}</div>
                  <div className="mt-0.5">
                    <TranscriptBadge status={call.transcript_status} />
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>
    </div>
  );
}
