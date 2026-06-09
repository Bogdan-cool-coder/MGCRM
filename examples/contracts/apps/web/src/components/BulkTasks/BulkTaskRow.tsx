"use client";

import { useMemo } from "react";
import {
  BULK_KIND_LABELS,
  BULK_STATUS_BADGE,
  BULK_STATUS_LABELS,
  BULK_TARGET_TYPE_LABELS,
  type BulkTask,
} from "@/lib/types";
import { formatRelativeTime } from "@/lib/format";

interface Props {
  task: BulkTask;
  authorName: string | undefined;
  isHighlighted: boolean;
  onDownload: (task: BulkTask) => void;
  onCancel: (task: BulkTask) => void;
  onDelete: (task: BulkTask) => void;
}

export function BulkTaskRow({
  task,
  authorName,
  isHighlighted,
  onDownload,
  onCancel,
  onDelete,
}: Props) {
  const progress = useMemo(() => {
    if (task.total_count === 0) return 0;
    return Math.round(((task.success_count + task.failed_count) / task.total_count) * 100);
  }, [task.total_count, task.success_count, task.failed_count]);

  const isActive = task.status === "pending" || task.status === "running";
  const canDownload = task.status === "success" || (task.status === "failed" && task.success_count > 0);

  return (
    <tr
      data-task-id={task.id}
      className={
        "border-t border-gray-200 align-top transition-colors " +
        (isHighlighted ? "bg-primary/5 ring-1 ring-primary/30" : "hover:bg-gray-50")
      }
    >
      <td className="px-3 py-3 tabular-nums text-gray-500 text-sm">#{task.id}</td>
      <td className="px-3 py-3">
        <div className="text-sm font-medium">{BULK_KIND_LABELS[task.kind] ?? task.kind}</div>
        {task.template_code && (
          <div className="text-xs text-gray-500 font-mono truncate max-w-[200px]" title={task.template_code}>
            {task.template_code}
          </div>
        )}
        <div className="text-xs text-gray-500 mt-0.5">
          {BULK_TARGET_TYPE_LABELS[task.target_type] ?? task.target_type}: {task.total_count}
        </div>
      </td>
      <td className="px-3 py-3">
        <span className={`inline-flex items-center px-2 py-0.5 rounded-full text-xs ${BULK_STATUS_BADGE[task.status]}`}>
          {BULK_STATUS_LABELS[task.status] ?? task.status}
        </span>
      </td>
      <td className="px-3 py-3 min-w-[160px]">
        <div className="flex items-center gap-2">
          <div className="flex-1 h-1.5 rounded-full bg-gray-200 overflow-hidden">
            <div
              className={
                "h-full transition-all " +
                (task.status === "failed"
                  ? "bg-danger"
                  : task.status === "cancelled"
                  ? "bg-gray-400"
                  : "bg-primary-light")
              }
              style={{ width: `${progress}%` }}
            />
          </div>
          <span className="text-xs tabular-nums text-gray-500 w-9 text-right">{progress}%</span>
        </div>
        <div className="text-[11px] text-gray-500 mt-1">
          Готово: <span className="tabular-nums text-success">{task.success_count}</span>
          {task.failed_count > 0 && (
            <>
              {" · "}Ошибок: <span className="tabular-nums text-danger">{task.failed_count}</span>
            </>
          )}
        </div>
        {task.error_text && (
          <div className="text-[11px] text-danger mt-1 truncate max-w-[260px]" title={task.error_text}>
            {task.error_text}
          </div>
        )}
      </td>
      <td className="px-3 py-3 text-sm text-gray-600">
        <div title={new Date(task.created_at).toLocaleString("ru-RU")}>
          {formatRelativeTime(task.created_at)}
        </div>
        {authorName && <div className="text-xs text-gray-500">{authorName}</div>}
      </td>
      <td className="px-3 py-3 text-right whitespace-nowrap">
        {canDownload && (
          <button
            onClick={() => onDownload(task)}
            className="btn-ghost text-sm"
            title="Скачать архив"
          >
            <i className="bi bi-download" /> Скачать
          </button>
        )}
        {isActive ? (
          <button
            onClick={() => onCancel(task)}
            className="btn-ghost text-sm text-danger ml-1"
            title="Отменить задачу"
          >
            <i className="bi bi-x-circle" /> Отменить
          </button>
        ) : (
          <button
            onClick={() => onDelete(task)}
            className="btn-ghost text-sm text-danger ml-1"
            title="Удалить запись"
          >
            <i className="bi bi-trash" /> Удалить
          </button>
        )}
      </td>
    </tr>
  );
}
