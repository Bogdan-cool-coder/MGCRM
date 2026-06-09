"use client";

import { useState, useMemo, useCallback } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { fetcher } from "@/lib/api";
import type { FinAct, FinLegalEntity, FinActStatus } from "@/lib/types";
import { MoneyCell, formatAmount } from "@/components/Finance/MoneyCell";
import { ActStatusBadge } from "@/components/Finance/ActStatusBadge";
import { ActFormModal } from "@/components/Finance/ActFormModal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";

const FINANCE_ROLES = ["accountant", "cfo", "admin"] as const;

const STATUS_LABELS: Record<FinActStatus, string> = {
  draft: "Черновик",
  issued: "Выставлен",
  signed: "Подписан",
  cancelled: "Отменён",
};

export default function ActsPage() {
  const router = useRouter();
  const [statusFilter, setStatusFilter] = useState("");
  const [entityFilter, setEntityFilter] = useState("");
  const [showCreate, setShowCreate] = useState(false);

  const params = new URLSearchParams();
  if (statusFilter) params.set("status", statusFilter);
  if (entityFilter) params.set("legal_entity_id", entityFilter);
  const swrKey = `/api/finance/acts?${params.toString()}`;

  const { data: acts, error } = useSWR<FinAct[]>(swrKey, fetcher);
  const { data: entities = [] } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const sumGross = useMemo(
    () => acts?.reduce((s, a) => s + parseFloat(String(a.amount_gross)), 0) ?? 0,
    [acts]
  );

  const columns = useMemo<DataTableColumn<FinAct>[]>(() => [
    {
      key: "number",
      header: "№",
      width: "9rem",
      render: (act) => (
        <span className="font-mono text-xs text-gray-500 dark:text-gray-400">
          {act.number ?? `#${act.id}`}
        </span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "counterparty_company_id",
      header: "Контрагент",
      render: (act) => (
        <span className="text-gray-700 dark:text-gray-200">
          Контрагент #{act.counterparty_company_id}
        </span>
      ),
    },
    {
      key: "act_date",
      header: "Дата",
      width: "8rem",
      render: (act) => (
        <span className="text-gray-500 dark:text-gray-400 tabular-nums">{act.act_date}</span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "invoice_id",
      header: "Инвойс",
      width: "7rem",
      render: (act) =>
        act.invoice_id ? (
          <Link
            href={`/finance/invoices/${act.invoice_id}`}
            className="text-primary hover:underline text-xs font-mono"
            onClick={(e) => e.stopPropagation()}
          >
            #{act.invoice_id}
          </Link>
        ) : (
          <span className="text-gray-400">—</span>
        ),
      skeletonWidth: "3rem",
    },
    {
      key: "amount_gross",
      header: "Сумма",
      align: "right",
      width: "10rem",
      render: (act) => <MoneyCell amount={act.amount_gross} currency={act.currency} />,
      skeletonWidth: "6rem",
    },
    {
      key: "status",
      header: "Статус",
      width: "10rem",
      render: (act) => <ActStatusBadge status={act.status} />,
      skeletonWidth: "5rem",
    },
  ], []);

  const handleRowClick = useCallback(
    (act: FinAct) => router.push(`/finance/acts/${act.id}`),
    [router]
  );

  const footer = useMemo(() => (cols: number) => (
    <tr className="bg-gray-50 dark:bg-gray-800/60 border-t-2 border-gray-200 dark:border-gray-600">
      <td colSpan={cols - 1} className="px-4 py-2.5 text-sm text-gray-500 dark:text-gray-400">
        Всего:{" "}
        <span className="font-semibold text-gray-700 dark:text-gray-200">
          {acts?.length ?? 0}
        </span>
      </td>
      <td className="px-4 py-2.5 text-right text-sm tabular-nums">
        <span className="text-gray-500 dark:text-gray-400">Итого: </span>
        <span className="font-bold text-gray-900 dark:text-gray-100">{formatAmount(sumGross)}</span>
      </td>
    </tr>
  ), [acts, sumGross]);

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Акты"
          description="Акты выполненных работ и оказанных услуг"
          actions={
            <button className="btn-primary" onClick={() => setShowCreate(true)}>
              <i className="bi bi-plus mr-1" />
              Создать акт
            </button>
          }
        />

        <div className="p-6 flex-1 overflow-auto space-y-4">
          {/* Информационная плашка */}
          <div className="flex items-center gap-2 px-3 py-2 rounded-lg bg-blue-50 dark:bg-blue-900/15 border border-blue-100 dark:border-blue-800/40 text-sm text-gray-600 dark:text-gray-400">
            <i className="bi bi-info-circle text-info shrink-0" />
            Акт — документ подтверждения выполнения работ. Финансовую проводку не создаёт.
          </div>

          {/* Фильтры */}
          <div className="card p-4">
            <div className="flex flex-wrap gap-2 items-center">
              <select
                className="input text-sm w-auto"
                value={statusFilter}
                onChange={(e) => setStatusFilter(e.target.value)}
              >
                <option value="">Все статусы</option>
                {(Object.keys(STATUS_LABELS) as FinActStatus[]).map((s) => (
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
            rows={acts}
            getRowKey={(act) => act.id}
            onRowClick={handleRowClick}
            isError={!!error}
            errorText="Не удалось загрузить акты"
            emptyIcon="bi-file-earmark-check"
            emptyTitle="Актов пока нет"
            emptyText="Создайте первый акт выполненных работ"
            emptyCta={
              <button className="btn-primary" onClick={() => setShowCreate(true)}>
                <i className="bi bi-plus mr-1" />
                Создать акт
              </button>
            }
            footer={acts && acts.length > 0 ? footer : undefined}
            ariaLabel="Список актов"
            skeletonRows={7}
          />
        </div>
      </div>

      {showCreate && (
        <ActFormModal
          act={null}
          onClose={() => setShowCreate(false)}
          onSuccess={(a) => router.push(`/finance/acts/${a.id}`)}
        />
      )}
    </RoleGate>
  );
}
