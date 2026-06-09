"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { Sparkline } from "@/components/Sparkline";
import { safeToFixed } from "@/lib/format";
import type { OnboardingOverviewDTO } from "@/lib/types";

function formatAvgTime(hours: number | null): string {
  if (hours == null) return "—";
  const h = Math.floor(hours);
  const m = Math.round((hours % 1) * 60);
  if (h === 0) return `${m}мин`;
  if (m === 0) return `${h}ч`;
  return `${h}ч ${m}мин`;
}

interface KpiCardProps {
  label: string;
  value: React.ReactNode;
  sub?: React.ReactNode;
}

function KpiCard({ label, value, sub }: KpiCardProps) {
  return (
    <div className="card p-4 flex flex-col gap-1">
      <span className="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">{label}</span>
      <span className="text-2xl font-bold text-gray-900 dark:text-gray-100 tabular-nums">{value}</span>
      {sub && <span className="text-xs text-gray-500 dark:text-gray-400">{sub}</span>}
    </div>
  );
}

export function OverviewKpiRow() {
  const { data, isLoading, error } = useSWR<OnboardingOverviewDTO>(
    "/admin/onboarding/analytics/overview",
    fetcher
  );

  if (isLoading) {
    return (
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
        {Array.from({ length: 6 }).map((_, i) => (
          <div key={i} className="card p-4 animate-pulse h-24 bg-gray-100 dark:bg-gray-700" />
        ))}
      </div>
    );
  }

  if (error || !data) {
    return (
      <div className="text-danger text-sm mb-6">
        Не удалось загрузить KPI
      </div>
    );
  }

  const completionColor =
    (data.completion_rate_pct ?? 0) >= 50 ? "text-success" : "text-warning";

  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-6">
      {/* Всего курсов */}
      <KpiCard
        label="Всего курсов"
        value={
          <span className="flex items-end gap-1">
            {data.total_courses}
            {data.courses_sparkline_30d && data.courses_sparkline_30d.length > 0 && (
              <span className="mb-0.5">
                <Sparkline values={data.courses_sparkline_30d} width={60} height={20} />
              </span>
            )}
          </span>
        }
        sub="активных"
      />

      {/* Назначений */}
      <KpiCard
        label="Назначений"
        value={data.total_assignments}
        sub={`+${data.new_assignments_30d} за 30 дней`}
      />

      {/* Прохождений */}
      <KpiCard
        label="Прохождений"
        value={
          <span>
            {data.total_completed}{" "}
            <span className={`text-base font-semibold ${completionColor}`}>
              {safeToFixed(data.completion_rate_pct, 1)}%
            </span>
          </span>
        }
        sub="completion"
      />

      {/* Среднее время */}
      <KpiCard
        label="Среднее время"
        value={formatAvgTime(data.avg_time_to_complete_hours)}
        sub="на прохождение"
      />

      {/* Активных учеников */}
      <KpiCard
        label="Активных учеников"
        value={data.active_learners_30d}
        sub="за последние 30 дней"
      />

      {/* Просроченные mandatory */}
      <div className="card p-4 flex flex-col gap-1">
        <span className="text-xs text-gray-500 dark:text-gray-400 font-medium uppercase tracking-wide">
          Просрочено
        </span>
        <span
          className={`text-2xl font-bold tabular-nums ${
            data.overdue_mandatory > 0 ? "text-danger" : "text-gray-900 dark:text-gray-100"
          }`}
        >
          {data.overdue_mandatory}
        </span>
        <span className="text-xs text-gray-500 dark:text-gray-400">обязательных курсов</span>
      </div>
    </div>
  );
}
