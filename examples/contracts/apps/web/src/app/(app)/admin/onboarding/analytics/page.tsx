"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { KpiCard } from "@/components/Dashboard/KpiCard";
import { CourseCompletionBars } from "@/components/Onboarding/Analytics/CourseCompletionBars";
import { ActivitySparkline } from "@/components/Onboarding/Analytics/ActivitySparkline";
import { StatusDonut } from "@/components/Onboarding/Analytics/StatusDonut";
import { HardQuestionsList } from "@/components/Onboarding/Analytics/HardQuestionsList";
import { DropOffFunnel } from "@/components/Onboarding/Analytics/DropOffFunnel";
import { useToast } from "@/components/ui/Toast";
import { safeToFixed } from "@/lib/format";
import type { OnboardingOverviewDTO } from "@/lib/types";

// ─── Export button ────────────────────────────────────────────────────────────

function ExportButton() {
  const { toast } = useToast();
  const [exporting, setExporting] = useState(false);

  async function handleExport() {
    setExporting(true);
    try {
      const response = await fetch("/api/admin/onboarding/analytics/export", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify({}),
      });

      if (!response.ok) throw new Error("Export failed");

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "onboarding-analytics.xlsx";
      a.click();
      URL.revokeObjectURL(url);
      toast.success("Экспорт завершён", "Файл загружен на устройство");
    } catch {
      toast.error("Не удалось экспортировать данные");
    } finally {
      setExporting(false);
    }
  }

  return (
    <button
      onClick={handleExport}
      disabled={exporting}
      className="btn-secondary flex items-center gap-2"
    >
      <i className="bi bi-file-earmark-excel" aria-hidden="true" />
      {exporting ? "Готовим…" : "Экспорт Excel"}
    </button>
  );
}

// ─── Helpers ──────────────────────────────────────────────────────────────────

function formatAvgTime(hours: number | null): string {
  if (hours == null) return "—";
  const h = Math.floor(hours);
  const m = Math.round((hours % 1) * 60);
  if (h === 0) return `${m} мин`;
  if (m === 0) return `${h} ч`;
  return `${h}ч ${m}мин`;
}

// ─── KPI row with new KpiCard ─────────────────────────────────────────────────

function KpiRow() {
  const { data, isLoading } = useSWR<OnboardingOverviewDTO>(
    "/admin/onboarding/analytics/overview",
    fetcher
  );

  if (isLoading) {
    return (
      <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
        {Array.from({ length: 6 }).map((_, i) => (
          <KpiCard key={i} label="" value={undefined} />
        ))}
      </div>
    );
  }

  if (!data) return null;

  const completionRate = safeToFixed(data.completion_rate_pct, 1);
  const avgTimeStr = formatAvgTime(data.avg_time_to_complete_hours);

  return (
    <div className="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-6 gap-4 mb-8">
      <KpiCard
        label="Всего курсов"
        value={data.total_courses}
        iconClass="bi-collection"
        iconBg="bg-primary/10 dark:bg-primary/20"
        iconColor="text-primary"
        sparkline={data.courses_sparkline_30d ?? undefined}
        sparklineColor="#172747"
      />
      <KpiCard
        label="Назначений"
        value={data.total_assignments}
        trendPct={data.new_assignments_30d ?? null}
        iconClass="bi-person-check"
        iconBg="bg-info-50 dark:bg-info-500/10"
        iconColor="text-info-600"
      />
      <KpiCard
        label="Прохождений"
        value={data.total_completed}
        suffix={` (${completionRate}%)`}
        iconClass="bi-mortarboard"
        iconBg="bg-success-50 dark:bg-success-500/10"
        iconColor="text-success-600"
      />
      <KpiCard
        label="Среднее время"
        value={avgTimeStr}
        iconClass="bi-clock-history"
        iconBg="bg-warning-50 dark:bg-warning-500/10"
        iconColor="text-warning-600"
      />
      <KpiCard
        label="Активных учеников"
        value={data.active_learners_30d}
        iconClass="bi-people"
        iconBg="bg-info-50 dark:bg-info-500/10"
        iconColor="text-info-600"
      />
      <KpiCard
        label="Просрочено"
        value={data.overdue_mandatory}
        iconClass="bi-exclamation-triangle"
        iconBg={data.overdue_mandatory > 0
          ? "bg-danger-50 dark:bg-danger-500/10"
          : "bg-gray-100 dark:bg-gray-700"}
        iconColor={data.overdue_mandatory > 0
          ? "text-danger-600 dark:text-danger-500"
          : "text-gray-400"}
      />
    </div>
  );
}

// ─── Dashboard ────────────────────────────────────────────────────────────────

function AnalyticsDashboard() {
  const { data, isLoading } = useSWR<OnboardingOverviewDTO>(
    "/admin/onboarding/analytics/overview",
    fetcher
  );

  return (
    <div className="px-8 py-6">
      <KpiRow />

      {/* Charts row */}
      <div className="grid grid-cols-1 lg:grid-cols-2 gap-6 mb-6">
        <CourseCompletionBars data={data?.completions_by_course} isLoading={isLoading} />
        <StatusDonut data={data?.status_distribution} isLoading={isLoading} />
      </div>

      {/* Activity sparkline */}
      <div className="mb-6">
        <ActivitySparkline data={data?.activity_by_day_90d} isLoading={isLoading} />
      </div>

      <HardQuestionsList />
      <DropOffFunnel />
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function OnboardingAnalyticsPage() {
  return (
    <RoleGate allowed={["admin", "director"]}>
      <div>
        <PageHeader
          title="Аналитика онбординга"
          description="Прогресс команды, сложные вопросы и воронка отвала"
          actions={<ExportButton />}
        />
        <AnalyticsDashboard />
      </div>
    </RoleGate>
  );
}
