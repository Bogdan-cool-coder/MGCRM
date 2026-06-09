"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { TrainingScorecard } from "./TrainingScorecard";
import type { ColdCallSessionDetail } from "@/lib/types";

const SCENARIO_LABELS: Record<ColdCallSessionDetail["scenario_type"], string> = {
  cold_call: "Холодный звонок",
  objection_handling: "Возражение по цене",
  ceo_rejection: "Отказ ЛПР",
  follow_up: "Повторный звонок",
};

interface Props {
  sessionId: number;
  onBack: () => void;
}

export function TrainingDetailView({ sessionId, onBack }: Props) {
  const { data, isLoading, error } = useSWR<ColdCallSessionDetail>(
    `/me/training/sessions/${sessionId}`,
    fetcher,
  );

  return (
    <div className="max-w-2xl mx-auto space-y-4">
      <button onClick={onBack} className="btn-ghost text-sm">
        <i className="bi bi-arrow-left mr-1" />
        К истории
      </button>

      {isLoading && (
        <div className="card p-6 h-64 animate-pulse bg-gray-100 dark:bg-gray-700" />
      )}

      {!isLoading && error && (
        <div className="card p-6 text-sm text-danger">Не удалось загрузить тренировку</div>
      )}

      {!isLoading && !error && data && (
        <>
          <div className="card p-5">
            <div className="text-sm font-medium mb-1">
              {SCENARIO_LABELS[data.scenario_type]}
              <span className="text-gray-400 font-normal">
                {" · "}{data.company_type}{data.company_name ? ` «${data.company_name}»` : ""}
              </span>
            </div>
            <div className="text-xs text-gray-400 mb-4">
              {new Date(data.created_at).toLocaleString("ru-RU", {
                day: "numeric",
                month: "long",
                year: "numeric",
                hour: "2-digit",
                minute: "2-digit",
              })}
            </div>

            {/* Transcript */}
            <div className="space-y-3">
              {data.transcript.length === 0 ? (
                <p className="text-sm text-gray-400">Транскрипт пуст</p>
              ) : (
                data.transcript.map((m, i) => (
                  <div key={i} className={`flex ${m.role === "user" ? "justify-end" : "justify-start"}`}>
                    <div className="max-w-[80%]">
                      <div
                        className={
                          m.role === "user"
                            ? "bg-primary text-white rounded-2xl rounded-br-none px-4 py-2 text-sm"
                            : "bg-gray-100 dark:bg-gray-800 rounded-2xl rounded-bl-none px-4 py-2 text-sm"
                        }
                      >
                        {m.content}
                      </div>
                      {m.hints && m.hints.length > 0 && (
                        <div className="mt-1.5 space-y-1">
                          {m.hints.map((h, j) => (
                            <div key={j} className="flex items-start gap-1.5 text-xs text-gray-500 dark:text-gray-400">
                              <i className="bi bi-lightbulb text-warning mt-0.5 shrink-0" />
                              <span>{h}</span>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  </div>
                ))
              )}
            </div>
          </div>

          {/* Scorecard if finished */}
          {data.status === "finished" && data.score != null && data.scores && (
            <TrainingScorecard
              score={data.score}
              scores={data.scores}
              feedback={data.feedback ?? ""}
              recommendations={data.recommendations ?? undefined}
              goodDecisions={data.good_decisions ?? undefined}
            />
          )}
        </>
      )}
    </div>
  );
}
