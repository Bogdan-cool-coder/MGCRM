"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { ColdCallHistoryItem } from "@/lib/types";

const SCENARIO_LABELS: Record<ColdCallHistoryItem["scenario_type"], string> = {
  cold_call: "Холодный звонок",
  objection_handling: "Возражение по цене",
  ceo_rejection: "Отказ ЛПР",
  follow_up: "Повторный звонок",
};

interface Props {
  onOpen: (id: number) => void;
}

function scoreColor(score: number): string {
  if (score >= 7) return "text-success";
  if (score >= 5) return "text-warning";
  return "text-danger";
}

export function TrainingHistory({ onOpen }: Props) {
  const { data, isLoading, error } = useSWR<ColdCallHistoryItem[]>(
    "/me/training/sessions",
    fetcher,
  );

  return (
    <div className="card rounded-2xl shadow-elev-1 p-5 flex flex-col">
      <div className="flex items-center gap-2 mb-3">
        <i className="bi bi-clock-history text-primary" />
        <h3 className="text-sm font-semibold text-gray-900 dark:text-white">История тренировок</h3>
      </div>

      {isLoading && (
        <div className="space-y-2 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-14 bg-gray-100 dark:bg-gray-700 rounded-xl" />
          ))}
        </div>
      )}

      {!isLoading && error && (
        <p className="text-sm text-danger">Не удалось загрузить историю</p>
      )}

      {!isLoading && !error && (!data || data.length === 0) && (
        <div className="flex flex-col items-center justify-center py-8 text-center gap-2">
          <i className="bi bi-telephone-x text-2xl text-gray-300 dark:text-gray-600" />
          <p className="text-sm text-gray-400">Пока нет завершённых тренировок</p>
        </div>
      )}

      {!isLoading && !error && data && data.length > 0 && (
        <div className="overflow-y-auto max-h-[520px] -mx-1 px-1 space-y-1.5 scrollbar-thin">
          {data.map((s) => (
            <button
              key={s.id}
              type="button"
              onClick={() => onOpen(s.id)}
              className="w-full flex items-center gap-3 p-3 text-left rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700/40 transition-colors border border-transparent hover:border-gray-200 dark:hover:border-gray-700"
            >
              <div className="shrink-0 w-8 h-8 rounded-lg bg-primary/10 flex items-center justify-center">
                <i className="bi bi-telephone-fill text-primary text-sm" />
              </div>
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium truncate">
                  {SCENARIO_LABELS[s.scenario_type]}
                </div>
                <div className="text-xs text-gray-400 truncate">
                  {s.company_type}{s.company_name ? ` · «${s.company_name}»` : ""}
                  {" · "}
                  {new Date(s.created_at).toLocaleString("ru-RU", {
                    day: "numeric",
                    month: "short",
                    hour: "2-digit",
                    minute: "2-digit",
                  })}
                </div>
              </div>
              <div className="shrink-0 flex items-center gap-1.5">
                {s.status === "finished" && s.score != null ? (
                  <span className={`text-sm font-semibold tabular-nums ${scoreColor(s.score)}`}>
                    {s.score.toFixed(1)}<span className="text-gray-400 font-normal text-xs">/10</span>
                  </span>
                ) : (
                  <span className="badge bg-gray-100 dark:bg-gray-700 text-gray-500 text-xs">не завершён</span>
                )}
                <i className="bi bi-chevron-right text-gray-300 text-xs" />
              </div>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
