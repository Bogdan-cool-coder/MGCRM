"use client";

import { useState } from "react";
import { api } from "@/lib/api";
import type { Activity, ActivityStatus } from "@/lib/types";
import { ACTIVITY_PRIORITY_LABELS } from "@/lib/types";
import { TaskPriorityIndicator } from "./TaskPriorityIndicator";
import { TaskStatusBadge } from "./TaskStatusBadge";

const STATUS_TRANSITIONS: Record<ActivityStatus, { label: string; next: ActivityStatus }[]> = {
  new: [{ label: "В работу", next: "in_progress" }, { label: "Отклонить", next: "rejected" }],
  in_progress: [{ label: "Выполнена", next: "done" }, { label: "Отклонить", next: "rejected" }],
  done: [
    { label: "Вернуть в работу", next: "in_progress" },
    { label: "Отклонить", next: "rejected" },
  ],
  rejected: [{ label: "Восстановить", next: "new" }],
};

interface Props {
  task: Activity;
  onMutate: () => void;
}

export function TaskDetailHeader({ task, onMutate }: Props) {
  const [title, setTitle] = useState(task.title);
  const [savingTitle, setSavingTitle] = useState(false);
  const [changingStatus, setChangingStatus] = useState(false);

  async function saveTitle() {
    if (title.trim() === task.title || !title.trim()) {
      setTitle(task.title);
      return;
    }
    setSavingTitle(true);
    try {
      await api(`/activities/${task.id}`, { method: "PATCH", body: { title: title.trim() } });
      onMutate();
    } finally {
      setSavingTitle(false);
    }
  }

  async function changeStatus(status: ActivityStatus) {
    setChangingStatus(true);
    try {
      await api(`/activities/${task.id}/status`, { method: "PATCH", body: { status } });
      onMutate();
    } finally {
      setChangingStatus(false);
    }
  }

  async function closeTask() {
    setChangingStatus(true);
    try {
      await api(`/activities/${task.id}/close`, { method: "PATCH" });
      onMutate();
    } finally {
      setChangingStatus(false);
    }
  }

  const currentStatus = task.status ?? "new";
  const transitions = task.is_closed ? [] : STATUS_TRANSITIONS[currentStatus] ?? [];

  return (
    <div className="flex items-start gap-3 px-6 py-4 bg-white dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700">
      {/* Priority bar */}
      <TaskPriorityIndicator priority={task.priority} variant="bar" />

      {/* Title + meta */}
      <div className="flex-1 min-w-0">
        <input
          className={
            "text-xl font-semibold bg-transparent border-none outline-none w-full " +
            "hover:bg-gray-50 dark:hover:bg-gray-700 focus:bg-gray-50 dark:focus:bg-gray-700 " +
            "rounded px-1 -ml-1 dark:text-gray-100 " +
            (savingTitle ? "opacity-50" : "")
          }
          value={title}
          onChange={(e) => setTitle(e.target.value)}
          onBlur={saveTitle}
          onKeyDown={(e) => { if (e.key === "Enter") e.currentTarget.blur(); }}
        />
        <div className="flex items-center flex-wrap gap-2 mt-1 text-sm text-gray-500">
          <span className="font-mono">#{task.id}</span>
          {task.category_name && (
            <>
              <span>·</span>
              <span>{task.category_name}</span>
            </>
          )}
          {task.priority && task.priority !== "normal" && (
            <>
              <span>·</span>
              <span className={
                task.priority === "critical"
                  ? "badge bg-danger/10 text-danger"
                  : task.priority === "high"
                  ? "badge bg-warning/10 text-warning"
                  : "badge bg-gray-100 text-gray-500"
              }>
                {ACTIVITY_PRIORITY_LABELS[task.priority]}
              </span>
            </>
          )}
          {task.google_calendar_synced && (
            <>
              <span>·</span>
              <span className="badge bg-info/10 text-info flex items-center gap-1">
                <i className="bi-google text-xs" />
                Синхронизировано
              </span>
            </>
          )}
        </div>
      </div>

      {/* Status + actions */}
      <div className="flex items-center gap-2 shrink-0">
        <TaskStatusBadge status={task.status} isClosed={task.is_closed} />

        {!task.is_closed && transitions.map((t) => (
          <button
            key={t.next}
            className={
              "btn-secondary text-sm " +
              (t.next === "rejected" ? "text-danger" : "")
            }
            disabled={changingStatus}
            onClick={() => changeStatus(t.next)}
          >
            {t.label}
          </button>
        ))}

        {task.status === "done" && !task.is_closed && (
          <button
            className="btn-primary text-sm"
            disabled={changingStatus}
            onClick={closeTask}
          >
            Закрыть задачу
          </button>
        )}
      </div>
    </div>
  );
}
