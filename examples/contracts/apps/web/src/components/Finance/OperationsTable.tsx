"use client";

import { useRouter } from "next/navigation";
import useSWR, { mutate as globalMutate } from "swr";
import { useState, useRef, useEffect, useCallback } from "react";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { api, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { DirectionBadge } from "./DirectionBadge";
import { OperationStatusBadge } from "./OperationStatusBadge";
import { MoneyCell } from "./MoneyCell";
import type {
  FinOperation,
  FinOpType,
  FinMoneyAccount,
  FinCashflowCategory,
} from "@/lib/types";
import { formatDate } from "@/lib/dates";

interface CompanyOption {
  id: number;
  name: string;
}

interface Props {
  /**
   * undefined = loading (skeleton).
   * [] = пусто (EmptyState).
   * FinOperation[] = данные.
   */
  operations: FinOperation[] | undefined;
  onOpenPaymentModal: (id: number) => void;
  onRefresh: () => void;
  isError?: boolean;
  emptyCta?: React.ReactNode;
}

// Изолированный dropdown-меню для строки операции (собственный state открытия).
function ActionMenu({ op, onOpen, onRefresh }: { op: FinOperation; onOpen: () => void; onRefresh: () => void }) {
  const [menuOpen, setMenuOpen] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { user } = useMe();

  const canPost = user?.role === "admin" || user?.role === "accountant" || user?.role === "cfo" || user?.role === "director";
  const canCreate = user?.role === "admin" || user?.role === "accountant" || user?.role === "cfo";

  const canProvesti = canPost && (op.status === "planned" || op.status === "to_pay");
  const canStorno = canPost && op.status === "posted";
  const canRaznesti = canCreate && op.status === "posted";

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setMenuOpen(false);
      }
    }
    if (menuOpen) document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [menuOpen]);

  const handleToggle = useCallback((e: React.MouseEvent) => {
    e.stopPropagation();
    setMenuOpen((o) => !o);
  }, []);

  async function provesti(e: React.MouseEvent) {
    e.stopPropagation();
    setSubmitting(true);
    try {
      await api(`/api/finance/operations/${op.id}/post`, { method: "POST" });
      await globalMutate("/api/finance/operations");
      onRefresh();
    } catch {
      // silently ignore — page will reflect old state
    } finally {
      setSubmitting(false);
      setMenuOpen(false);
    }
  }

  async function storno(e: React.MouseEvent) {
    e.stopPropagation();
    setSubmitting(true);
    try {
      await api(`/api/finance/operations/${op.id}/reverse`, { method: "POST", body: {} });
      await globalMutate("/api/finance/operations");
      onRefresh();
    } catch {
      /* */
    } finally {
      setSubmitting(false);
      setMenuOpen(false);
    }
  }

  return (
    <div ref={ref} className="relative flex justify-end">
      <button
        type="button"
        onClick={handleToggle}
        className="p-1 rounded hover:bg-gray-100 dark:hover:bg-gray-700 text-gray-400 hover:text-gray-600 dark:hover:text-gray-300"
        disabled={submitting}
      >
        <i className="bi bi-three-dots-vertical" />
      </button>
      {menuOpen && (
        <div className="absolute right-0 z-30 mt-1 w-44 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-lg shadow-lg py-1 text-sm">
          {canProvesti && (
            <button
              onClick={provesti}
              className="flex items-center gap-2 w-full px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300"
            >
              <i className="bi bi-check-circle text-success" /> Провести
            </button>
          )}
          {canStorno && (
            <button
              onClick={storno}
              className="flex items-center gap-2 w-full px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 text-danger"
            >
              <i className="bi bi-arrow-counterclockwise" /> Сторнировать
            </button>
          )}
          {canRaznesti && (
            <button
              onClick={(e) => { e.stopPropagation(); onOpen(); setMenuOpen(false); }}
              className="flex items-center gap-2 w-full px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300"
            >
              <i className="bi bi-diagram-2" /> Разнести
            </button>
          )}
          <hr className="border-gray-100 dark:border-gray-700 my-1" />
          <button
            onClick={(e) => { e.stopPropagation(); onOpen(); setMenuOpen(false); }}
            className="flex items-center gap-2 w-full px-3 py-2 hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-700 dark:text-gray-300"
          >
            <i className="bi bi-arrow-up-right-square" /> Открыть
          </button>
        </div>
      )}
    </div>
  );
}

export function OperationsTable({ operations, onOpenPaymentModal, onRefresh, isError, emptyCta }: Props) {
  const router = useRouter();

  // Резолв имён из справочников (backend отдаёт только FK-id). Списки кешируются SWR.
  const { data: opTypes } = useSWR<FinOpType[]>("/api/finance/op-types", fetcher);
  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);
  const { data: cats } = useSWR<FinCashflowCategory[]>("/api/finance/categories", fetcher);
  const { data: companies } = useSWR<CompanyOption[]>("/api/companies?limit=500", fetcher);

  const opTypeName = (id: number | null) =>
    id != null ? opTypes?.find((t) => t.id === id)?.name ?? null : null;
  const accountName = (id: number | null) =>
    id != null ? accounts?.find((a) => a.id === id)?.name ?? null : null;
  const catName = (id: number | null) =>
    id != null ? cats?.find((c) => c.id === id)?.name ?? null : null;
  const companyName = (id: number | null) =>
    id != null ? companies?.find((c) => c.id === id)?.name ?? null : null;

  const columns: DataTableColumn<FinOperation>[] = [
    {
      key: "op_date",
      header: "Дата",
      width: "7rem",
      skeletonWidth: "70%",
      render: (op) => (
        <span className="text-gray-600 dark:text-gray-400">{formatDate(op.op_date)}</span>
      ),
    },
    {
      key: "direction",
      header: "",
      width: "2.5rem",
      skeletonWidth: "80%",
      render: (op) => <DirectionBadge direction={op.direction} />,
    },
    {
      key: "op_type_id",
      header: "Тип",
      width: "8rem",
      skeletonWidth: "75%",
      render: (op) => (
        <span className="text-gray-600 dark:text-gray-400">{opTypeName(op.op_type_id) ?? "—"}</span>
      ),
    },
    {
      key: "counterparty_company_id",
      header: "Контрагент",
      skeletonWidth: "85%",
      render: (op) => (
        <span className="text-gray-800 dark:text-gray-200">{companyName(op.counterparty_company_id) ?? "—"}</span>
      ),
    },
    {
      key: "amount",
      header: "Сумма",
      width: "10rem",
      align: "right",
      skeletonWidth: "70%",
      render: (op) => <MoneyCell amount={op.amount} currency={op.currency} direction={op.direction} />,
    },
    {
      key: "account_from_id",
      header: "Счёт",
      width: "8rem",
      skeletonWidth: "75%",
      render: (op) => (
        <span className="text-gray-600 dark:text-gray-400">
          {op.direction === "transfer"
            ? `${accountName(op.account_from_id) ?? "—"} → ${accountName(op.account_to_id) ?? "—"}`
            : (accountName(op.account_from_id) ?? accountName(op.account_to_id) ?? "—")}
        </span>
      ),
    },
    {
      key: "cashflow_category_id",
      header: "Статья",
      width: "8rem",
      skeletonWidth: "70%",
      render: (op) => (
        <span className="text-xs text-gray-500 dark:text-gray-400">{catName(op.cashflow_category_id) ?? "—"}</span>
      ),
    },
    {
      key: "status",
      header: "Статус",
      width: "8rem",
      skeletonWidth: "65%",
      render: (op) => <OperationStatusBadge status={op.status} />,
    },
  ];

  return (
    <DataTable<FinOperation>
      columns={columns}
      rows={operations}
      getRowKey={(op) => op.id}
      onRowClick={(op) => router.push(`/finance/operations/${op.id}`)}
      isError={isError}
      errorText="Не удалось загрузить операции. Попробуй обновить страницу."
      rowActions={(op) => (
        <ActionMenu
          op={op}
          onOpen={() => onOpenPaymentModal(op.id)}
          onRefresh={onRefresh}
        />
      )}
      emptyIcon="bi-arrow-left-right"
      emptyTitle="Операций пока нет"
      emptyText="Создай первую операцию, чтобы начать учёт"
      emptyCta={emptyCta}
      ariaLabel="Финансовые операции"
      skeletonRows={8}
    />
  );
}
