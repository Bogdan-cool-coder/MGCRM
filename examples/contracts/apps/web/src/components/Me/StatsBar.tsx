"use client";

import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { KpiCard } from "@/components/Dashboard/KpiCard";
import type { MeDashboard } from "@/lib/types";

type Period = "current_month" | "last_month" | "current_quarter" | "current_year";

const PERIOD_OPTIONS: { value: Period; label: string }[] = [
  { value: "current_month", label: "Текущий месяц" },
  { value: "last_month", label: "Прошлый месяц" },
  { value: "current_quarter", label: "Текущий квартал" },
  { value: "current_year", label: "Текущий год" },
];

interface Props {
  period: Period;
  onPeriodChange: (p: Period) => void;
  userId?: number;
}

function safePct(fact: number | null | undefined, plan: number | null | undefined): number {
  const f = fact ?? 0;
  const p = plan ?? 0;
  if (!p) return f > 0 ? 100 : 0;
  return Math.round((f / p) * 100);
}

export function StatsBar({ period, onPeriodChange, userId }: Props) {
  const key = `/me/dashboard?period=${period}${userId ? `&user_id=${userId}` : ""}`;
  const { data, isLoading } = useSWR<MeDashboard>(key, fetcher);

  // Числовые значения для KpiCard (undefined → skeleton)
  const personalFact = isLoading ? undefined : (data?.personal_income_fact ?? 0);
  const teamFact = isLoading ? undefined : (data?.team_income_fact ?? 0);
  const ftmFact = isLoading ? undefined : (data?.ftm_count_fact ?? 0);
  const scorePct = isLoading ? undefined : (data?.score_pct ?? 0);

  const personalPct = data
    ? safePct(data.personal_income_fact, data.personal_income_plan)
    : undefined;
  const teamPct = data
    ? safePct(data.team_income_fact, data.team_income_plan)
    : undefined;
  const ftmPct = data
    ? safePct(data.ftm_count_fact, data.ftm_count_plan)
    : undefined;

  const cur = data?.personal_income_currency ?? "";

  return (
    <div className="space-y-3">
      {/* Шапка: лейбл + селект периода */}
      <div className="flex items-center justify-between">
        <span className="text-[11px] uppercase tracking-wider font-semibold text-gray-400 dark:text-gray-500">
          Показатели
        </span>
        <select
          className="input w-auto text-sm"
          value={period}
          onChange={(e) => onPeriodChange(e.target.value as Period)}
          aria-label="Выбрать период"
        >
          {PERIOD_OPTIONS.map((p) => (
            <option key={p.value} value={p.value}>{p.label}</option>
          ))}
        </select>
      </div>

      {/* KPI-карточки */}
      <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
        <KpiCard
          label="Личные продажи"
          value={personalFact}
          suffix={cur ? ` ${cur}` : ""}
          trendPct={personalPct != null ? personalPct - 100 : undefined}
          iconClass="bi-person-check"
          iconBg="bg-primary/5 dark:bg-white/5"
          iconColor="text-primary dark:text-gray-300"
          invertColor={false}
        />
        <KpiCard
          label="Цель команды"
          value={teamFact}
          suffix={cur ? ` ${cur}` : ""}
          trendPct={teamPct != null ? teamPct - 100 : undefined}
          iconClass="bi-people"
          iconBg="bg-info-50 dark:bg-info-500/10"
          iconColor="text-info-600"
          invertColor={false}
        />
        <KpiCard
          label="FTM встречи"
          value={ftmFact}
          trendPct={ftmPct != null ? ftmPct - 100 : undefined}
          iconClass="bi-calendar-check"
          iconBg="bg-success-50 dark:bg-success-500/10"
          iconColor="text-success-600"
          invertColor={false}
        />
        <KpiCard
          label="Выполнение МК"
          value={scorePct}
          suffix="%"
          iconClass="bi-trophy"
          iconBg="bg-warning-50 dark:bg-warning-500/10"
          iconColor="text-warning-600"
          invertColor={false}
        />
      </div>
    </div>
  );
}

export type { Period };
