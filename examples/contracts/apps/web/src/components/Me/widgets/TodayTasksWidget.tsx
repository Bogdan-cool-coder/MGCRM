"use client";

import Link from "next/link";
import { api } from "@/lib/api";
import { useSWRConfig } from "swr";
import { EmptyState } from "@/components/EmptyState";
import type { MeDashboardTask } from "@/lib/types";

interface Props {
  tasks?: MeDashboardTask[];
  isLoading?: boolean;
  swrKey?: string;
}

export function TodayTasksWidget({ tasks, isLoading, swrKey }: Props) {
  const { mutate } = useSWRConfig();

  async function markDone(id: number) {
    try {
      await api(`/activities/${id}`, {
        method: "PATCH",
        body: { is_done: true },
      });
      if (swrKey) mutate(swrKey);
    } catch {
      // graceful ignore
    }
  }

  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 flex flex-col">
      <div className="flex items-center justify-between mb-4">
        <span className="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <i className="bi bi-check2-square text-primary" aria-hidden="true" />
          Задачи на сегодня
        </span>
        <Link href="/me?tab=activity" className="btn-ghost text-xs">
          Все
          <i className="bi bi-arrow-right ml-1" aria-hidden="true" />
        </Link>
      </div>

      {isLoading && (
        <div className="space-y-2 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-11 bg-gray-100 dark:bg-gray-700 rounded-xl" />
          ))}
        </div>
      )}

      {!isLoading && (!tasks || tasks.length === 0) && (
        <div className="flex-1">
          <EmptyState
            icon="bi-check-circle"
            title="Задач нет — отличный день!"
          />
        </div>
      )}

      {!isLoading && tasks && tasks.length > 0 && (
        <div className="space-y-1">
          {tasks.slice(0, 10).map((t, idx) => (
            <div
              key={t.id}
              className="blur-fade flex items-start gap-3 py-2 px-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700/30 transition-colors"
              style={{ "--blur-fade-duration": `${0.3 + idx * 0.05}s` } as React.CSSProperties}
            >
              <button
                onClick={() => markDone(t.id)}
                className={`mt-0.5 w-5 h-5 rounded-full border-2 shrink-0 flex items-center justify-center transition-colors ${
                  t.completed_at
                    ? "border-success bg-success/10 text-success"
                    : "border-gray-300 hover:border-primary"
                }`}
                title="Отметить выполненной"
                aria-label="Отметить задачу выполненной"
              >
                {t.completed_at && <i className="bi bi-check text-xs" aria-hidden="true" />}
              </button>
              <div className="flex-1 min-w-0">
                <div className={`text-sm truncate ${t.completed_at ? "line-through text-gray-400" : ""} ${t.is_overdue ? "text-danger" : ""}`}>
                  {t.title}
                </div>
                {t.target_name && (
                  <div className="text-xs text-gray-500 truncate">{t.target_name}</div>
                )}
              </div>
              <div className="shrink-0 text-xs text-gray-400">
                {t.due_at && (
                  <span className={t.is_overdue ? "text-warning" : ""}>
                    <i className={`bi ${t.is_overdue ? "bi-clock-history" : "bi-clock"} mr-0.5`} aria-hidden="true" />
                    {new Date(t.due_at).toLocaleTimeString("ru-RU", { hour: "2-digit", minute: "2-digit" })}
                  </span>
                )}
              </div>
            </div>
          ))}
          {tasks.length > 10 && (
            <div className="flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-xl text-xs text-gray-500 dark:text-gray-400">
              <i className="bi bi-three-dots" aria-hidden="true" />
              Ещё {tasks.length - 10} задач — перейди в
              <a href="/me?tab=activity" className="text-primary hover:underline ml-0.5">
                список
              </a>
            </div>
          )}
        </div>
      )}
    </div>
  );
}
