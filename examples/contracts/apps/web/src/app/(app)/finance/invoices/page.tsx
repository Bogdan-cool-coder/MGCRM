"use client";

import { useState, useMemo, useCallback } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { fetcher } from "@/lib/api";
import type { FinInvoice, FinLegalEntity, FinInvoiceStatus } from "@/lib/types";
import { MoneyCell, formatAmount } from "@/components/Finance/MoneyCell";
import { InvoiceStatusBadge } from "@/components/Finance/InvoiceStatusBadge";
import { InvoiceFormModal } from "@/components/Finance/InvoiceFormModal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { EmptyState } from "@/components/EmptyState";

const FINANCE_ROLES = ["accountant", "cfo", "admin"] as const;

const STATUS_LABELS: Record<FinInvoiceStatus, string> = {
  draft: "Черновик",
  issued: "Выставлен",
  partially_paid: "Частично оплачен",
  paid: "Оплачен",
  cancelled: "Отменён",
};

export default function InvoicesPage() {
  const router = useRouter();
  const [statusFilter, setStatusFilter] = useState("");
  const [entityFilter, setEntityFilter] = useState("");
  const [showCreate, setShowCreate] = useState(false);

  const params = new URLSearchParams();
  if (statusFilter) params.set("status", statusFilter);
  if (entityFilter) params.set("legal_entity_id", entityFilter);
  const swrKey = `/api/finance/invoices?${params.toString()}`;

  const { data: invoices, error } = useSWR<FinInvoice[]>(swrKey, fetcher);
  const { data: entities = [] } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const sumGross = useMemo(
    () => invoices?.reduce((s, inv) => s + parseFloat(String(inv.amount_gross)), 0) ?? 0,
    [invoices]
  );
  const sumPaid = useMemo(
    () => invoices?.reduce((s, inv) => s + parseFloat(String(inv.paid_amount)), 0) ?? 0,
    [invoices]
  );

  const columns = useMemo<DataTableColumn<FinInvoice>[]>(() => [
    {
      key: "number",
      header: "№",
      width: "9rem",
      render: (inv) => (
        <span className="font-mono text-xs text-gray-500 dark:text-gray-400">
          {inv.number ?? `#${inv.id}`}
        </span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "counterparty_company_id",
      header: "Контрагент",
      render: (inv) => (
        <span className="text-gray-700 dark:text-gray-200">
          Контрагент #{inv.counterparty_company_id}
        </span>
      ),
    },
    {
      key: "issue_date",
      header: "Дата",
      width: "8rem",
      render: (inv) => (
        <span className="text-gray-500 dark:text-gray-400 tabular-nums">{inv.issue_date}</span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "due_date",
      header: "Срок",
      width: "8rem",
      render: (inv) => (
        <span className="text-gray-400 dark:text-gray-500 tabular-nums">{inv.due_date ?? "—"}</span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "amount_gross",
      header: "Сумма",
      align: "right",
      width: "10rem",
      render: (inv) => <MoneyCell amount={inv.amount_gross} currency={inv.currency} />,
      skeletonWidth: "6rem",
    },
    {
      key: "paid_amount",
      header: "Оплачено",
      align: "right",
      width: "10rem",
      render: (inv) =>
        parseFloat(String(inv.paid_amount)) > 0 ? (
          <MoneyCell amount={inv.paid_amount} currency={inv.currency} positive />
        ) : (
          <span className="text-gray-400">—</span>
        ),
      skeletonWidth: "6rem",
    },
    {
      key: "status",
      header: "Статус",
      width: "10rem",
      render: (inv) => <InvoiceStatusBadge status={inv.status} />,
      skeletonWidth: "5rem",
    },
  ], []);

  const handleRowClick = useCallback(
    (inv: FinInvoice) => router.push(`/finance/invoices/${inv.id}`),
    [router]
  );

  const totalColumns = columns.length;
  const footer = useMemo(() => (cols: number) => (
    <tr className="bg-gray-50 dark:bg-gray-800/60 border-t-2 border-gray-200 dark:border-gray-600">
      <td colSpan={cols - 2} className="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400">
        Всего:{" "}
        <span className="font-semibold text-gray-700 dark:text-gray-200">
          {invoices?.length ?? 0}
        </span>
      </td>
      <td className="px-4 py-2.5 text-right text-sm tabular-nums" colSpan={1}>
        <span className="text-gray-500 dark:text-gray-400">Итого: </span>
        <span className="font-bold text-gray-900 dark:text-gray-100">{formatAmount(sumGross)}</span>
      </td>
      <td className="px-4 py-2.5 text-right text-sm tabular-nums" colSpan={1}>
        <span className="text-gray-500 dark:text-gray-400">Оплачено: </span>
        <span className="font-semibold text-success">{formatAmount(sumPaid)}</span>
        {sumGross - sumPaid > 0 && (
          <>
            {" · "}
            <span className="text-danger font-semibold">{formatAmount(sumGross - sumPaid)}</span>
            <span className="text-gray-400 text-xs ml-0.5">остаток</span>
          </>
        )}
      </td>
    </tr>
  ), [invoices, sumGross, sumPaid]);

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Инвойсы"
          description="Счета клиентам с позициями, НДС и оплатой"
          actions={
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              <i className="bi bi-plus mr-1" />
              Создать инвойс
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
                {(Object.keys(STATUS_LABELS) as FinInvoiceStatus[]).map((s) => (
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
            rows={invoices}
            getRowKey={(inv) => inv.id}
            onRowClick={handleRowClick}
            isError={!!error}
            errorText="Не удалось загрузить инвойсы"
            emptyIcon="bi-receipt"
            emptyTitle="Инвойсов пока нет"
            emptyText="Создайте первый счёт клиенту"
            emptyCta={
              <button className="btn-primary" onClick={() => setShowCreate(true)}>
                <i className="bi bi-plus mr-1" />
                Создать инвойс
              </button>
            }
            footer={invoices && invoices.length > 0 ? footer : undefined}
            ariaLabel="Список инвойсов"
            skeletonRows={7}
          />
        </div>
      </div>

      {showCreate && (
        <InvoiceFormModal
          invoice={null}
          onClose={() => setShowCreate(false)}
          onSuccess={(inv) => router.push(`/finance/invoices/${inv.id}`)}
        />
      )}
    </RoleGate>
  );
}
