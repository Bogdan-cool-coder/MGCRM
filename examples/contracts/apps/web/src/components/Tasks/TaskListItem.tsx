"use client";

import { useState } from "react";
import Link from "next/link";
import { api, errorMessage } from "@/lib/api";
import type { Activity, ActivityTargetType } from "@/lib/types";
import { ACTIVITY_TARGET_LABELS } from "@/lib/types";
import { TaskStatusBadge } from "./TaskStatusBadge";
import { TaskPriorityIndicator } from "./TaskPriorityIndicator";

interface Props {
  task: Activity;
  selected: boolean;
  onSelect: (id: number, checked: boolean) => void;
  onMutate: () => void;
}

function formatDueDate(due: string | null): { text: string; className: string } | null {
  if (!due) return null;
  const dueDate = new Date(due);
  const now = new Date();
  const diffMs = dueDate.getTime() - now.getTime();
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

  if (diffDays < 0) {
    const overdueDays = Math.abs(diffDays);
    return { text: `просрочено ${overdueDays} дн`, className: "text-danger font-medium" };
  }
  if (diffDays === 0) {
    return { text: "сегодня", className: "text-warning font-medium" };
  }
  const formatted = dueDate.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
  return { text: formatted, className: "text-gray-500" };
}

function formatFileSize(bytes: number | null): string {
  if (!bytes) return "";
  if (bytes < 1024) return `${bytes} Б`;
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} КБ`;
  return `${(bytes / (1024 * 1024)).toFixed(1)} МБ`;
}

export function TaskListItem({ task, selected, onSelect, onMutate }: Props) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [rowError, setRowError] = useState<string | null>(null);

  const isClosed = task.is_closed === true;
  const titleClass = isClosed
    ? "line-through text-gray-400"
    : task.status === "rejected"
    ? "line-through text-danger"
    : "text-gray-900 dark:text-gray-100";

  const dueInfo = formatDueDate(task.due_at);

  async function toggleFavorite() {
    setMenuOpen(false);
    setRowError(null);
    try {
      await api(`/activities/${task.id}/favorite`, { method: "PATCH" });
      onMutate();
    } catch (err: unknown) {
      setRowError(errorMessage(err, "Не удалось обновить избранное"));
    }
  }

  async function togglePin() {
    setMenuOpen(false);
    setRowError(null);
    try {
      await api(`/activities/${task.id}/pin`, { method: "PATCH" });
      onMutate();
    } catch (err: unknown) {
      setRowError(errorMessage(err, "Не удалось закрепить задачу"));
    }
  }

  async function extendDeadline(days: number) {
    setMenuOpen(false);
    setRowError(null);
    try {
      // Бэк принимает {days} и сам считает новую дату (now + days).
      await api(`/activities/${task.id}/extend-deadline`, {
        method: "POST",
        body: { days, reason: `Перенос на +${days} дн` },
      });
      // Перечитываем список, чтобы убедиться, что дедлайн реально сдвинулся.
      onMutate();
    } catch (err: unknown) {
      setRowError(errorMessage(err, "Не удалось перенести дедлайн"));
    }
  }

  const progress = task.progress_pct ?? 0;

  return (
    <div
      className={
        "group flex items-start gap-3 px-4 py-3 border-b border-gray-100 dark:border-gray-700 " +
        "hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors " +
        (selected ? "bg-primary/5" : "")
      }
    >
      {/* Priority bar */}
      <div className="mt-1">
        <TaskPriorityIndicator priority={task.priority} variant="bar" />
      </div>

      {/* Checkbox */}
      <input
        type="checkbox"
        className="w-4 h-4 mt-1 shrink-0 cursor-pointer"
        checked={selected}
        onChange={(e) => onSelect(task.id, e.target.checked)}
      />

      {/* Star */}
      <button
        onClick={toggleFavorite}
        className="mt-0.5 shrink-0 text-gray-400 hover:text-warning transition-colors"
        title={task.is_favorite ? "Убрать из избранного" : "В избранное"}
      >
        <i className={`bi ${task.is_favorite ? "bi-star-fill text-warning" : "bi-star"} text-sm`} />
      </button>

      {/* Main content */}
      <div className="flex-1 min-w-0">
        {/* Row 1: title + status + menu */}
        <div className="flex items-center gap-2">
          {task.color_label && (
            <span
              className="w-3 h-3 rounded-sm shrink-0"
              style={{ background: task.color_label }}
            />
          )}
          <Link
            href={`/tasks/${task.id}`}
            className={`text-sm font-medium hover:underline flex-1 truncate ${titleClass}`}
          >
            {task.title}
          </Link>
          <TaskStatusBadge status={task.status} isClosed={isClosed} />
          {/* Kebab menu */}
          <div className="relative opacity-0 group-hover:opacity-100 transition-opacity">
            <button
              onClick={() => setMenuOpen(!menuOpen)}
              className="p-1 rounded hover:bg-gray-200 dark:hover:bg-gray-700 text-gray-400"
            >
              <i className="bi bi-three-dots-vertical text-sm" />
            </button>
            {menuOpen && (
              <>
                <div className="fixed inset-0 z-10" onClick={() => setMenuOpen(false)} />
                <div className="absolute right-0 top-6 z-20 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 w-48 text-sm">
                  <Link href={`/tasks/${task.id}`} className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 w-full text-left" onClick={() => setMenuOpen(false)}>
                    <i className="bi bi-box-arrow-up-right text-xs" /> Открыть
                  </Link>
                  <button className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 w-full text-left" onClick={togglePin}>
                    <i className={`bi ${task.is_pinned ? "bi-pin-fill" : "bi-pin"} text-xs`} />
                    {task.is_pinned ? "Открепить" : "Закрепить"}
                  </button>
                  <button className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 w-full text-left" onClick={toggleFavorite}>
                    <i className={`bi ${task.is_favorite ? "bi-star-fill" : "bi-star"} text-xs`} />
                    {task.is_favorite ? "Убрать из избранного" : "В избранное"}
                  </button>
                  <div className="border-t border-gray-100 dark:border-gray-700 my-1" />
                  <div className="px-3 py-1 text-[10px] text-gray-400 uppercase tracking-wide">Перенести дедлайн</div>
                  <button className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 w-full text-left" onClick={() => extendDeadline(1)}>
                    <i className="bi bi-calendar-plus text-xs" /> +1 день
                  </button>
                  <button className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 w-full text-left" onClick={() => extendDeadline(3)}>
                    <i className="bi bi-calendar-plus text-xs" /> +3 дня
                  </button>
                  <button className="flex items-center gap-2 px-3 py-1.5 hover:bg-gray-50 dark:hover:bg-gray-700 w-full text-left" onClick={() => extendDeadline(7)}>
                    <i className="bi bi-calendar-plus text-xs" /> +1 неделю
                  </button>
                </div>
              </>
            )}
          </div>
        </div>

        {/* Row 2: ID + category + target */}
        <div className="flex items-center gap-1.5 mt-0.5 text-xs text-gray-500">
          <span className="font-mono">#{task.id}</span>
          {task.category_name && (
            <>
              <span>·</span>
              <span>Категория: {task.category_name}</span>
            </>
          )}
          {task.target_type && task.target_id && (
            <>
              <span>·</span>
              <span className="badge bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-400 text-[10px] px-1.5 py-0.5 rounded">
                {ACTIVITY_TARGET_LABELS[task.target_type as ActivityTargetType]}
              </span>
            </>
          )}
        </div>

        {/* Row 3: due date + priority */}
        {(dueInfo || (task.priority && task.priority !== "normal")) && (
          <div className="flex items-center gap-2 mt-0.5 text-xs">
            {dueInfo && (
              <span className={`flex items-center gap-1 ${dueInfo.className}`}>
                {dueInfo.className.includes("danger") && <i className="bi bi-clock-history text-[10px]" />}
                {dueInfo.text}
              </span>
            )}
            {task.priority && task.priority !== "normal" && (
              <>
                {dueInfo && <span className="text-gray-300">·</span>}
                <span className="text-gray-500">
                  Приоритет: {task.priority === "critical" ? <span className="text-danger font-medium">Критический</span> : task.priority === "high" ? <span className="text-warning">Высокий</span> : "Низкий"}
                </span>
              </>
            )}
          </div>
        )}

        {/* Row 4: progress + responsible + created */}
        <div className="flex items-center gap-3 mt-1">
          {(task.progress_pct != null || task.status === "in_progress" || task.status === "done") && (
            <div className="flex items-center gap-1.5">
              <div className="w-20 h-1.5 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
                <div
                  className="h-full bg-primary transition-all"
                  style={{ width: `${progress}%` }}
                />
              </div>
              <span className="text-[10px] text-gray-500 tabular-nums">{progress}%</span>
            </div>
          )}
          {task.responsible_name && (
            <span className="text-xs text-gray-500 truncate max-w-[100px]">
              {task.responsible_name}
            </span>
          )}
          {task.created_by_name && (
            <span className="text-xs text-gray-400">
              Создал: {task.created_by_name}
            </span>
          )}
          {task.created_at && (
            <span className="text-xs text-gray-400 tabular-nums">
              {new Date(task.created_at).toLocaleDateString("ru-RU", { day: "numeric", month: "short" })}
            </span>
          )}
        </div>

        {/* Row 5: hours + tags */}
        {((task.planned_hours != null || task.actual_hours != null) || (task.tags && task.tags.length > 0)) && (
          <div className="flex items-center flex-wrap gap-1.5 mt-0.5">
            {(task.planned_hours != null || task.actual_hours != null) && (
              <span className="text-xs text-gray-500">
                {task.actual_hours ?? 0}/{task.planned_hours ?? 0}ч
              </span>
            )}
            {task.tags?.map((tag) => (
              <span
                key={tag}
                className="badge badge-info text-[10px]"
              >
                {tag}
              </span>
            ))}
          </div>
        )}

        {rowError && (
          <div className="mt-1 flex items-center gap-1 text-xs text-danger">
            <i className="bi bi-exclamation-circle text-[10px]" />
            <span>{rowError}</span>
            <button
              type="button"
              className="ml-1 underline hover:no-underline"
              onClick={() => setRowError(null)}
            >
              скрыть
            </button>
          </div>
        )}
      </div>
    </div>
  );
}
