"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import type { Activity } from "@/lib/types";

interface Props {
  task: Activity;
  onMutate: () => void;
}

export function TaskSummaryTab({ task, onMutate }: Props) {
  const [editingDesc, setEditingDesc] = useState(false);
  const [desc, setDesc] = useState(task.body ?? "");
  const [editingDeadline, setEditingDeadline] = useState(false);
  const [deadlineValue, setDeadlineValue] = useState(
    task.due_at ? new Date(task.due_at).toISOString().slice(0, 16) : ""
  );
  const [plannedHours, setPlannedHours] = useState(String(task.planned_hours ?? ""));
  const [actualHours, setActualHours] = useState(String(task.actual_hours ?? ""));
  const [progress, setProgress] = useState(task.progress_pct ?? 0);
  const [resultText, setResultText] = useState(task.result_text ?? "");
  const [saving, setSaving] = useState(false);

  async function saveField(body: Record<string, unknown>) {
    setSaving(true);
    try {
      await api(`/activities/${task.id}`, { method: "PATCH", body });
      onMutate();
    } finally {
      setSaving(false);
    }
  }

  async function saveDesc() {
    setEditingDesc(false);
    if (desc.trim() === (task.body ?? "")) return;
    await saveField({ body: desc.trim() });
  }

  async function saveDeadline() {
    setEditingDeadline(false);
    if (!deadlineValue) return;
    await saveField({ due_at: new Date(deadlineValue).toISOString() });
  }

  async function saveHours() {
    const p = plannedHours ? Number(plannedHours) : null;
    const a = actualHours ? Number(actualHours) : null;
    await saveField({ planned_hours: p, actual_hours: a });
  }

  async function saveProgress() {
    await saveField({ progress_pct: progress });
  }

  async function saveResult() {
    await saveField({ result_text: resultText.trim() });
  }

  const isDue = task.due_at != null;
  const isOverdue = isDue && new Date(task.due_at!) < new Date() && task.status !== "done";
  const dueText = task.due_at
    ? new Date(task.due_at).toLocaleString("ru-RU", {
        day: "numeric",
        month: "short",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      })
    : "Не задан";

  const hoursDiff = task.actual_hours != null && task.planned_hours != null
    ? task.actual_hours - task.planned_hours
    : null;

  const showProgress = task.status === "in_progress" || task.status === "done";
  const showResult = task.status === "done" || task.is_closed;

  return (
    <div className="space-y-6 p-6">
      {/* Описание */}
      <div>
        <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Описание</div>
        {editingDesc ? (
          <textarea
            className="input w-full min-h-[100px]"
            value={desc}
            onChange={(e) => setDesc(e.target.value)}
            onBlur={saveDesc}
            autoFocus
          />
        ) : (
          <div
            className="text-sm text-gray-700 dark:text-gray-300 min-h-[60px] px-3 py-2 rounded border border-transparent hover:border-gray-200 dark:hover:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
            onClick={() => setEditingDesc(true)}
          >
            {desc || <span className="text-gray-400">Добавить описание...</span>}
          </div>
        )}
      </div>

      {/* Дедлайн */}
      <div>
        <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Дедлайн</div>
        {editingDeadline ? (
          <div className="flex items-center gap-2">
            <input
              type="datetime-local"
              className="input w-auto"
              value={deadlineValue}
              onChange={(e) => setDeadlineValue(e.target.value)}
              onBlur={saveDeadline}
              autoFocus
            />
          </div>
        ) : (
          <div className="flex items-center gap-2">
            <span className={`text-sm ${isOverdue ? "text-danger font-medium" : "text-gray-700 dark:text-gray-300"}`}>
              {dueText}
            </span>
            <button
              onClick={() => setEditingDeadline(true)}
              className="text-gray-400 hover:text-primary p-0.5"
              title="Изменить"
            >
              <i className="bi bi-pencil text-xs" />
            </button>
          </div>
        )}
      </div>

      {/* Время */}
      <div>
        <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Время</div>
        <div className="flex items-center gap-4">
          <div>
            <label className="text-xs text-gray-500 block mb-1">Плановое</label>
            <div className="flex items-center gap-1">
              <input
                type="number"
                min="0"
                step="0.5"
                className="input w-20 text-sm"
                value={plannedHours}
                onChange={(e) => setPlannedHours(e.target.value)}
                onBlur={saveHours}
              />
              <span className="text-sm text-gray-500">ч</span>
            </div>
          </div>
          <div>
            <label className="text-xs text-gray-500 block mb-1">Фактическое</label>
            <div className="flex items-center gap-1">
              <input
                type="number"
                min="0"
                step="0.5"
                className="input w-20 text-sm"
                value={actualHours}
                onChange={(e) => setActualHours(e.target.value)}
                onBlur={saveHours}
              />
              <span className="text-sm text-gray-500">ч</span>
            </div>
          </div>
          {hoursDiff !== null && (
            <div className="text-sm">
              <span className={hoursDiff > 0 ? "text-danger" : "text-success"}>
                {hoursDiff > 0 ? "+" : ""}{hoursDiff}ч
              </span>
            </div>
          )}
        </div>
      </div>

      {/* Прогресс */}
      {showProgress && (
        <div>
          <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">Прогресс</div>
          <div className="flex items-center gap-3">
            <input
              type="range"
              min={0}
              max={100}
              value={progress}
              onChange={(e) => setProgress(Number(e.target.value))}
              onMouseUp={saveProgress}
              onTouchEnd={saveProgress}
              className="flex-1"
            />
            <span className="text-sm font-medium tabular-nums w-10 text-right">{progress}%</span>
          </div>
        </div>
      )}

      {/* Результат */}
      {showResult && (
        <div>
          <div className="text-sm font-medium text-gray-700 dark:text-gray-300 mb-1.5">
            Результат работы *
          </div>
          <textarea
            className="input w-full min-h-[80px]"
            placeholder="Опиши результат..."
            value={resultText}
            onChange={(e) => setResultText(e.target.value)}
            onBlur={saveResult}
          />
          <p className="text-xs text-gray-500 mt-1">Обязательное поле для закрытия задачи</p>
        </div>
      )}
    </div>
  );
}
