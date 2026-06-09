"use client";

import type { CourseProgressStatus } from "@/lib/types";

type CellStatus = CourseProgressStatus | "unassigned";

interface Props {
  status: CellStatus;
  percent: number;
  onClick?: () => void;
}

const STATUS_BADGE: Record<CellStatus, { label: string; badgeClass: string; barClass: string }> = {
  completed:   { label: "Завершён",   badgeClass: "bg-success/10 text-success",    barClass: "bg-success" },
  in_progress: { label: "В процессе", badgeClass: "bg-info/10 text-info",          barClass: "bg-primary-light" },
  overdue:     { label: "Просрочен",  badgeClass: "bg-danger/10 text-danger",      barClass: "bg-danger" },
  not_started: { label: "Не начат",   badgeClass: "bg-gray-100 text-gray-500",     barClass: "bg-gray-300" },
  unassigned:  { label: "—",          badgeClass: "",                               barClass: "" },
};

export function ProgressMatrixCell({ status, percent, onClick }: Props) {
  if (status === "unassigned") {
    return (
      <td className="px-3 py-2 text-center text-xs text-gray-400 italic cursor-default">—</td>
    );
  }

  const meta = STATUS_BADGE[status];

  return (
    <td
      className="px-3 py-2 hover:bg-gray-50 cursor-pointer transition-colors"
      onClick={onClick}
    >
      <div className="min-w-[80px]">
        <div className="flex items-center justify-between mb-1">
          <span className="text-xs font-medium tabular-nums">{percent}%</span>
          <span className={`badge text-[10px] px-1.5 py-0.5 rounded-full ${meta.badgeClass}`}>
            {meta.label}
          </span>
        </div>
        <div className="h-1.5 rounded-full bg-gray-200">
          <div
            className={`h-full rounded-full ${meta.barClass} transition-all`}
            style={{ width: `${Math.min(100, Math.max(0, percent))}%` }}
          />
        </div>
      </div>
    </td>
  );
}
