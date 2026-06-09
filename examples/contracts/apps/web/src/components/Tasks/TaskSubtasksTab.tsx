"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { Activity } from "@/lib/types";
import { TaskGroupRollup } from "./TaskGroupRollup";
import { TaskStatusBadge } from "./TaskStatusBadge";
import { TaskCreateDrawer } from "./TaskCreateDrawer";

interface Props {
  task: Activity;
}

export function TaskSubtasksTab({ task }: Props) {
  const [drawerOpen, setDrawerOpen] = useState(false);

  const { data: subtasks, mutate } = useSWR<Activity[]>(
    `/activities?parent_activity_id=${task.id}&kind=task`,
    fetcher
  );

  const list = subtasks ?? [];

  return (
    <div className="p-6 space-y-4">
      {list.length > 0 && <TaskGroupRollup subtasks={list} />}

      <div className="space-y-0.5">
        {list.map((t) => (
          <div
            key={t.id}
            className="flex items-center gap-2 py-2 px-3 rounded-md hover:bg-gray-50 dark:hover:bg-gray-800/50"
          >
            <span
              className={
                "w-2 h-2 rounded-full shrink-0 " +
                (t.status === "done" ? "bg-success" :
                  t.status === "in_progress" ? "bg-warning" :
                  t.status === "rejected" ? "bg-danger" : "bg-gray-400")
              }
            />
            <Link
              href={`/tasks/${t.id}`}
              className="flex-1 text-sm hover:underline truncate text-gray-700 dark:text-gray-300"
            >
              {t.title}
            </Link>
            <span className="text-xs text-gray-500 tabular-nums w-8 text-right">
              {t.progress_pct ?? 0}%
            </span>
            {t.responsible_name && (
              <span className="text-xs text-gray-500 truncate max-w-[80px]">
                {t.responsible_name}
              </span>
            )}
            <TaskStatusBadge status={t.status} isClosed={t.is_closed} />
          </div>
        ))}
      </div>

      {list.length === 0 && (
        <div className="text-center py-8">
          <p className="text-sm font-medium text-gray-500 dark:text-gray-400">Нет подзадач</p>
          <p className="text-xs text-gray-400 mt-1">Добавь первую, чтобы разбить задачу на шаги</p>
        </div>
      )}

      <button
        className="btn-secondary text-sm"
        onClick={() => setDrawerOpen(true)}
      >
        + Добавить подзадачу
      </button>

      <TaskCreateDrawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
        parentActivityId={task.id}
      />
    </div>
  );
}
