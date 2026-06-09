"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { PipelineStage, TaskCategory } from "@/lib/types";

interface StageTasksAdminProps {
  pipelineId: number;
}

export function StageTasksAdmin({ pipelineId }: StageTasksAdminProps) {
  const { data: stages } = useSWR<PipelineStage[]>(
    `/pipelines/${pipelineId}/stages`,
    fetcher
  );
  const { data: categories } = useSWR<TaskCategory[]>("/task-categories", fetcher);

  const mainStages = (stages ?? []).filter((s) => !s.parent_stage_id);

  function getCategoryName(id: number) {
    return categories?.find((c) => c.id === id)?.name ?? `#${id}`;
  }

  return (
    <div>
      <div className="mb-4">
        <h3 className="text-base font-semibold text-gray-800 dark:text-gray-100">
          Задачи этапов
        </h3>
        <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
          Какие категории задач доступны на каждом этапе. Настройка — в параметрах этапа → «Фичи».
        </p>
      </div>

      {!stages && (
        <div className="py-6 text-center text-gray-400 text-sm">Загрузка…</div>
      )}

      <div className="space-y-2">
        {mainStages.map((stage) => {
          const allowed = stage.allowed_task_category_ids ?? [];
          return (
            <div
              key={stage.id}
              className="border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5"
            >
              <div className="flex items-center gap-2">
                <span
                  className="w-2.5 h-2.5 rounded-full shrink-0"
                  style={{ backgroundColor: stage.color ?? "#6B7A99" }}
                />
                <span className="text-sm font-medium text-gray-800 dark:text-gray-200 flex-1">
                  {stage.name}
                </span>
                {stage.is_won && <i className="bi bi-trophy text-success text-xs" />}
                {stage.is_lost && <i className="bi bi-x-circle text-danger text-xs" />}
              </div>

              {allowed.length === 0 ? (
                <p className="text-xs text-gray-400 mt-1.5 ml-5">Все категории (не ограничено)</p>
              ) : (
                <div className="flex flex-wrap gap-1 mt-1.5 ml-5">
                  {allowed.map((catId) => (
                    <span
                      key={catId}
                      className="text-xs px-2 py-0.5 rounded-full bg-primary/10 text-primary"
                    >
                      {getCategoryName(catId)}
                    </span>
                  ))}
                </div>
              )}

              {(stage.stage_features ?? []).length > 0 && (
                <div className="flex flex-wrap gap-1 mt-1 ml-5">
                  {(stage.stage_features ?? []).map((feat) => {
                    const labels: Record<string, string> = {
                      send_presentation: "Презентация",
                      meeting_report: "Отчёт встречи",
                      generate_document: "Договор",
                    };
                    return (
                      <span
                        key={feat}
                        className="text-xs px-2 py-0.5 rounded-full bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400"
                      >
                        {labels[feat] ?? feat}
                      </span>
                    );
                  })}
                </div>
              )}
            </div>
          );
        })}
      </div>

      <div className="mt-4 p-3 bg-gray-50 dark:bg-gray-700/50 rounded-lg border border-gray-200 dark:border-gray-700">
        <div className="text-xs text-gray-500 dark:text-gray-400">
          <i className="bi bi-info-circle mr-1" />
          Чтобы изменить категории задач или фичи этапа — кликните по этапу в визуальном конструкторе
          и откройте вкладку «Фичи».
        </div>
        <a
          href="/admin/task-categories"
          className="text-xs text-primary hover:underline mt-1 inline-block"
        >
          Редактировать категории задач →
        </a>
      </div>
    </div>
  );
}
