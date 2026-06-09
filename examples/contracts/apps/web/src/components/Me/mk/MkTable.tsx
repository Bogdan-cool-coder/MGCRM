"use client";

import { useState } from "react";
import { MkStatusBadge } from "./MkStatusBadge";
import { MkBreakdown } from "./MkBreakdown";
import type { MotivationalCard } from "@/lib/types";

interface Props {
  card: MotivationalCard;
}

function fmt(n: number | null | undefined, cur?: string) {
  return `${(n ?? 0).toLocaleString("ru-RU")}${cur ? " " + cur : ""}`;
}

function safePct(fact: number | null | undefined, plan: number | null | undefined): number {
  const f = fact ?? 0;
  const p = plan ?? 0;
  if (!p) return f > 0 ? 100 : 0;
  return Math.round((f / p) * 100);
}

export function MkTable({ card }: Props) {
  const [commissionExpanded, setCommissionExpanded] = useState(false);
  const [kpiExpanded, setKpiExpanded] = useState(false);
  const [tooltip, setTooltip] = useState<string | null>(null);

  const salaryPct = 100;
  const commissionPct = safePct(card.commission_amount_fact, card.commission_amount_plan);
  const kpiFact = card.fact_team_bonus_proportional_amount + card.fact_team_bonus_equal_amount;
  const kpiPct = safePct(kpiFact, card.bonus_pool_amount);

  const rub_uzs = card.exchange_rates_snapshot["RUB_UZS"] ?? card.exchange_rates_snapshot["RUB/UZS"] ?? 1;
  const kzt_uzs = card.exchange_rates_snapshot["KZT_UZS"] ?? card.exchange_rates_snapshot["KZT/UZS"] ?? 1;

  const commissionPlanUZS = Math.round(card.commission_amount_plan * rub_uzs);
  const commissionFactUZS = Math.round(card.commission_amount_fact * rub_uzs);
  const kpiPlanUZS = Math.round(card.bonus_pool_amount * kzt_uzs);
  const kpiFactUZS = Math.round(kpiFact * kzt_uzs);

  function Tooltip({ id, text }: { id: string; text: string }) {
    const isOpen = tooltip === id;
    return (
      <span className="relative inline-block">
        <button
          type="button"
          onClick={() => setTooltip(isOpen ? null : id)}
          className="text-gray-400 hover:text-gray-600 ml-1"
        >
          <i className="bi bi-info-circle text-xs" />
        </button>
        {isOpen && (
          <div className="absolute z-10 bg-gray-900 text-white text-xs p-2 rounded shadow-lg w-64 left-0 top-6">
            {text}
          </div>
        )}
      </span>
    );
  }

  return (
    <div className="overflow-x-auto">
      <table className="w-full text-sm">
        <thead className="bg-gray-50 dark:bg-gray-700/50">
          <tr>
            <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Key points</th>
            <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Валюта</th>
            <th className="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Plan</th>
            <th className="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Fact</th>
            <th className="px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">%</th>
            <th className="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Plan UZS</th>
            <th className="text-right px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Fact UZS</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
          {/* Оклад */}
          <tr className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
            <td className="px-4 py-3">
              Оклад (базовый)
              <Tooltip id="salary" text="Выплачивается в следующем месяце за текущим" />
            </td>
            <td className="px-4 py-3 text-gray-500">{card.base_salary_currency}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(card.base_salary_amount)}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(card.base_salary_amount)}</td>
            <td className="px-4 py-3"><MkStatusBadge pct={salaryPct} /></td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(card.base_salary_amount)}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(card.base_salary_amount)}</td>
          </tr>

          {/* Комиссия */}
          <tr
            className="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
            onClick={() => setCommissionExpanded(!commissionExpanded)}
          >
            <td className="px-4 py-3">
              Комиссия
              <Tooltip
                id="commission"
                text="10% от новых поступлений зачисленных на расчётный счёт. Только личные сделки. Только первый платёж. Выплачивается сразу при поступлении."
              />
              <i className={`bi ${commissionExpanded ? "bi-chevron-up" : "bi-chevron-down"} ml-2 text-xs text-gray-400`} />
            </td>
            <td className="px-4 py-3 text-gray-500">{card.personal_income_currency}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(card.commission_amount_plan)}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(card.commission_amount_fact)}</td>
            <td className="px-4 py-3"><MkStatusBadge pct={commissionPct} /></td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(commissionPlanUZS)}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(commissionFactUZS)}</td>
          </tr>

          {commissionExpanded && (
            <tr className="bg-gray-50 dark:bg-gray-800/50">
              <td colSpan={7} className="px-6 py-2">
                <MkBreakdown items={card.fact_commission_breakdown} />
              </td>
            </tr>
          )}

          {/* KPI */}
          <tr
            className="hover:bg-gray-50 dark:hover:bg-gray-800/50 cursor-pointer"
            onClick={() => setKpiExpanded(!kpiExpanded)}
          >
            <td className="px-4 py-3">
              KPI (командный бонус)
              <Tooltip
                id="kpi"
                text="Командный бонус: 60% пропорционально личному результату + 40% поровну. Минимальный порог выполнения — 80%."
              />
              <i className={`bi ${kpiExpanded ? "bi-chevron-up" : "bi-chevron-down"} ml-2 text-xs text-gray-400`} />
            </td>
            <td className="px-4 py-3 text-gray-500">KZT</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(card.bonus_pool_amount)}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(kpiFact)}</td>
            <td className="px-4 py-3"><MkStatusBadge pct={kpiPct} /></td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(kpiPlanUZS)}</td>
            <td className="px-4 py-3 text-right tabular-nums">{fmt(kpiFactUZS)}</td>
          </tr>

          {kpiExpanded && (
            <>
              <tr className="bg-gray-50 dark:bg-gray-800/50 text-xs">
                <td className="px-8 py-2 text-gray-500">Часть 1 (60%, пропорция)</td>
                <td className="px-4 py-2 text-gray-400">KZT</td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(Math.round(card.bonus_pool_amount * 0.6))}</td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(card.fact_team_bonus_proportional_amount)}</td>
                <td className="px-4 py-2">
                  <MkStatusBadge pct={safePct(card.fact_team_bonus_proportional_amount, card.bonus_pool_amount * 0.6)} />
                </td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(Math.round(card.bonus_pool_amount * 0.6 * kzt_uzs))}</td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(Math.round(card.fact_team_bonus_proportional_amount * kzt_uzs))}</td>
              </tr>
              <tr className="bg-gray-50 dark:bg-gray-800/50 text-xs">
                <td className="px-8 py-2 text-gray-500">Часть 2 (40%, поровну)</td>
                <td className="px-4 py-2 text-gray-400">KZT</td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(Math.round(card.bonus_pool_amount * 0.4))}</td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(card.fact_team_bonus_equal_amount)}</td>
                <td className="px-4 py-2">
                  <MkStatusBadge pct={safePct(card.fact_team_bonus_equal_amount, card.bonus_pool_amount * 0.4)} />
                </td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(Math.round(card.bonus_pool_amount * 0.4 * kzt_uzs))}</td>
                <td className="px-4 py-2 text-right tabular-nums">{fmt(Math.round(card.fact_team_bonus_equal_amount * kzt_uzs))}</td>
              </tr>
            </>
          )}
        </tbody>

        {/* Итого */}
        <tfoot>
          <tr className="border-t-2 border-gray-300 dark:border-gray-600 font-semibold">
            <td colSpan={5} className="px-4 py-3">Итого</td>
            <td className="px-4 py-3 text-right tabular-nums">
              {fmt(card.base_salary_amount + commissionPlanUZS + kpiPlanUZS)}
            </td>
            <td className="px-4 py-3 text-right tabular-nums">
              {fmt(card.total_amount_local)}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
