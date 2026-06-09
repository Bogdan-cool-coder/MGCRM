"use client";

import { useState, useMemo, useRef, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import useSWR from "swr";
import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";
import { ReportFilterBar, type ReportFilters } from "@/components/Finance/ReportFilterBar";
import { formatCurrency } from "@/lib/format";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { fetcher } from "@/lib/api";
import type { FinTrialBalanceReport, FinLegalEntity } from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;

function buildTbUrl(f: ReportFilters): string | null {
  if (!f.entity) return null;
  const p = new URLSearchParams({ legal_entity_id: f.entity });
  if (f.date_to) p.set("on_date", f.date_to);
  return `/api/finance/reports/trial-balance?${p}`;
}

export default function TrialBalancePage() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const wrapRef = useRef<HTMLDivElement>(null);

  const [filters, setFilters] = useState<ReportFilters>(() => ({
    entity: searchParams.get("entity") ?? "",
    date_from: "",
    date_to: searchParams.get("on_date") ?? "",
  }));

  const apiUrl = useMemo(() => buildTbUrl(filters), [filters]);
  const { data: report, isLoading, error } = useSWR<FinTrialBalanceReport>(apiUrl, fetcher);
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const entity = entities?.find((e) => String(e.id) === filters.entity);
  const currency = entity?.functional_currency ?? "";

  // Scroll-shadow для fin-table-wrap
  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const onScroll = () => {
      el.classList.toggle("scrolled", el.scrollTop > 4);
    };
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => el.removeEventListener("scroll", onScroll);
  }, []);

  function handleFiltersChange(f: ReportFilters) {
    setFilters(f);
    const p = new URLSearchParams();
    if (f.entity) p.set("entity", f.entity);
    if (f.date_to) p.set("on_date", f.date_to);
    router.replace(`/finance/reports/trial-balance?${p}`, { scroll: false });
  }

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="ОСВ — Оборотно-сальдовая ведомость"
          description="Дебет/кредит обороты и сальдо по каждому GL-счёту накопительно до указанной даты"
          actions={
            <Link href="/finance/reports" className="btn-ghost text-sm">
              <i className="bi bi-arrow-left mr-1" />
              К отчётам
            </Link>
          }
        />

        <div className="p-6 flex flex-col gap-4">
          <ReportFilterBar filters={filters} onChange={handleFiltersChange} onDateMode hideDates />

          {!apiUrl && (
            <EmptyState
              icon="bi-table"
              title="Выберите юрлицо"
              description="Выберите юрлицо для построения ОСВ"
            />
          )}

          {apiUrl && error && (
            <div className="card p-6 text-center text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить ОСВ
            </div>
          )}

          {apiUrl && (isLoading || report) && (
            <div className="card overflow-hidden">
              {/* Заголовок с балансовым индикатором */}
              {report && (
                <div className="px-5 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                  <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                    {report.rows.length} счетов с оборотами
                    {filters.date_to && (
                      <span className="ml-2 text-gray-400 dark:text-gray-500 font-normal">
                        на {filters.date_to}
                      </span>
                    )}
                  </h2>
                  <span
                    className={`badge ${
                      report.is_balanced ? "badge-success" : "badge-danger"
                    }`}
                  >
                    {report.is_balanced ? (
                      <><i className="bi bi-check-circle mr-1" />Сбалансировано</>
                    ) : (
                      <><i className="bi bi-exclamation-triangle mr-1" />Дисбаланс Σ Дт ≠ Σ Кт</>
                    )}
                  </span>
                </div>
              )}

              <div
                ref={wrapRef}
                className="fin-table-wrap overflow-x-auto max-h-[60vh] overflow-y-auto"
              >
                <table className="w-full text-sm">
                  <thead className="fin-thead-shadow bg-gray-50 dark:bg-gray-900/30 sticky top-0 z-10">
                    <tr>
                      <th className="text-left px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-20">Счёт</th>
                      <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">Наименование</th>
                      <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-24">Тип</th>
                      <th className="text-right px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">Дт-оборот</th>
                      <th className="text-right px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">Кт-оборот</th>
                      <th className="text-right px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">Сальдо</th>
                    </tr>
                  </thead>
                  <tbody>
                    {isLoading && !report ? (
                      <FinTableSkeleton
                        rows={8}
                        cols={["5rem", "40%", "6rem", "12%", "12%", "12%"]}
                      />
                    ) : report && report.rows.length === 0 ? (
                      <tr>
                        <td colSpan={6} className="py-12 text-center">
                          <EmptyState
                            icon="bi-table"
                            title="Нет данных"
                            description="Нет счетов с оборотами за выбранный период"
                          />
                        </td>
                      </tr>
                    ) : (
                      report?.rows.map((row) => (
                        <tr
                          key={row.account_id}
                          className="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                        >
                          <td className="px-5 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                            {row.account_code}
                          </td>
                          <td className="px-3 py-2.5 text-gray-800 dark:text-gray-200">
                            {row.account_name}
                          </td>
                          <td className="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 capitalize">
                            {row.account_type}
                          </td>
                          <td className="px-5 py-2.5 text-right tabular-nums text-gray-700 dark:text-gray-300">
                            {row.debit > 0 ? (
                              <>{formatCurrency(row.debit, currency)}</>
                            ) : (
                              <span className="text-gray-300 dark:text-gray-600">—</span>
                            )}
                          </td>
                          <td className="px-5 py-2.5 text-right tabular-nums text-gray-700 dark:text-gray-300">
                            {row.credit > 0 ? (
                              <>{formatCurrency(row.credit, currency)}</>
                            ) : (
                              <span className="text-gray-300 dark:text-gray-600">—</span>
                            )}
                          </td>
                          <td className="px-5 py-2.5 text-right tabular-nums font-medium">
                            <span className={
                              row.balance > 0 ? "text-gray-800 dark:text-gray-200"
                              : row.balance < 0 ? "text-danger"
                              : "text-gray-400 dark:text-gray-500"
                            }>
                              {formatCurrency(row.balance, currency)}
                            </span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                  {/* Total-bar */}
                  {report && (
                    <tfoot className="border-t-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/30">
                      <tr>
                        <td colSpan={3} className="px-5 py-3 text-sm font-semibold text-gray-700 dark:text-gray-300">
                          Итого
                        </td>
                        <td className="px-5 py-3 text-right tabular-nums font-bold text-gray-800 dark:text-gray-200">
                          {formatCurrency(report.total_debit, currency)}
                        </td>
                        <td className="px-5 py-3 text-right tabular-nums font-bold text-gray-800 dark:text-gray-200">
                          {formatCurrency(report.total_credit, currency)}
                        </td>
                        <td className="px-5 py-3 text-right tabular-nums font-bold">
                          <span className={report.is_balanced ? "text-success" : "text-danger"}>
                            {formatCurrency(report.total_debit - report.total_credit, currency)}
                          </span>
                        </td>
                      </tr>
                    </tfoot>
                  )}
                </table>
              </div>
            </div>
          )}
        </div>
      </div>
    </RoleGate>
  );
}
