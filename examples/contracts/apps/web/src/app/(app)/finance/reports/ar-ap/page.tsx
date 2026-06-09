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
import type { FinArApReport, FinLegalEntity } from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;

type ArApKind = "ar" | "ap";

function buildArApUrl(f: ReportFilters, kind: ArApKind): string | null {
  if (!f.entity) return null;
  const p = new URLSearchParams({ legal_entity_id: f.entity, kind });
  if (f.date_to) p.set("on_date", f.date_to);
  return `/api/finance/reports/ar-ap?${p}`;
}

export default function ArApReportPage() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const wrapRef = useRef<HTMLDivElement>(null);

  const [kind, setKind] = useState<ArApKind>(
    (searchParams.get("kind") as ArApKind | null) === "ap" ? "ap" : "ar"
  );

  const [filters, setFilters] = useState<ReportFilters>(() => ({
    entity: searchParams.get("entity") ?? "",
    date_from: "",
    date_to: searchParams.get("on_date") ?? "",
  }));

  const apiUrl = useMemo(() => buildArApUrl(filters, kind), [filters, kind]);
  const { data: report, isLoading, error } = useSWR<FinArApReport>(apiUrl, fetcher);
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const entity = entities?.find((e) => String(e.id) === filters.entity);
  const currency = entity?.functional_currency ?? "";

  const isAr = kind === "ar";

  // Scroll-shadow
  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const onScroll = () => el.classList.toggle("scrolled", el.scrollTop > 4);
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => el.removeEventListener("scroll", onScroll);
  }, []);

  function handleFiltersChange(f: ReportFilters) {
    setFilters(f);
    syncUrl(f, kind);
  }

  function handleKindChange(k: ArApKind) {
    setKind(k);
    syncUrl(filters, k);
  }

  function syncUrl(f: ReportFilters, k: ArApKind) {
    const p = new URLSearchParams({ kind: k });
    if (f.entity) p.set("entity", f.entity);
    if (f.date_to) p.set("on_date", f.date_to);
    router.replace(`/finance/reports/ar-ap?${p}`, { scroll: false });
  }

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Дебиторка и кредиторка"
          description="Сальдо расчётов с контрагентами по AR/AP-счетам"
          actions={
            <Link href="/finance/reports" className="btn-ghost text-sm">
              <i className="bi bi-arrow-left mr-1" />
              К отчётам
            </Link>
          }
        />

        <div className="p-6 flex flex-col gap-4">
          <ReportFilterBar filters={filters} onChange={handleFiltersChange} onDateMode hideDates />

          {/* Переключатель AR / AP */}
          <div className="flex gap-2">
            <button
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${isAr ? "bg-primary text-white" : "btn-ghost"}`}
              onClick={() => handleKindChange("ar")}
            >
              <i className="bi bi-arrow-down-circle mr-1.5" />
              Дебиторка (AR)
            </button>
            <button
              className={`px-4 py-2 rounded-md text-sm font-medium transition-colors ${!isAr ? "bg-primary text-white" : "btn-ghost"}`}
              onClick={() => handleKindChange("ap")}
            >
              <i className="bi bi-arrow-up-circle mr-1.5" />
              Кредиторка (AP)
            </button>
          </div>

          {/* Информационный баннер */}
          <div className="flex items-start gap-3 p-3 rounded-lg bg-info/10 border border-info/20 text-sm">
            <i className="bi bi-info-circle text-info mt-0.5 shrink-0" />
            <div className="text-gray-600 dark:text-gray-400">
              {isAr
                ? "Дебиторка — остатки по счетам 1210/1290 (нам должны). Положительный баланс = долг контрагента перед нами."
                : "Кредиторка — остатки по счетам 2110/2210 (мы должны). Отрицательный баланс = наш долг перед контрагентом."}
              {" "}<strong>Ограничение Ф1:</strong> сальдо-агрегат без привязки к документам. Полные книги AR/AP с инвойсами — в Ф5.
            </div>
          </div>

          {!apiUrl && (
            <EmptyState
              icon="bi-people"
              title="Выберите юрлицо"
              description={`Выберите юрлицо для построения ${isAr ? "дебиторки" : "кредиторки"}`}
            />
          )}

          {apiUrl && error && (
            <div className="card p-6 text-center text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить отчёт
            </div>
          )}

          {apiUrl && (isLoading || report) && (
            <div className="card overflow-hidden">
              {/* Шапка с итогом */}
              {report && (
                <div className="px-5 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                  <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-300">
                    {isAr ? "Дебиторская задолженность" : "Кредиторская задолженность"}
                    <span className="ml-2 text-gray-400 dark:text-gray-500 font-normal text-xs">
                      ({report.rows.length} строк)
                      {filters.date_to && ` — на ${filters.date_to}`}
                    </span>
                  </h2>
                  <div className="tabular-nums font-semibold text-sm">
                    <span className={isAr ? "text-success" : "text-danger"}>
                      {formatCurrency(Math.abs(report.total), currency)}
                    </span>
                  </div>
                </div>
              )}

              <div
                ref={wrapRef}
                className="fin-table-wrap overflow-x-auto max-h-[55vh] overflow-y-auto"
              >
                <table className="w-full text-sm">
                  <thead className="fin-thead-shadow bg-gray-50 dark:bg-gray-900/30 sticky top-0 z-10">
                    <tr>
                      <th className="text-left px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-20">Счёт</th>
                      <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">GL-счёт</th>
                      <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">Контрагент</th>
                      <th className="text-right px-5 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">Сальдо</th>
                    </tr>
                  </thead>
                  <tbody>
                    {isLoading && !report ? (
                      <FinTableSkeleton rows={6} cols={["5rem", "30%", "35%", "15%"]} />
                    ) : report && report.rows.length === 0 ? (
                      <tr>
                        <td colSpan={4} className="py-10 text-center">
                          <EmptyState
                            icon="bi-check-circle"
                            title={`Нет ${isAr ? "дебиторской" : "кредиторской"} задолженности`}
                          />
                        </td>
                      </tr>
                    ) : (
                      report?.rows.map((row, idx) => (
                        <tr
                          key={`${row.account_id}-${row.counterparty_company_id ?? idx}`}
                          className="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                        >
                          <td className="px-5 py-2.5 font-mono text-xs text-gray-500 dark:text-gray-400">
                            {row.account_code}
                          </td>
                          <td className="px-3 py-2.5 text-gray-700 dark:text-gray-300 text-xs">
                            {row.account_name}
                          </td>
                          <td className="px-3 py-2.5">
                            {row.counterparty_company_id ? (
                              <Link
                                href={`/contacts?company_id=${row.counterparty_company_id}`}
                                className="text-primary dark:text-blue-400 hover:underline text-sm"
                              >
                                <i className="bi bi-person mr-1" />
                                ID: {row.counterparty_company_id}
                              </Link>
                            ) : (
                              <span className="text-gray-400 dark:text-gray-500 text-xs italic">
                                Без контрагента
                              </span>
                            )}
                          </td>
                          <td className="px-5 py-2.5 text-right tabular-nums font-medium">
                            <span className={
                              isAr
                                ? (row.balance > 0 ? "text-success" : "text-danger")
                                : (row.balance < 0 ? "text-danger" : "text-success")
                            }>
                              {formatCurrency(row.balance, currency)}
                            </span>
                          </td>
                        </tr>
                      ))
                    )}
                  </tbody>
                  {/* Total-bar */}
                  {report && report.rows.length > 0 && (
                    <tfoot className="border-t-2 border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-900/30">
                      <tr>
                        <td colSpan={3} className="px-5 py-3 text-sm font-semibold text-gray-700 dark:text-gray-300">
                          Итого
                        </td>
                        <td className="px-5 py-3 text-right tabular-nums font-bold">
                          <span className={isAr ? "text-success" : "text-danger"}>
                            {formatCurrency(report.total, currency)}
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
