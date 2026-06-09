"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { DatePicker } from "@/components/ui/DatePicker";
import { fetcher } from "@/lib/api";
import type { FinShadowRecon, UserRole } from "@/lib/types";

const ALLOWED_ROLES: UserRole[] = ["cfo", "admin"];

type DiscrepancyKind = "missing_in_gl" | "amount_mismatch" | "orphan_in_gl";

const DISCREPANCY_META: Record<
  DiscrepancyKind,
  { label: string; hint: string; icon: string }
> = {
  missing_in_gl: {
    label: "Нет в GL",
    hint: "Платёж (комиссия) учтён по ContractPayment, но зеркальной операции в GL нет — GL недоучёл.",
    icon: "bi-dash-circle",
  },
  amount_mismatch: {
    label: "Расхождение суммы",
    hint: "Операция в GL есть, но сумма или валюта отличается от ContractPayment.",
    icon: "bi-exclamation-triangle",
  },
  orphan_in_gl: {
    label: "Лишнее в GL",
    hint: "Write-through операция в GL без подходящего ContractPayment-платежа — GL переучёл.",
    icon: "bi-plus-circle",
  },
};

function ReconSkeleton() {
  return (
    <div className="card p-5 animate-pulse space-y-3">
      <div className="h-10 bg-gray-100 dark:bg-gray-800 rounded w-1/2" />
      <div className="h-5 bg-gray-100 dark:bg-gray-800 rounded w-1/3" />
    </div>
  );
}

export default function ReconciliationPage() {
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");

  const params = new URLSearchParams();
  if (dateFrom) params.set("date_from", dateFrom);
  if (dateTo) params.set("date_to", dateTo);
  const qs = params.toString();
  const swrKey = `/api/finance/integrations/shadow-reconciliation${qs ? `?${qs}` : ""}`;

  const { data, isLoading, error } = useSWR<FinShadowRecon>(swrKey, fetcher);

  const totalDiscrepancies = data
    ? data.missing_in_gl.length + data.amount_mismatch.length + data.orphan_in_gl.length
    : 0;

  return (
    <RoleGate
      allowed={ALLOWED_ROLES}
      fallback={
        <div className="p-8 text-center flex flex-col items-center gap-3">
          <i className="bi bi-lock text-4xl text-gray-300 dark:text-gray-600" />
          <p className="text-sm text-gray-500 dark:text-gray-400">
            Этот раздел доступен только CFO и администратору
          </p>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Сверка комиссий"
          description="GL-зеркало комиссий vs ContractPayment (источник истины расчёта)"
        />

        <div className="p-6 flex flex-col gap-4">
          {/* Пояснение */}
          <div className="flex items-start gap-2 p-3 rounded-lg bg-blue-50 dark:bg-blue-900/10 border border-blue-100 dark:border-blue-800/40">
            <i className="bi bi-info-circle text-info mt-0.5 shrink-0" />
            <p className="text-sm text-gray-700 dark:text-gray-300">
              Инструмент безопасности: проверяет, что зеркало комиссий в главной книге
              (GL) точно совпадает с платежами ContractPayment, по которым считается
              комиссия менеджеров.
            </p>
          </div>

          {/* Фильтр периода */}
          <div className="card p-4">
            <div className="flex flex-wrap items-end gap-3">
              <div className="flex flex-col gap-1">
                <DatePicker
                  label="С даты"
                  value={dateFrom || null}
                  onChange={(v) => setDateFrom(v ?? "")}
                />
              </div>
              <div className="flex flex-col gap-1">
                <DatePicker
                  label="По дату"
                  value={dateTo || null}
                  onChange={(v) => setDateTo(v ?? "")}
                />
              </div>
              {(dateFrom || dateTo) && (
                <button
                  type="button"
                  className="btn-ghost text-sm"
                  onClick={() => { setDateFrom(""); setDateTo(""); }}
                >
                  <i className="bi bi-x mr-1" />
                  Сбросить
                </button>
              )}
            </div>
          </div>

          {/* Результат */}
          {isLoading && <ReconSkeleton />}

          {error && !isLoading && (
            <div className="card p-5 text-sm text-danger">
              <i className="bi bi-exclamation-circle mr-1" />
              Не удалось выполнить сверку
            </div>
          )}

          {!isLoading && !error && data && (
            <>
              {/* Статус-баннер */}
              {data.ok ? (
                <div className="flex items-center gap-3 p-4 rounded-lg bg-success/5 border border-success/20 dark:bg-success/10">
                  <i className="bi bi-check-circle-fill text-2xl text-success" />
                  <div>
                    <p className="text-sm font-semibold text-success">
                      Комиссии и GL сходятся, 0 расхождений
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                      Сверено платежей: {data.total_old} · операций в GL: {data.total_new} · совпало: {data.matched}
                    </p>
                  </div>
                </div>
              ) : (
                <div className="flex items-center gap-3 p-4 rounded-lg bg-danger/5 border border-danger/20 dark:bg-danger/10">
                  <i className="bi bi-x-circle-fill text-2xl text-danger" />
                  <div>
                    <p className="text-sm font-semibold text-danger">
                      Найдено расхождений: {totalDiscrepancies}
                    </p>
                    <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                      Сверено платежей: {data.total_old} · операций в GL: {data.total_new} · совпало: {data.matched}
                    </p>
                  </div>
                </div>
              )}

              {/* Детали расхождений */}
              {!data.ok && (
                <div className="flex flex-col gap-3">
                  {(Object.keys(DISCREPANCY_META) as DiscrepancyKind[]).map((kind) => {
                    const ids = data[kind];
                    if (ids.length === 0) return null;
                    const meta = DISCREPANCY_META[kind];
                    return (
                      <div key={kind} className="card p-4">
                        <div className="flex items-start gap-2 mb-3">
                          <i className={`bi ${meta.icon} text-danger mt-0.5 shrink-0`} />
                          <div>
                            <p className="text-sm font-medium text-gray-700 dark:text-gray-300">
                              {meta.label}{" "}
                              <span className="badge bg-danger/10 text-danger text-xs px-1.5 py-0.5 rounded-full ml-1">
                                {ids.length}
                              </span>
                            </p>
                            <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                              {meta.hint}
                            </p>
                          </div>
                        </div>
                        <div className="flex flex-wrap gap-1.5 pl-6">
                          {ids.map((id) => (
                            <span
                              key={id}
                              className="inline-flex items-center px-2 py-0.5 rounded-md text-xs font-mono bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-300"
                            >
                              #{id}
                            </span>
                          ))}
                        </div>
                      </div>
                    );
                  })}
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </RoleGate>
  );
}
