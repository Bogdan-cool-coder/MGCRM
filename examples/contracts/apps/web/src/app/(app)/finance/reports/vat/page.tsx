"use client";

import { useState, useRef, useEffect } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";
import { fetcher } from "@/lib/api";
import type { FinVatReport } from "@/lib/types";
import { MoneyCell, formatAmount } from "@/components/Finance/MoneyCell";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { ReportFilterBar, type ReportFilters, DEFAULT_REPORT_FILTERS } from "@/components/Finance/ReportFilterBar";

// /reports/vat гейтится backend-capability `view_vat` (seed_data.ROLE_PERMISSIONS):
// accountant / cfo / director / admin. director имеет view_vat (read-only).
const VAT_ROLES = ["accountant", "cfo", "director", "admin"] as const;

export default function VatReportPage() {
  const [filters, setFilters] = useState<ReportFilters>(DEFAULT_REPORT_FILTERS);
  const [activeTab, setActiveTab] = useState<"sales" | "purchases">("sales");
  const wrapRef = useRef<HTMLDivElement>(null);

  const canLoad = !!(filters.entity && filters.date_from && filters.date_to);

  const swrKey = canLoad
    ? `/api/finance/reports/vat?legal_entity_id=${filters.entity}&date_from=${filters.date_from}&date_to=${filters.date_to}`
    : null;

  const { data: report, error, isLoading } = useSWR<FinVatReport>(swrKey, fetcher);

  const book = activeTab === "sales" ? report?.sales_book : report?.purchase_book;

  const vatPayable = report ? parseFloat(String(report.vat_payable)) : 0;
  const vatPositive = vatPayable >= 0;

  // Scroll-shadow
  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const onScroll = () => el.classList.toggle("scrolled", el.scrollTop > 4);
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => el.removeEventListener("scroll", onScroll);
  }, [activeTab]);

  return (
    <RoleGate allowed={[...VAT_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Отчёт по НДС"
          description="Книга продаж и книга покупок за период"
          actions={
            <Link href="/finance/reports" className="btn-ghost text-sm">
              <i className="bi bi-arrow-left mr-1" />
              К отчётам
            </Link>
          }
        />

        <div className="p-6 flex-1 overflow-auto flex flex-col gap-4">
          <ReportFilterBar filters={filters} onChange={setFilters} />

          {!canLoad && (
            <EmptyState
              icon="bi-percent"
              title="Выберите юрлицо и период"
              description="Параметры нужны для формирования отчёта"
            />
          )}

          {canLoad && error && (
            <div className="card p-6 text-center text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить отчёт по НДС
            </div>
          )}

          {canLoad && (isLoading || report) && (
            <>
              {/* KPI-сводка */}
              <div className="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div className="card p-4 flex flex-col gap-1">
                  <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                    НДС начисленный (вых.)
                  </div>
                  {isLoading && !report ? (
                    <div className="h-7 w-32 animate-pulse bg-gray-100 dark:bg-gray-700 rounded mt-1" />
                  ) : (
                    <div className="text-xl tabular-nums font-bold text-danger">
                      {formatAmount(parseFloat(String(report!.output_total)))}
                    </div>
                  )}
                  <div className="text-xs text-gray-400">Книга продаж</div>
                </div>

                <div className="card p-4 flex flex-col gap-1">
                  <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                    НДС к вычету (вход.)
                  </div>
                  {isLoading && !report ? (
                    <div className="h-7 w-32 animate-pulse bg-gray-100 dark:bg-gray-700 rounded mt-1" />
                  ) : (
                    <div className="text-xl tabular-nums font-bold text-success">
                      {formatAmount(parseFloat(String(report!.input_total)))}
                    </div>
                  )}
                  <div className="text-xs text-gray-400">Книга покупок</div>
                </div>

                <div className="card p-4 flex flex-col gap-1">
                  <div className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wide">
                    {vatPositive ? "К уплате в бюджет" : "К возмещению из бюджета"}
                  </div>
                  {isLoading && !report ? (
                    <div className="h-7 w-32 animate-pulse bg-gray-100 dark:bg-gray-700 rounded mt-1" />
                  ) : (
                    <div
                      className={`text-xl tabular-nums font-bold ${vatPositive ? "text-danger" : "text-success"}`}
                    >
                      {formatAmount(Math.abs(vatPayable))}
                    </div>
                  )}
                  <div className="text-xs text-gray-400">
                    Вых. − Вход. = {vatPositive ? "к уплате" : "возмещение"}
                  </div>
                </div>
              </div>

              {/* Вкладки книг */}
              <div className="card overflow-hidden">
                <div className="border-b border-gray-200 dark:border-gray-700 flex">
                  {(["sales", "purchases"] as const).map((tab) => (
                    <button
                      key={tab}
                      className={`px-6 py-3 text-sm font-medium transition-colors ${
                        activeTab === tab
                          ? "border-b-2 border-primary text-primary"
                          : "text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-300"
                      }`}
                      onClick={() => setActiveTab(tab)}
                    >
                      {tab === "sales" ? "Книга продаж" : "Книга покупок"}
                      {report && (
                        <span className="ml-2 badge badge-neutral text-[10px]">
                          {tab === "sales" ? report.sales_book.length : report.purchase_book.length}
                        </span>
                      )}
                    </button>
                  ))}
                </div>

                {isLoading && !report ? (
                  <table className="w-full text-sm">
                    <tbody>
                      <FinTableSkeleton rows={6} cols={["8rem", "10rem", "20%", "30%", "12%"]} />
                    </tbody>
                  </table>
                ) : !book || book.length === 0 ? (
                  <EmptyState
                    icon="bi-receipt"
                    title="Записей нет"
                    description="Нет записей за выбранный период"
                  />
                ) : (
                  <>
                    <div
                      ref={wrapRef}
                      className="fin-table-wrap overflow-x-auto max-h-[50vh] overflow-y-auto"
                    >
                      <table className="w-full text-sm">
                        <thead className="fin-thead-shadow bg-gray-50 dark:bg-gray-800 sticky top-0 z-10">
                          <tr className="text-gray-500 dark:text-gray-400 text-xs">
                            <th className="text-left px-4 py-3 font-medium">Дата</th>
                            <th className="text-left px-4 py-3 font-medium">Источник</th>
                            <th className="text-left px-4 py-3 font-medium">Контрагент</th>
                            <th className="text-left px-4 py-3 font-medium">Примечание</th>
                            <th className="text-right px-4 py-3 font-medium">Сумма НДС</th>
                          </tr>
                        </thead>
                        <tbody>
                          {book.map((entry) => (
                            <tr
                              key={entry.entry_id}
                              className="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors"
                            >
                              <td className="px-4 py-2.5 text-gray-600 dark:text-gray-400 font-mono text-xs">
                                {entry.entry_date}
                              </td>
                              <td className="px-4 py-2.5 text-gray-700 dark:text-gray-300">
                                <span className="font-mono text-xs bg-gray-100 dark:bg-gray-700 rounded px-1.5 py-0.5">
                                  {entry.source}
                                </span>
                                {entry.source_ref_id && (
                                  <span className="ml-1 text-xs text-gray-400">
                                    #{entry.source_ref_id}
                                  </span>
                                )}
                              </td>
                              <td className="px-4 py-2.5 text-gray-600 dark:text-gray-400 text-xs">
                                {entry.counterparty_company_id
                                  ? `Контрагент #${entry.counterparty_company_id}`
                                  : <span className="text-gray-400 dark:text-gray-500 italic">—</span>}
                              </td>
                              <td className="px-4 py-2.5 text-gray-500 dark:text-gray-500 text-xs max-w-xs truncate">
                                {entry.memo ?? "—"}
                              </td>
                              <td className="px-4 py-2.5 text-right">
                                <MoneyCell amount={entry.vat_amount} currency="" />
                              </td>
                            </tr>
                          ))}
                        </tbody>
                      </table>
                    </div>

                    {/* Total-bar */}
                    <div className="flex items-center justify-end gap-4 px-4 py-3 border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/60 text-sm">
                      <span className="text-gray-500 dark:text-gray-400">Итого НДС:</span>
                      <span className="tabular-nums font-bold text-gray-900 dark:text-gray-100">
                        {formatAmount(
                          parseFloat(
                            String(
                              activeTab === "sales"
                                ? report!.output_total
                                : report!.input_total
                            )
                          )
                        )}
                      </span>
                    </div>
                  </>
                )}
              </div>
            </>
          )}
        </div>
      </div>
    </RoleGate>
  );
}
