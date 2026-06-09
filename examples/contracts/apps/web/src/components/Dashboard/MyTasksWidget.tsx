"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { ACTIVITY_TARGET_LABELS, type MyTask } from "@/lib/types";

const KIND_ICONS: Record<string, string> = {
  call: "bi-telephone",
  meeting: "bi-camera-video",
  task: "bi-check2-square",
  note: "bi-sticky",
};

function fmtDue(due: string | null, overdue: boolean): { text: string; cls: string } {
  if (!due) return { text: "без срока", cls: "text-gray-400 dark:text-gray-500" };
  const d = new Date(due);
  const today = new Date();
  const isToday = d.toDateString() === today.toDateString();
  const text = isToday ? "сегодня" : d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
  return {
    text,
    cls: overdue
      ? "text-danger-600 font-semibold"
      : isToday
      ? "text-warning-600 font-semibold"
      : "text-gray-500 dark:text-gray-400",
  };
}

const TARGET_PATHS: Record<string, string> = {
  lead: "/leads",
  contact: "/contacts",
  company: "/companies",
  counterparty: "/counterparties",
  deal: "/deals",
  contract: "/contracts",
  subscription: "/registry",
};

export function MyTasksWidget() {
  const { data: rawTasks, isLoading, error } = useSWR<MyTask[]>(
    "/activities?responsible_id=me&include_closed=false&page_size=10&sort=due_at_asc",
    fetcher,
  );
  // Клиентский лимит — бэкенд может проигнорировать page_size
  const tasks = rawTasks?.slice(0, 10);
  const taskCount = tasks?.length ?? 0;

  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 hover:shadow-elev-2 p-6 lift flex flex-col min-h-[220px] h-full overflow-hidden">
      {/* Заголовок с counter-badge */}
      <div className="flex items-center justify-between mb-4">
        <h3 className="font-semibold">Мои задачи на сегодня</h3>
        {!isLoading && taskCount > 0 && (
          <span className="text-xs bg-info-50 text-info-700 dark:bg-info-500/10 dark:text-info-500 rounded-full px-2 py-0.5 font-medium">
            {taskCount}
          </span>
        )}
      </div>

      {/* Loading skeleton */}
      {isLoading && (
        <ul
          className="flex-1 space-y-1"
          aria-busy="true"
          aria-label="Загружаем данные"
          role="list"
        >
          {[1, 2, 3, 4].map((i) => (
            <li key={i} className="flex items-center gap-2 py-1.5" role="listitem">
              <div className="w-4 h-4 rounded bg-gray-100 dark:bg-gray-700 animate-pulse shrink-0" />
              <div className="flex-1 h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
              <div className="w-12 h-3 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
            </li>
          ))}
        </ul>
      )}

      {!isLoading && error && (
        <div className="flex-1 flex items-center justify-center">
          <div className="text-center text-sm text-gray-500">
            <i className="bi bi-exclamation-triangle text-2xl block mb-2 text-warning-500" />
            Не удалось загрузить
          </div>
        </div>
      )}

      {/* Empty state */}
      {!isLoading && !error && (!tasks || tasks.length === 0) && (
        <div className="flex-1 flex flex-col items-center justify-center gap-2 py-6">
          <i className="bi bi-check2-circle text-4xl text-success-500" aria-hidden="true" />
          <div className="text-sm font-medium text-gray-700 dark:text-gray-300">Всё сделано</div>
          <div className="text-xs text-gray-500 dark:text-gray-400">На сегодня задач нет</div>
        </div>
      )}

      {/* Task list with stagger appearance; внутренний скролл при переполнении */}
      {!isLoading && !error && tasks && tasks.length > 0 && (
        <ul className="flex-1 space-y-1 overflow-y-auto" role="list">
          {tasks.map((t, idx) => {
            const due = fmtDue(t.due_at, t.is_overdue);
            const targetPath = t.target_id
              ? `${TARGET_PATHS[t.target_type] ?? ""}/${t.target_id}`
              : null;
            const targetLabel = t.target_name ?? ACTIVITY_TARGET_LABELS[t.target_type];
            return (
              <li
                key={t.id}
                className="blur-fade"
                style={{ "--blur-fade-duration": "0.4s", animationDelay: `${idx * 50}ms` } as React.CSSProperties}
                role="listitem"
              >
                <Link
                  href={`/tasks/${t.id}`}
                  className="flex items-center gap-2 py-1.5 border-b border-gray-100 dark:border-gray-700/50 last:border-0 rounded hover:bg-gray-50 dark:hover:bg-gray-700 px-1 -mx-1 transition-colors"
                >
                  <i className={`bi ${KIND_ICONS[t.kind] ?? "bi-check2"} text-primary text-sm shrink-0`} aria-hidden="true" />
                  <span className="flex-1 text-sm truncate" title={t.title}>{t.title}</span>
                  {targetPath && (
                    <span
                      className="text-xs text-primary dark:text-blue-300 shrink-0 truncate max-w-[100px]"
                      title={targetLabel ?? ""}
                    >
                      {targetLabel}
                    </span>
                  )}
                  <span className={`text-xs shrink-0 ${due.cls}`}>{due.text}</span>
                </Link>
              </li>
            );
          })}
        </ul>
      )}

      <div className="mt-3 pt-2 border-t border-gray-100 dark:border-gray-700">
        <Link href="/tasks" className="text-xs text-primary dark:text-blue-300 hover:underline">
          Открыть все задачи →
        </Link>
      </div>
    </div>
  );
}
