"use client";

import { useState, useMemo, useRef, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import useSWR from "swr";
import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";
import { ReportFilterBar, type ReportFilters } from "@/components/Finance/ReportFilterBar";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { formatCurrency } from "@/lib/format";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { fetcher } from "@/lib/api";
import type { FinPnlReport, FinLegalEntity } from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;

type PnlBasis = "accrual" | "cash";

function normalizeBasis(raw: string | null): PnlBasis {
  return raw === "cash" ? "cash" : "accrual";
}

function buildPnlUrl(f: ReportFilters, basis: PnlBasis): string | null {
  if (!f.entity || !f.date_from || !f.date_to) return null;
  const p = new URLSearchParams({
    legal_entity_id: f.entity,
    date_from: f.date_from,
    date_to: f.date_to,
    basis,
  });
  return `/api/finance/reports/pnl?${p}`;
}

/** Строки доходов/расходов с fin-table паттерном */
function PnlSection({
  title,
  icon,
  colorCls,
  bgCls,
  total,
  lines,
  currency,
  isLoading,
  glUrl,
}: {
  title: string;
  icon: string;
  colorCls: string;
  bgCls: string;
  total: number;
  lines: FinPnlReport["income_lines"];
  currency: string;
  isLoading: boolean;
  glUrl: string;
}) {
  const wrapRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const onScroll = () => {
      el.classList.toggle("scrolled", el.scrollTop > 4);
    };
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => el.removeEventListener("scroll", onScroll);
  }, []);

  return (
    <section className="card overflow-hidden">
      <div className={`px-5 py-3 ${bgCls} border-b border-gray-100 dark:border-gray-700 flex items-center justify-between`}>
        <h2 className={`text-sm font-semibold ${colorCls} flex items-center gap-2`}>
          <i className={`bi ${icon}`} />
          {title}
        </h2>
        <MoneyCell amount={total} currency={currency} positive={colorCls === "text-success"} />
      </div>

      {isLoading ? (
        <table className="w-full text-sm">
          <tbody>
            <FinTableSkeleton rows={4} cols={["5rem", "55%", "20%", "10%"]} />
          </tbody>
        </table>
      ) : lines.length === 0 ? (
        <EmptyState
          icon="bi-inbox"
          title="Нет проводок за период"
        />
      ) : (
        <div
          ref={wrapRef}
          className="fin-table-wrap overflow-x-auto max-h-[320px] overflow-y-auto"
        >
          <table className="w-full text-sm">
            <thead className="fin-thead-shadow bg-gray-50 dark:bg-gray-900/30 sticky top-0 z-10">
              <tr>
                <th className="text-left px-5 py-2 text-xs text-gray-500 dark:text-gray-400 font-medium w-20">Счёт</th>
                <th className="text-left px-3 py-2 text-xs text-gray-500 dark:text-gray-400 font-medium">Наименование</th>
                <th className="text-right px-5 py-2 text-xs text-gray-500 dark:text-gray-400 font-medium">Сумма</th>
                <th className="text-right px-5 py-2 text-xs text-gray-500 dark:text-gray-400 font-medium w-28">Доля</th>
              </tr>
            </thead>
            <tbody>
              {lines.map((line) => {
                const share = total > 0
                  ? ((line.amount / total) * 100).toFixed(1)
                  : "—";
                return (
                  <tr
                    key={line.account_id}
                    className="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                  >
                    <td className="px-5 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                      {line.account_code}
                    </td>
                    <td className="px-3 py-2.5 text-gray-800 dark:text-gray-200">
                      <Link
                        href={glUrl}
                        className="hover:text-primary dark:hover:text-blue-400 hover:underline"
                        title="Открыть в главной книге"
                      >
                        {line.account_name}
                      </Link>
                    </td>
                    <td className="px-5 py-2.5 text-right">
                      <MoneyCell
                        amount={line.amount}
                        currency={currency}
                        positive={colorCls === "text-success"}
                      />
                    </td>
                    <td className="px-5 py-2.5 text-right tabular-nums text-xs text-gray-500 dark:text-gray-400">
                      {share}%
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </section>
  );
}

export default function PnlReportPage() {
  const searchParams = useSearchParams();
  const router = useRouter();

  const [filters, setFilters] = useState<ReportFilters>(() => ({
    entity: searchParams.get("entity") ?? "",
    date_from: searchParams.get("date_from") ?? "",
    date_to: searchParams.get("date_to") ?? "",
  }));
  const [basis, setBasis] = useState<PnlBasis>(() => normalizeBasis(searchParams.get("basis")));

  const apiUrl = useMemo(() => buildPnlUrl(filters, basis), [filters, basis]);
  const { data: report, isLoading, error } = useSWR<FinPnlReport>(apiUrl, fetcher);
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const entity = entities?.find((e) => String(e.id) === filters.entity);
  const currency = entity?.functional_currency ?? "";

  function syncUrl(f: ReportFilters, b: PnlBasis) {
    const p = new URLSearchParams();
    if (f.entity) p.set("entity", f.entity);
    if (f.date_from) p.set("date_from", f.date_from);
    if (f.date_to) p.set("date_to", f.date_to);
    if (b === "cash") p.set("basis", b);
    router.replace(`/finance/reports/pnl?${p}`, { scroll: false });
  }

  function handleFiltersChange(f: ReportFilters) {
    setFilters(f);
    syncUrl(f, basis);
  }

  function handleBasisChange(b: PnlBasis) {
    setBasis(b);
    syncUrl(filters, b);
  }

  const profitColor =
    !report ? "" :
    report.profit > 0 ? "text-success" :
    report.profit < 0 ? "text-danger" :
    "text-gray-700 dark:text-gray-300";

  const glUrl = `/finance/reports/gl?entity=${filters.entity}&date_from=${filters.date_from}&date_to=${filters.date_to}`;

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="P&L — Прибыли и убытки"
          description="Доходы (4xxx) и расходы (5xxx) за период в функциональной валюте юрлица"
          actions={
            <Link href="/finance/reports" className="btn-ghost text-sm">
              <i className="bi bi-arrow-left mr-1" />
              К отчётам
            </Link>
          }
        />

        <div className="p-6 flex flex-col gap-4">
          <ReportFilterBar filters={filters} onChange={handleFiltersChange} />

          {/* Переключатель базиса */}
          <div className="card p-3 flex flex-wrap items-center gap-3">
            <span className="text-sm text-gray-600 dark:text-gray-400 shrink-0">Метод:</span>
            <div className="inline-flex rounded-md border border-gray-200 dark:border-gray-700 overflow-hidden text-sm">
              <button
                type="button"
                onClick={() => handleBasisChange("accrual")}
                className={`px-3 py-1.5 transition-colors ${
                  basis === "accrual"
                    ? "bg-primary text-white"
                    : "bg-transparent text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                }`}
              >
                <i className="bi bi-journal-text mr-1.5" />
                По начислению
              </button>
              <button
                type="button"
                onClick={() => handleBasisChange("cash")}
                className={`px-3 py-1.5 border-l border-gray-200 dark:border-gray-700 transition-colors ${
                  basis === "cash"
                    ? "bg-primary text-white"
                    : "bg-transparent text-gray-600 dark:text-gray-300 hover:bg-gray-50 dark:hover:bg-gray-800"
                }`}
              >
                <i className="bi bi-cash-coin mr-1.5" />
                По оплате
              </button>
            </div>
            <span className="text-xs text-gray-400 dark:text-gray-500">
              {basis === "accrual"
                ? "Все начисленные доходы/расходы (канонический P&L)"
                : "Только проводки с движением денег"}
            </span>
          </div>

          {!apiUrl && (
            <EmptyState
              icon="bi-graph-up-arrow"
              title="Выберите юрлицо и период"
              description="Параметры нужны для построения P&L"
            />
          )}

          {apiUrl && error && (
            <div className="card p-6 text-center text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить отчёт
            </div>
          )}

          {apiUrl && (isLoading || report) && (
            <div className="space-y-4">
              {/* Секция Доходы */}
              <PnlSection
                title="Доходы"
                icon="bi-arrow-up-circle"
                colorCls="text-success"
                bgCls="bg-success/10"
                total={report?.total_income ?? 0}
                lines={report?.income_lines ?? []}
                currency={currency}
                isLoading={isLoading && !report}
                glUrl={glUrl}
              />

              {/* Секция Расходы */}
              <PnlSection
                title="Расходы"
                icon="bi-arrow-down-circle"
                colorCls="text-danger"
                bgCls="bg-danger/10"
                total={report?.total_expense ?? 0}
                lines={report?.expense_lines ?? []}
                currency={currency}
                isLoading={isLoading && !report}
                glUrl={glUrl}
              />

              {/* Итог — нетто-прибыль */}
              {report && (
                <section className="card p-5">
                  <div className="flex items-center justify-between">
                    <div>
                      <div className="text-xs text-gray-400 dark:text-gray-500 uppercase tracking-wider mb-1">
                        Нетто-прибыль за период
                      </div>
                      <div className="text-xs text-gray-500 dark:text-gray-400">
                        {filters.date_from} — {filters.date_to}
                      </div>
                    </div>
                    <div className="text-right">
                      <div className={`text-2xl font-bold tabular-nums ${profitColor}`}>
                        {report.profit > 0 ? "+" : ""}
                        {formatCurrency(report.profit, currency)}
                      </div>
                      <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                        {report.profit > 0 ? "Прибыль" : report.profit < 0 ? "Убыток" : "Нулевой результат"}
                      </div>
                    </div>
                  </div>

                  {/* Прогресс-бар доходы/расходы */}
                  {(report.total_income > 0 || report.total_expense > 0) && (
                    <div className="mt-4 space-y-2">
                      <div className="flex justify-between text-xs text-gray-500 dark:text-gray-400">
                        <span>Доходы: {formatCurrency(report.total_income, currency)}</span>
                        <span>Расходы: {formatCurrency(report.total_expense, currency)}</span>
                      </div>
                      <div className="flex h-2 rounded-full overflow-hidden bg-danger/30">
                        <div
                          className="bg-success transition-all"
                          style={{
                            width: report.total_income + report.total_expense > 0
                              ? `${(report.total_income / (report.total_income + report.total_expense)) * 100}%`
                              : "0%",
                          }}
                        />
                      </div>
                    </div>
                  )}
                </section>
              )}
            </div>
          )}
        </div>
      </div>
    </RoleGate>
  );
}
