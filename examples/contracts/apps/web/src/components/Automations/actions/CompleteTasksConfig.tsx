"use client";

import { TASK_TYPES } from "@/lib/types";

interface Props {
  config: Record<string, unknown>;
  onChange: (next: Record<string, unknown>) => void;
}

type Scope = "all" | "by_type";

/**
 * Конфиг для action_kind=complete_tasks.
 * TODO(backend): добавить action_kind complete_tasks в automation_executor.py.
 */
export function CompleteTasksConfig({ config, onChange }: Props) {
  const scope: Scope = config.scope === "by_type" ? "by_type" : "all";
  const taskTypes: string[] = Array.isArray(config.task_types) ? (config.task_types as string[]) : [];

  function toggleTaskType(value: string) {
    const next = taskTypes.includes(value)
      ? taskTypes.filter((t) => t !== value)
      : [...taskTypes, value];
    onChange({ ...config, task_types: next });
  }

  return (
    <div className="space-y-3">
      <div>
        <label className="label">Какие задачи завершить</label>
        <div className="space-y-2">
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="radio"
              checked={scope === "all"}
              onChange={() => onChange({ ...config, scope: "all", task_types: [] })}
            />
            <span className="text-sm">Все открытые задачи</span>
          </label>
          <label className="flex items-center gap-2 cursor-pointer">
            <input
              type="radio"
              checked={scope === "by_type"}
              onChange={() => onChange({ ...config, scope: "by_type" })}
            />
            <span className="text-sm">Задачи типа…</span>
          </label>
        </div>
      </div>

      {scope === "by_type" && (
        <div>
          <label className="label">Типы задач</label>
          <div className="flex flex-wrap gap-2">
            {TASK_TYPES.map((t) => (
              <label
                key={t.value}
                className={`px-2 py-1 rounded border text-xs cursor-pointer ${
                  taskTypes.includes(t.value)
                    ? "bg-primary text-white border-primary"
                    : "border-gray-300"
                }`}
              >
                <input
                  type="checkbox"
                  className="hidden"
                  checked={taskTypes.includes(t.value)}
                  onChange={() => toggleTaskType(t.value)}
                />
                {t.label}
              </label>
            ))}
          </div>
        </div>
      )}
    </div>
  );
}
