"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";

interface HistoryEntry {
  id: number;
  user_name: string | null;
  action: string;
  detail: string | null;
  created_at: string;
}

interface Props {
  activityId: number;
}

export function TaskHistoryTab({ activityId }: Props) {
  const { data: history, isLoading } = useSWR<HistoryEntry[]>(
    `/activities/${activityId}/history`,
    fetcher
  );

  return (
    <div className="p-6">
      {isLoading && (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-8 bg-gray-100 dark:bg-gray-700 animate-pulse rounded" />
          ))}
        </div>
      )}

      {!isLoading && (!history || history.length === 0) && (
        <div className="text-center py-8">
          <p className="text-sm text-gray-500 dark:text-gray-400">История пуста</p>
        </div>
      )}

      {!isLoading && history && history.length > 0 && (
        <div className="space-y-3">
          {history.map((entry) => (
            <div key={entry.id} className="flex items-start gap-3 text-sm">
              <span className="text-gray-400 tabular-nums text-xs shrink-0 mt-0.5 w-32">
                {new Date(entry.created_at).toLocaleString("ru-RU", {
                  day: "numeric",
                  month: "short",
                  hour: "2-digit",
                  minute: "2-digit",
                })}
              </span>
              <div>
                {entry.user_name && (
                  <span className="font-medium text-gray-800 dark:text-gray-200">{entry.user_name} </span>
                )}
                <span className="text-gray-600 dark:text-gray-400">{entry.action}</span>
                {entry.detail && (
                  <span className="text-gray-500"> — {entry.detail}</span>
                )}
              </div>
            </div>
          ))}
        </div>
      )}
    </div>
  );
}
