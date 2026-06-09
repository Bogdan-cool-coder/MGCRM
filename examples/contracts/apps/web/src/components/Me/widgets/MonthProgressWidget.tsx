"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { formatCurrency, formatAmount } from "@/lib/format";
import type { MeDashboard } from "@/lib/types";

interface Props {
  userId?: number;
}

const MONTHS = ["Январь", "Февраль", "Март", "Апрель", "Май", "Июнь", "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь"];

function currentYm(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function ymLabel(ym: string): string {
  const [y, m] = ym.split("-").map(Number);
  return `${MONTHS[m - 1]} ${y}`;
}

function shiftYm(ym: string, delta: number): string {
  const [y, m] = ym.split("-").map(Number);
  const d = new Date(y, m - 1 + delta, 1);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
}

interface BarProps {
  label: string;
  fact: number | null | undefined;
  plan: number | null | undefined;
  currency?: string;
}

function ProgressBar({ label, fact, plan, currency }: BarProps) {
  const f = fact ?? 0;
  const p = plan ?? 0;
  const pct = p > 0 ? Math.min((f / p) * 100, 100) : 0;
  const displayPct = p > 0 ? Math.round((f / p) * 100) : 0;

  let barColor = "bg-danger";
  if (displayPct >= 100) barColor = "bg-success";
  else if (displayPct >= 80) barColor = "bg-warning";
  else if (displayPct >= 50) barColor = "bg-primary";

  return (
    <div className="space-y-1.5">
      <div className="flex justify-between items-center text-xs">
        <span className="text-gray-600 dark:text-gray-400 font-medium">{label}</span>
        <span className="tabular-nums font-semibold text-gray-700 dark:text-gray-300">{displayPct}%</span>
      </div>
      <div className="h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
        <div
          className={`h-full rounded-full transition-all duration-500 ${barColor}`}
          style={{ width: `${pct}%` }}
          role="progressbar"
          aria-valuenow={displayPct}
          aria-valuemin={0}
          aria-valuemax={100}
        />
      </div>
      <div className="text-[11px] text-gray-400 flex justify-between">
        <span>{formatCurrency(fact, currency ?? null)}</span>
        <span>план {formatAmount(plan)}</span>
      </div>
    </div>
  );
}

export function MonthProgressWidget({ userId }: Props) {
  const thisYm = currentYm();
  const [ym, setYm] = useState<string>(thisYm);

  const key = `/me/dashboard?period=${ym}${userId ? `&user_id=${userId}` : ""}`;
  const { data, isLoading } = useSWR<MeDashboard>(key, fetcher);

  const isAtCurrent = ym === thisYm;
  const ftmFact = data?.ftm_count_fact ?? 0;
  const ftmPlan = data?.ftm_count_plan ?? 0;
  const ftmPct = ftmPlan > 0 ? Math.min((ftmFact / ftmPlan) * 100, 100) : 0;
  const ftmBarColor = ftmFact >= ftmPlan ? "bg-success" : "bg-primary";

  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 space-y-5">
      <div className="flex items-center justify-between">
        <span className="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <i className="bi bi-graph-up-arrow text-primary" aria-hidden="true" />
          Прогресс · {ymLabel(ym)}
        </span>
        <div className="flex items-center gap-0.5">
          <button
            type="button"
            onClick={() => setYm(shiftYm(ym, -1))}
            className="btn-ghost px-2 py-1 text-sm"
            title="Предыдущий месяц"
            aria-label="Предыдущий месяц"
          >
            <i className="bi bi-chevron-left" aria-hidden="true" />
          </button>
          <button
            type="button"
            onClick={() => !isAtCurrent && setYm(shiftYm(ym, 1))}
            disabled={isAtCurrent}
            className="btn-ghost px-2 py-1 text-sm disabled:opacity-30"
            title="Следующий месяц"
            aria-label="Следующий месяц"
          >
            <i className="bi bi-chevron-right" aria-hidden="true" />
          </button>
        </div>
      </div>

      {isLoading ? (
        <div className="space-y-4 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="space-y-1.5">
              <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded w-24" />
              <div className="h-2 bg-gray-100 dark:bg-gray-700 rounded-full" />
            </div>
          ))}
        </div>
      ) : !data ? (
        <div className="text-sm text-gray-400 py-6 text-center">Нет данных за месяц</div>
      ) : (
        <div className="space-y-4">
          <ProgressBar
            label="Личные продажи"
            fact={data.personal_income_fact}
            plan={data.personal_income_plan}
            currency={data.personal_income_currency ?? undefined}
          />
          <ProgressBar
            label="Командный план"
            fact={data.team_income_fact}
            plan={data.team_income_plan}
            currency={data.personal_income_currency ?? undefined}
          />
          {/* FTM встречи */}
          <div className="space-y-1.5">
            <div className="flex justify-between items-center text-xs">
              <span className="text-gray-600 dark:text-gray-400 font-medium">FTM встречи</span>
              <span className="tabular-nums font-semibold text-gray-700 dark:text-gray-300">
                {ftmFact} / {ftmPlan}
              </span>
            </div>
            <div className="h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
              <div
                className={`h-full rounded-full transition-all duration-500 ${ftmBarColor}`}
                style={{ width: `${ftmPct}%` }}
                role="progressbar"
                aria-valuenow={ftmFact}
                aria-valuemin={0}
                aria-valuemax={ftmPlan}
              />
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
