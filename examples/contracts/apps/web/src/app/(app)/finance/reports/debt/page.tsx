"use client";

import { useState, useEffect } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";
import { fetcher } from "@/lib/api";
import type { FinAgingReport } from "@/lib/types";
import { ReportFilterBar, type ReportFilters, DEFAULT_REPORT_FILTERS } from "@/components/Finance/ReportFilterBar";
import { AgingTable } from "@/components/Finance/AgingTable";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";

// Гейтится backend-capability view_vat (seed_data.ROLE_PERMISSIONS):
// accountant / cfo / director / admin.
const REPORT_ROLES = ["accountant", "cfo", "director", "admin"] as const;

type DebtTab = "ar" | "ap";

const TABS: { key: DebtTab; label: string; icon: string }[] = [
  { key: "ar", label: "Дебиторская", icon: "bi-hourglass-split" },
  { key: "ap", label: "Кредиторская", icon: "bi-hourglass-bottom" },
];

const TAB_META: Record<DebtTab, { endpoint: string; emptyIcon: string; emptyTitle: string; emptyDesc: string }> = {
  ar: {
    endpoint: "ar-aging",
    emptyIcon: "bi-check-circle",
    emptyTitle: "Дебиторской задолженности нет",
    emptyDesc: "Все инвойсы оплачены на выбранную дату",
  },
  ap: {
    endpoint: "ap-aging",
    emptyIcon: "bi-check-circle",
    emptyTitle: "Кредиторской задолженности нет",
    emptyDesc: "Все счета поставщиков оплачены на выбранную дату",
  },
};

export default function DebtPage() {
  const searchParams = useSearchParams();
  const initialTab: DebtTab = searchParams.get("tab") === "ap" ? "ap" : "ar";
  const [tab, setTab] = useState<DebtTab>(initialTab);

  // Синхронизация вкладки при изменении URL (?tab=) — например при редиректе со старого роута
  useEffect(() => {
    const t = searchParams.get("tab");
    if (t === "ap" || t === "ar") setTab(t);
  }, [searchParams]);

  const [filters, setFilters] = useState<ReportFilters>({
    ...DEFAULT_REPORT_FILTERS,
    date_to: new Date().toISOString().slice(0, 10),
  });

  const canLoad = !!filters.entity;
  const meta = TAB_META[tab];

  const swrKey = canLoad
    ? `/api/finance/reports/${meta.endpoint}?legal_entity_id=${filters.entity}${
        filters.date_to ? `&as_of=${filters.date_to}` : ""
      }`
    : null;

  const { data: report, error, isLoading } = useSWR<FinAgingReport>(swrKey, fetcher);

  return (
    <RoleGate allowed={[...REPORT_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Задолженность"
          description="Дебиторская и кредиторская задолженность по бакетам возраста долга"
          actions={
            <Link href="/finance/reports" className="btn-ghost text-sm">
              <i className="bi bi-arrow-left mr-1" />
              К отчётам
            </Link>
          }
        />

        <div className="p-6 flex-1 overflow-auto flex flex-col gap-4">
          {/* Вкладки */}
          <div className="flex items-center gap-1 border-b border-gray-200 dark:border-gray-700">
            {TABS.map((t) => {
              const active = t.key === tab;
              return (
                <button
                  key={t.key}
                  type="button"
                  onClick={() => setTab(t.key)}
                  className={
                    "flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors " +
                    (active
                      ? "border-primary text-primary dark:text-primary-light dark:border-primary-light"
                      : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200")
                  }
                >
                  <i className={`bi ${t.icon}`} />
                  {t.label}
                </button>
              );
            })}
          </div>

          <ReportFilterBar
            filters={filters}
            onChange={setFilters}
            onDateMode
          />

          {!canLoad && (
            <EmptyState
              icon={tab === "ar" ? "bi-people" : "bi-building"}
              title="Выберите юрлицо"
              description="Выберите юрлицо для формирования отчёта"
            />
          )}

          {canLoad && error && (
            <div className="card p-6 text-center text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              {tab === "ar"
                ? "Не удалось загрузить дебиторскую задолженность"
                : "Не удалось загрузить кредиторскую задолженность"}
            </div>
          )}

          {canLoad && isLoading && !report && (
            <div className="card overflow-hidden">
              {/* Заглушка bucket-строки */}
              <div className="grid grid-cols-5 border-b border-gray-200 dark:border-gray-700">
                {Array.from({ length: 5 }).map((_, i) => (
                  <div key={i} className="p-4 border-r last:border-r-0 border-gray-200 dark:border-gray-700">
                    <div className="h-3 animate-pulse bg-gray-100 dark:bg-gray-700 rounded mb-2 w-3/4 mx-auto" />
                    <div className="h-5 animate-pulse bg-gray-100 dark:bg-gray-700 rounded w-1/2 mx-auto" />
                  </div>
                ))}
              </div>
              <table className="w-full text-sm">
                <tbody>
                  <FinTableSkeleton rows={6} cols={["40%", "12%", "15%", "12%"]} />
                </tbody>
              </table>
            </div>
          )}

          {canLoad && report && (
            report.docs.length === 0 ? (
              <EmptyState
                icon={meta.emptyIcon}
                title={meta.emptyTitle}
                description={meta.emptyDesc}
              />
            ) : (
              <AgingTable report={report} />
            )
          )}
        </div>
      </div>
    </RoleGate>
  );
}
