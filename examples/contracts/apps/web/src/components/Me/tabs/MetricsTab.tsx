"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { SalesByDayChart } from "../metrics/SalesByDayChart";
import { TeamComparisonBar } from "../metrics/TeamComparisonBar";
import { EmptyState } from "@/components/EmptyState";
import type { MeMetrics } from "@/lib/types";

interface Props {
  userId?: number;
}

const MONTHS_RU = [
  "Январь", "Февраль", "Март", "Апрель", "Май", "Июнь",
  "Июль", "Август", "Сентябрь", "Октябрь", "Ноябрь", "Декабрь",
];

function generatePeriods() {
  const now = new Date();
  const opts: { value: string; label: string }[] = [];
  for (let i = 0; i < 6; i++) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const y = d.getFullYear();
    const m = d.getMonth() + 1;
    opts.push({
      value: `${y}-${String(m).padStart(2, "0")}`,
      label: `${MONTHS_RU[m - 1]} ${y}`,
    });
  }
  return opts;
}

/** Мини-плитка личного KPI */
interface KpiTileProps {
  label: string;
  value: string;
  icon: string;
  iconBg: string;
  iconColor: string;
  sub?: string;
  subColor?: string;
}

function KpiTile({ label, value, icon, iconBg, iconColor, sub, subColor }: KpiTileProps) {
  return (
    <div className="rounded-xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-4 flex flex-col gap-2">
      <div className="flex items-center justify-between">
        <span className="text-xs text-gray-500 dark:text-gray-400 leading-tight">{label}</span>
        <span className={`h-7 w-7 grid place-items-center rounded-lg shrink-0 ${iconBg}`}>
          <i className={`bi ${icon} text-xs ${iconColor}`} aria-hidden="true" />
        </span>
      </div>
      <div className="text-2xl font-bold tabular-nums text-gray-900 dark:text-gray-100 leading-none">
        {value}
      </div>
      {sub && (
        <div className={`text-xs truncate ${subColor ?? "text-gray-400 dark:text-gray-500"}`}>
          {sub}
        </div>
      )}
    </div>
  );
}

/** Вычисляет набор KPI-плиток из MeMetrics */
function buildKpiTiles(data: MeMetrics): KpiTileProps[] {
  const tiles: KpiTileProps[] = [];

  // 1. Средний цикл сделки
  const cycle = data.avg_cycle_days != null ? Math.round(data.avg_cycle_days) : null;
  tiles.push({
    label: "Средний цикл сделки",
    value: cycle != null ? `${cycle} дн` : "—",
    icon: "bi-clock-history",
    iconBg: "bg-primary/5 dark:bg-white/5",
    iconColor: "text-primary dark:text-gray-300",
    sub: cycle != null && cycle <= 14
      ? "Быстрые сделки"
      : cycle != null && cycle <= 30
      ? "Норма"
      : cycle != null
      ? "Долгий цикл"
      : undefined,
    subColor: cycle != null && cycle <= 14
      ? "text-success"
      : cycle != null && cycle > 30
      ? "text-warning"
      : undefined,
  });

  // 2. % выполнения личного плана
  const pct = data.personal_pct != null ? Math.round(data.personal_pct) : null;
  tiles.push({
    label: "Выполнение плана",
    value: pct != null ? `${pct}%` : "—",
    icon: "bi-bullseye",
    iconBg: pct != null && pct >= 100
      ? "bg-success/10 dark:bg-success/10"
      : "bg-warning/10 dark:bg-warning/10",
    iconColor: pct != null && pct >= 100
      ? "text-success"
      : "text-warning",
    sub: pct != null && pct >= 100 ? "План выполнен" : pct != null ? "Ниже плана" : undefined,
    subColor: pct != null && pct >= 100 ? "text-success" : "text-warning",
  });

  // 3. Ранг в команде
  const rank = data.team_rank;
  const teamSize = data.team_size;
  tiles.push({
    label: "Ранг в команде",
    value: rank != null && teamSize ? `#${rank}` : "—",
    icon: "bi-award",
    iconBg: rank === 1 ? "bg-warning/10 dark:bg-warning/10" : "bg-info/5 dark:bg-white/5",
    iconColor: rank === 1 ? "text-warning" : "text-info dark:text-gray-300",
    sub: teamSize != null && teamSize > 0 ? `из ${teamSize} менеджеров` : undefined,
  });

  // 4. Сделок в воронке (сумма counts по всем этапам)
  const funnel = data.funnel ?? [];
  const totalDeals = funnel.reduce((acc, s) => acc + s.count, 0);
  tiles.push({
    label: "Сделок в работе",
    value: totalDeals > 0 ? String(totalDeals) : "0",
    icon: "bi-kanban",
    iconBg: "bg-primary/5 dark:bg-white/5",
    iconColor: "text-primary dark:text-gray-300",
    sub: funnel.length > 0 ? `${funnel.length} этапов воронки` : undefined,
  });

  // 5. Конверсия — последний этап с conversion_pct
  const lastWithConv = [...funnel].reverse().find((s) => s.conversion_pct != null);
  const convPct = lastWithConv?.conversion_pct != null
    ? Math.round(lastWithConv.conversion_pct)
    : null;
  tiles.push({
    label: "Конверсия",
    value: convPct != null ? `${convPct}%` : "—",
    icon: "bi-funnel",
    iconBg: convPct != null && convPct >= 50
      ? "bg-success/10 dark:bg-success/10"
      : convPct != null
      ? "bg-danger/10 dark:bg-danger/10"
      : "bg-gray-50 dark:bg-white/5",
    iconColor: convPct != null && convPct >= 50
      ? "text-success"
      : convPct != null
      ? "text-danger"
      : "text-gray-400",
    sub: lastWithConv?.stage_name ?? undefined,
  });

  // 6. Активных дней продаж (days where amount > 0)
  const salesDays = (data.sales_by_day ?? []).filter((d) => d.amount > 0).length;
  tiles.push({
    label: "Активных дней",
    value: salesDays > 0 ? String(salesDays) : "0",
    icon: "bi-calendar-check",
    iconBg: "bg-info/5 dark:bg-white/5",
    iconColor: "text-info dark:text-gray-300",
    sub: salesDays > 0 ? "дней со сделками" : "нет активности",
    subColor: salesDays === 0 ? "text-gray-400" : undefined,
  });

  return tiles;
}

/** Проверяет, есть ли вообще хоть что-то в данных */
function hasAnyActivity(data: MeMetrics): boolean {
  const hasFunnel = (data.funnel ?? []).some((s) => s.count > 0);
  const hasSales = (data.sales_by_day ?? []).some((d) => d.amount > 0);
  const hasCycle = data.avg_cycle_days != null;
  return hasFunnel || hasSales || hasCycle;
}

export function MetricsTab({ userId }: Props) {
  const periods = generatePeriods();
  const [period, setPeriod] = useState(periods[0].value);

  const key = `/me/metrics?period=${period}${userId ? `&user_id=${userId}` : ""}`;
  const { data, isLoading, error } = useSWR<MeMetrics>(key, fetcher);

  return (
    <div className="space-y-5">
      {/* Шапка */}
      <div className="flex items-center justify-between">
        <span className="text-[11px] uppercase tracking-wider font-semibold text-gray-400 dark:text-gray-500">
          Показатели
        </span>
        <select
          className="input w-48"
          value={period}
          onChange={(e) => setPeriod(e.target.value)}
          aria-label="Выбрать период"
        >
          {periods.map((p) => (
            <option key={p.value} value={p.value}>{p.label}</option>
          ))}
        </select>
      </div>

      {isLoading && (
        <div className="space-y-5 animate-pulse">
          <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
            {[1, 2, 3, 4, 5, 6].map((i) => (
              <div key={i} className="rounded-xl h-24 bg-gray-100 dark:bg-gray-700" />
            ))}
          </div>
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            <div className="rounded-2xl h-44 bg-gray-100 dark:bg-gray-700" />
            <div className="rounded-2xl h-44 bg-gray-100 dark:bg-gray-700" />
          </div>
        </div>
      )}

      {!isLoading && error && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-8">
          <EmptyState icon="bi-exclamation-circle" title="Не удалось загрузить метрики" />
        </div>
      )}

      {!isLoading && !error && !data && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-8">
          <EmptyState icon="bi-bar-chart" title="Нет данных за период" />
        </div>
      )}

      {!isLoading && !error && data && (
        <div className="space-y-5">
          {/* Блок «Показатели»: сетка KPI-плиток */}
          {!hasAnyActivity(data) ? (
            <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-8">
              <EmptyState icon="bi-bar-chart-line" title="Нет активности за период" />
            </div>
          ) : (
            <div className="grid grid-cols-2 sm:grid-cols-3 gap-3">
              {buildKpiTiles(data).map((tile) => (
                <KpiTile key={tile.label} {...tile} />
              ))}
            </div>
          )}

          {/* Сравнение с командой */}
          <TeamComparisonBar
            personalPct={data.personal_pct ?? 0}
            teamAvgPct={data.team_avg_pct ?? 0}
            rank={data.team_rank ?? 0}
            teamSize={data.team_size ?? 0}
          />

          {/* Нижний ряд: Продажи по дням + Воронка */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-5">
            {/* Sales by day chart */}
            <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5">
              <h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i className="bi bi-bar-chart text-primary" aria-hidden="true" />
                Продажи по дням
              </h4>
              <SalesByDayChart data={data.sales_by_day ?? []} />
            </div>

            {/* Funnel */}
            <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5">
              <h4 className="text-sm font-semibold text-gray-900 dark:text-white mb-4 flex items-center gap-2">
                <i className="bi bi-funnel text-primary" aria-hidden="true" />
                Воронка личных сделок
              </h4>
              {(data.funnel ?? []).length === 0 ? (
                <EmptyState icon="bi-funnel" title="Нет данных" />
              ) : (
                <div className="space-y-2.5">
                  {(data.funnel ?? []).map((stage, i) => {
                    const maxCount = (data.funnel ?? [])[0]?.count ?? 1;
                    const width = maxCount > 0 ? (stage.count / maxCount) * 100 : 0;
                    const convColor =
                      stage.conversion_pct == null
                        ? "text-gray-400"
                        : stage.conversion_pct >= 60
                        ? "text-success"
                        : stage.conversion_pct >= 30
                        ? "text-warning"
                        : "text-danger";

                    return (
                      <div key={i} className="space-y-0.5">
                        <div className="flex items-center gap-2 text-xs">
                          <span className="w-32 truncate text-gray-600 dark:text-gray-400">{stage.stage_name}</span>
                          <div className="flex-1 h-2 rounded-full bg-primary/10 dark:bg-primary/20">
                            <div
                              className="h-full rounded-full bg-primary transition-all duration-500"
                              style={{ width: `${width}%` }}
                            />
                          </div>
                          <span className="w-6 text-right tabular-nums font-semibold text-gray-700 dark:text-gray-300">{stage.count}</span>
                          {stage.conversion_pct != null && (
                            <span className={`w-10 text-right font-medium ${convColor}`}>
                              {Math.round(stage.conversion_pct)}%
                            </span>
                          )}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
