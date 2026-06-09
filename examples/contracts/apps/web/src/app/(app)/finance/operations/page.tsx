"use client";

import { useState, useMemo } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { OperationsFilterBar, type OperationsFilters } from "@/components/Finance/OperationsFilterBar";
import { OperationsTable } from "@/components/Finance/OperationsTable";
import { FinSumFooter } from "@/components/Finance/FinSumFooter";
import { PaymentModal } from "@/components/Finance/PaymentModal";
import { fetcher } from "@/lib/api";
import type { FinOperationListResponse } from "@/lib/types";

const PAGE_SIZE = 50;

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;
const CREATE_ROLES = ["accountant", "cfo", "admin"] as const;

export default function OperationsPage() {
  const router = useRouter();
  const searchParams = useSearchParams();

  // Initialize filters from URL params
  const [filters, setFilters] = useState<OperationsFilters>(() => ({
    entity:       searchParams.get("entity") ?? "",
    account:      searchParams.get("account") ?? "",
    direction:    searchParams.get("direction") ?? "",
    status:       searchParams.get("status") ?? "",
    category:     searchParams.get("category") ?? "",
    counterparty: searchParams.get("counterparty") ?? "",
    op_type:      searchParams.get("op_type") ?? "",
    date_from:    searchParams.get("date_from") ?? "",
    date_to:      searchParams.get("date_to") ?? "",
    q:            searchParams.get("q") ?? "",
  }));
  const [page, setPage] = useState(1);
  const [paymentModalOpen, setPaymentModalOpen] = useState(false);
  const [selectedOpId, setSelectedOpId] = useState<number | undefined>();

  // UI-ключи фильтров → backend query-параметры (list_operations).
  // `q` (текстовый поиск по назначению) backend Ф0 не поддерживает — не шлём.
  const FILTER_TO_QUERY: Partial<Record<keyof OperationsFilters, string>> = {
    entity: "legal_entity_id",
    account: "account_id",
    direction: "direction",
    status: "status",
    category: "cashflow_category_id",
    counterparty: "counterparty_company_id",
    op_type: "op_type_id",
    date_from: "date_from",
    date_to: "date_to",
  };

  const swrKey = useMemo(() => {
    const p = new URLSearchParams();
    p.set("limit", String(PAGE_SIZE));
    p.set("offset", String((page - 1) * PAGE_SIZE));
    for (const [k, v] of Object.entries(filters)) {
      const qk = FILTER_TO_QUERY[k as keyof OperationsFilters];
      if (qk && v) p.set(qk, v);
    }
    return `/api/finance/operations?${p.toString()}`;
  }, [filters, page]);

  const { data, error, isLoading, mutate } = useSWR<FinOperationListResponse>(swrKey, fetcher);

  function handleFiltersChange(f: OperationsFilters) {
    setFilters(f);
    setPage(1);
    // Sync to URL for drill-down links
    const p = new URLSearchParams();
    for (const [k, v] of Object.entries(f)) if (v) p.set(k, v);
    router.replace(`/finance/operations?${p.toString()}`, { scroll: false });
  }

  function openPaymentModal(opId?: number) {
    setSelectedOpId(opId);
    setPaymentModalOpen(true);
  }

  const totalPages = data ? Math.ceil(data.total / PAGE_SIZE) : 1;
  // undefined при загрузке → OperationsTable покажет skeleton
  const items = isLoading ? undefined : (data?.items ?? []);

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Операции"
          actions={
            <RoleGate allowed={[...CREATE_ROLES]}>
              <button
                type="button"
                className="btn-primary"
                onClick={() => openPaymentModal()}
              >
                <i className="bi bi-plus mr-1" /> Создать операцию
              </button>
            </RoleGate>
          }
        />

        <div className="p-6 flex-1 overflow-y-auto">
          <OperationsFilterBar filters={filters} onChange={handleFiltersChange} />

          <OperationsTable
            operations={items}
            isError={!!error}
            onOpenPaymentModal={openPaymentModal}
            onRefresh={() => mutate()}
            emptyCta={
              <RoleGate allowed={[...CREATE_ROLES]}>
                <button type="button" className="btn-primary" onClick={() => openPaymentModal()}>
                  <i className="bi bi-plus mr-1" /> Создать операцию
                </button>
              </RoleGate>
            }
          />

          {data && !isLoading && (data.items?.length ?? 0) > 0 && (
            <FinSumFooter
              inflow={data.sum_in}
              outflow={data.sum_out}
            />
          )}

          {/* Pagination */}
          {totalPages > 1 && (
            <div className="flex items-center justify-center gap-1 mt-4">
              <button
                className="btn-ghost px-2 py-1"
                disabled={page === 1}
                onClick={() => setPage((p) => Math.max(1, p - 1))}
              >
                <i className="bi bi-chevron-left" />
              </button>
              {(() => {
                // Скользящее окно из 7 страниц вокруг текущей
                const windowSize = 7;
                const start = Math.min(
                  Math.max(1, page - Math.floor(windowSize / 2)),
                  Math.max(1, totalPages - windowSize + 1)
                );
                const end = Math.min(totalPages, start + windowSize - 1);
                return Array.from({ length: end - start + 1 }, (_, i) => start + i).map((pg) => (
                  <button
                    key={pg}
                    className={`px-3 py-1 rounded text-sm ${pg === page ? "bg-primary text-white" : "btn-ghost"}`}
                    onClick={() => setPage(pg)}
                  >
                    {pg}
                  </button>
                ));
              })()}
              <button
                className="btn-ghost px-2 py-1"
                disabled={page === totalPages}
                onClick={() => setPage((p) => Math.min(totalPages, p + 1))}
              >
                <i className="bi bi-chevron-right" />
              </button>
            </div>
          )}
        </div>
      </div>

      <PaymentModal
        open={paymentModalOpen}
        onClose={() => { setPaymentModalOpen(false); setSelectedOpId(undefined); }}
        operationId={selectedOpId}
        onSuccess={() => mutate()}
      />
    </RoleGate>
  );
}
