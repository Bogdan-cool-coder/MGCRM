"use client";

import type { Activity } from "@/lib/types";

interface Props {
  subtasks: Activity[];
}

interface RollupStats {
  done: number;
  in_progress: number;
  rejected: number;
  new: number;
  total: number;
  completion_pct: number;
  total_planned_hours: number;
  total_actual_hours: number;
}

function computeStats(subtasks: Activity[]): RollupStats {
  const stats: RollupStats = {
    done: 0,
    in_progress: 0,
    rejected: 0,
    new: 0,
    total: subtasks.length,
    completion_pct: 0,
    total_planned_hours: 0,
    total_actual_hours: 0,
  };
  for (const t of subtasks) {
    const s = t.status ?? "new";
    if (s === "done") stats.done++;
    else if (s === "in_progress") stats.in_progress++;
    else if (s === "rejected") stats.rejected++;
    else stats.new++;
    stats.total_planned_hours += t.planned_hours ?? 0;
    stats.total_actual_hours += t.actual_hours ?? 0;
  }
  stats.completion_pct = stats.total > 0 ? Math.round((stats.done / stats.total) * 100) : 0;
  return stats;
}

export function TaskGroupRollup({ subtasks }: Props) {
  const stats = computeStats(subtasks);

  return (
    <div className="bg-gray-50 dark:bg-gray-800/50 rounded-lg p-4 space-y-3">
      <div className="flex flex-wrap gap-3 text-sm">
        <span className="flex items-center gap-1.5">
          <span className="w-2 h-2 rounded-full bg-success" />
          <span>{stats.done} выполнено</span>
        </span>
        <span className="flex items-center gap-1.5">
          <span className="w-2 h-2 rounded-full bg-warning" />
          <span>{stats.in_progress} в работе</span>
        </span>
        {stats.rejected > 0 && (
          <span className="flex items-center gap-1.5">
            <span className="w-2 h-2 rounded-full bg-danger" />
            <span>{stats.rejected} отклонено</span>
          </span>
        )}
        <span className="text-gray-500">= {stats.total} всего</span>
      </div>

      <div className="flex items-center gap-3">
        <div className="flex-1 h-2 bg-gray-200 dark:bg-gray-700 rounded-full overflow-hidden">
          <div
            className="h-full bg-primary transition-all"
            style={{ width: `${stats.completion_pct}%` }}
          />
        </div>
        <span className="text-sm font-medium tabular-nums">{stats.completion_pct}%</span>
      </div>

      {(stats.total_planned_hours > 0 || stats.total_actual_hours > 0) && (
        <div className="flex gap-4 text-sm text-gray-600 dark:text-gray-400">
          <span>Плановое время: <b>{stats.total_planned_hours}ч</b></span>
          <span>Фактическое: <b>{stats.total_actual_hours}ч</b></span>
        </div>
      )}
    </div>
  );
}
