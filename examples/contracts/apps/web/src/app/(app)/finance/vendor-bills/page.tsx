"use client";

import { useState, useMemo, useCallback } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { fetcher } from "@/lib/api";
import type { FinVendorBill, FinLegalEntity, FinVendorBillStatus } from "@/lib/types";
import { MoneyCell, formatAmount } from "@/components/Finance/MoneyCell";
import { VendorBillStatusBadge } from "@/components/Finance/VendorBillStatusBadge";
import { VendorBillFormModal } from "@/components/Finance/VendorBillFormModal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";

const FINANCE_ROLES = ["accountant", "cfo", "admin"] as const;

const STATUS_LABELS: Record<FinVendorBillStatus, string> = {
  draft: "Черновик",
  confirmed: "Проведён",
  partially_paid: "Частично оплачен",
  paid: "Оплачен",
  cancelled: "Отменён",
};

export default function VendorBillsPage() {
  const router = useRouter();
  const [statusFilter, setStatusFilter] = useState("");
  const [entityFilter, setEntityFilter] = useState("");
  const [showCreate, setShowCreate] = useState(false);

  const params = new URLSearchParams();
  if (statusFilter) params.set("status", statusFilter);
  if (entityFilter) params.set("legal_entity_id", entityFilter);
  const swrKey = `/api/finance/vendor-bills?${params.toString()}`;

  const { data: bills, error } = useSWR<FinVendorBill[]>(swrKey, fetcher);
  const { data: entities = [] } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const sumGross = useMemo(
    () => bills?.reduce((s, b) => s + parseFloat(String(b.amount_gross)), 0) ?? 0,
    [bills]
  );

  const columns = useMemo<DataTableColumn<FinVendorBill>[]>(() => [
    {
      key: "number",
      header: "№",
      width: "8rem",
      render: (bill) => (
        <span className="font-mono text-xs text-gray-500 dark:text-gray-400">
          {bill.number ?? `#${bill.id}`}
        </span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "bill_no",
      header: "№ счёта",
      width: "8rem",
      render: (bill) => (
        <span className="font-mono text-xs text-gray-500 dark:text-gray-400">
          {bill.bill_no ?? "—"}
        </span>
      ),
      skeletonWidth: "4rem",
    },
    {
      key: "supplier_company_id",
      header: "Поставщик",
      render: (bill) => (
        <span className="text-gray-700 dark:text-gray-200">
          Поставщик #{bill.supplier_company_id}
        </span>
      ),
    },
    {
      key: "bill_date",
      header: "Дата",
      width: "8rem",
      render: (bill) => (
        <span className="text-gray-500 dark:text-gray-400 tabular-nums">{bill.bill_date}</span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "due_date",
      header: "Срок",
      width: "8rem",
      render: (bill) => (
        <span className="text-gray-400 dark:text-gray-500 tabular-nums">{bill.due_date ?? "—"}</span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "amount_gross",
      header: "Сумма",
      align: "right",
      width: "10rem",
      render: (bill) => <MoneyCell amount={bill.amount_gross} currency={bill.currency} />,
      skeletonWidth: "6rem",
    },
    {
      key: "paid_amount",
      header: "Оплачено",
      align: "right",
      width: "10rem",
      render: (bill) =>
        parseFloat(String(bill.paid_amount)) > 0 ? (
          <MoneyCell amount={bill.paid_amount} currency={bill.currency} positive />
        ) : (
          <span className="text-gray-400">—</span>
        ),
      skeletonWidth: "6rem",
    },
    {
      key: "status",
      header: "Статус",
      width: "10rem",
      render: (bill) => <VendorBillStatusBadge status={bill.status} />,
      skeletonWidth: "5rem",
    },
  ], []);

  const handleRowClick = useCallback(
    (bill: FinVendorBill) => router.push(`/finance/vendor-bills/${bill.id}`),
    [router]
  );

  const footer = useMemo(() => (cols: number) => (
    <tr className="bg-gray-50 dark:bg-gray-800/60 border-t-2 border-gray-200 dark:border-gray-600">
      <td colSpan={cols - 1} className="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400">
        Всего:{" "}
        <span className="font-semibold text-gray-700 dark:text-gray-200">
          {bills?.length ?? 0}
        </span>
      </td>
      <td className="px-4 py-2.5 text-right text-sm tabular-nums">
        <span className="text-gray-500 dark:text-gray-400">Итого: </span>
        <span className="font-bold text-gray-900 dark:text-gray-100">{formatAmount(sumGross)}</span>
      </td>
    </tr>
  ), [bills, sumGross]);

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Счета поставщиков"
          description="Входящие счета от поставщиков с расходными позициями и НДС"
          actions={
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              <i className="bi bi-plus mr-1" />
              Создать счёт
            </button>
          }
        />

        <div className="p-6 flex-1 overflow-auto space-y-4">
          {/* Фильтры */}
          <div className="card p-4">
            <div className="flex flex-wrap gap-2 items-center">
              <select
                className="input text-sm w-auto"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                <option value="">Все статусы</option>
                {(Object.keys(STATUS_LABELS) as FinVendorBillStatus[]).map((s) => (
                  <option key={s} value={s}>{STATUS_LABELS[s]}</option>
                ))}
              </select>

              <select
                className="input text-sm w-auto"
                value={entityFilter}
                onChange={(e) => setEntityFilter(e.target.value)}
              >
                <option value="">Все юрлица</option>
                {entities.map((e) => (
                  <option key={e.id} value={e.id}>{e.name}</option>
                ))}
              </select>

              {(statusFilter || entityFilter) && (
                <button
                  className="btn-ghost text-sm text-gray-500"
                  onClick={() => { setStatusFilter(""); setEntityFilter(""); }}
                >
                  <i className="bi bi-x mr-1" />
                  Сбросить
                </button>
              )}
            </div>
          </div>

          <DataTable
            columns={columns}
            rows={bills}
            getRowKey={(b) => b.id}
            onRowClick={handleRowClick}
            isError={!!error}
            errorText="Не удалось загрузить счета"
            emptyIcon="bi-file-earmark-minus"
            emptyTitle="Счетов поставщиков пока нет"
            emptyText="Создайте первый входящий счёт от поставщика"
            emptyCta={
              <button className="btn-primary" onClick={() => setShowCreate(true)}>
                <i className="bi bi-plus mr-1" />
                Создать счёт
              </button>
            }
            footer={bills && bills.length > 0 ? footer : undefined}
            ariaLabel="Список счетов поставщиков"
            skeletonRows={7}
          />
        </div>
      </div>

      {showCreate && (
        <VendorBillFormModal
          bill={null}
          onClose={() => setShowCreate(false)}
          onSuccess={(b) => router.push(`/finance/vendor-bills/${b.id}`)}
        />
      )}
    </RoleGate>
  );
}
