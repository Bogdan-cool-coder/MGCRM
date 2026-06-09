"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { RoleGate } from "@/components/RoleGate";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { formatCurrency } from "@/lib/format";
import { OperationStatusBadge } from "@/components/Finance/OperationStatusBadge";
import { DirectionBadge } from "@/components/Finance/DirectionBadge";
import { PaymentModal } from "@/components/Finance/PaymentModal";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { EmptyState } from "@/components/EmptyState";
import { fetcher } from "@/lib/api";
import type { FinMoneyAccount, FinAccountBalance, FinOperationListResponse, FinLegalEntity } from "@/lib/types";
import { formatDate } from "@/lib/dates";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;

// Columns: date, direction, purpose, amount, status
const OPS_SKELETON_COLS = ["12%", "5%", "42%", "20%", "16%"];

const ACCOUNT_TYPE_ICONS: Record<string, string> = {
  bank:      "bi-bank",
  cash:      "bi-cash",
  acquiring: "bi-credit-card",
  ewallet:   "bi-wallet2",
};

interface Props {
  params: { id: string };
}

export default function AccountDetailPage({ params }: Props) {
  const { id } = params;
  const today = new Date().toISOString().slice(0, 10);

  const { data: account, isLoading: accountLoading } = useSWR<FinMoneyAccount>(`/api/finance/money-accounts/${id}`, fetcher);
  const { data: balance, isLoading: balanceLoading } = useSWR<FinAccountBalance>(
    `/api/finance/money-accounts/${id}/balance?on_date=${today}`,
    fetcher
  );
  const { data: opsData, isLoading: opsLoading } = useSWR<FinOperationListResponse>(
    `/api/finance/operations?account_id=${id}&limit=10&offset=0`,
    fetcher
  );
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const funcCurrency = entities?.find((e) => e.id === account?.legal_entity_id)?.functional_currency ?? "";

  const [paymentOpen, setPaymentOpen] = useState(false);

  if (accountLoading) {
    return (
      <RoleGate allowed={[...FINANCE_ROLES]}>
        <div className="p-6 space-y-4 animate-pulse">
          <div className="h-6 bg-gray-100 dark:bg-gray-800 rounded w-48" />
          <div className="h-32 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
          <div className="h-64 bg-gray-100 dark:bg-gray-800 rounded-2xl" />
        </div>
      </RoleGate>
    );
  }

  if (!account) {
    return (
      <RoleGate allowed={[...FINANCE_ROLES]}>
        <div className="p-6">
          <EmptyState
            icon="bi-exclamation-triangle"
            title="Счёт не найден"
            description="Счёт не существует или у вас нет доступа"
            cta={
              <Link href="/finance/accounts" className="btn-ghost">
                <i className="bi bi-arrow-left mr-1" /> Счета
              </Link>
            }
          />
        </div>
      </RoleGate>
    );
  }

  const recentOps = opsData?.items ?? [];

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        {/* Hero v2 */}
        <div className="border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900 px-8 py-5">
          <div className="flex items-center gap-2 mb-2">
            <Link
              href="/finance/accounts"
              className="text-sm text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-blue-400 flex items-center gap-1 transition-colors"
            >
              <i className="bi bi-arrow-left" /> Счета
            </Link>
          </div>
          <div className="flex items-start justify-between gap-4">
            <div className="flex items-center gap-3">
              <div className="h-10 w-10 rounded-xl bg-primary/10 dark:bg-primary/20 grid place-items-center shrink-0">
                <i className={`bi ${ACCOUNT_TYPE_ICONS[account.account_type] ?? "bi-bank"} text-lg text-primary dark:text-blue-400`} />
              </div>
              <div>
                <h1 className="text-h3 dark:text-gray-100">{account.name}</h1>
                {account.currency && (
                  <p className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
                    Валюта счёта: <span className="font-mono font-medium">{account.currency}</span>
                  </p>
                )}
              </div>
            </div>
            <button type="button" className="btn-primary shrink-0" onClick={() => setPaymentOpen(true)}>
              <i className="bi bi-plus mr-1" /> Создать операцию
            </button>
          </div>
        </div>

        <div className="p-6 flex-1 overflow-y-auto space-y-5">
          {/* KPI — текущий остаток */}
          <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5">
            <p className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider mb-1">Текущий остаток</p>
            {balanceLoading ? (
              <div className="animate-pulse h-9 w-44 bg-gray-100 dark:bg-gray-800 rounded" />
            ) : balance ? (
              <>
                <p className="text-3xl font-bold tabular-nums text-gray-900 dark:text-gray-100">
                  {formatCurrency(balance.amount, balance.currency)}
                </p>
                {funcCurrency && balance.currency !== funcCurrency && (
                  <p className="text-xs text-gray-400 dark:text-gray-500 mt-1">
                    ≈ {formatCurrency(balance.amount_func, funcCurrency)}
                  </p>
                )}
              </>
            ) : (
              <p className="text-2xl font-bold text-gray-400">—</p>
            )}
          </div>

          {/* Последние операции — fin-table */}
          <div className="card rounded-2xl overflow-hidden shadow-elev-1">
            <div className="flex items-center justify-between px-5 py-3.5 border-b border-gray-200 dark:border-gray-700">
              <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-300">Последние операции</h2>
              <Link
                href={`/finance/operations?account=${id}`}
                className="text-xs text-primary dark:text-blue-400 hover:underline transition-colors"
              >
                Все операции →
              </Link>
            </div>

            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {opsLoading ? (
                    <FinTableSkeleton rows={5} cols={OPS_SKELETON_COLS} />
                  ) : recentOps.length === 0 ? (
                    <tr>
                      <td colSpan={5}>
                        <EmptyState
                          icon="bi-arrow-left-right"
                          title="Нет операций по счёту"
                          description="Создай первую операцию"
                        />
                      </td>
                    </tr>
                  ) : (
                    recentOps.map((op) => (
                      <tr
                        key={op.id}
                        className="group border-b border-gray-100 dark:border-gray-800 transition-colors duration-100 hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]"
                      >
                        <td className="px-4 py-2.5 w-24 text-sm text-gray-500 dark:text-gray-400">
                          {formatDate(op.op_date)}
                        </td>
                        <td className="px-2 py-2.5 w-8 text-center">
                          <DirectionBadge direction={op.direction} />
                        </td>
                        <td className="px-4 py-2.5 text-sm text-gray-700 dark:text-gray-300 max-w-xs truncate">
                          {op.purpose || op.number || `Операция #${op.id}`}
                        </td>
                        <td className="px-4 py-2.5 text-right">
                          <MoneyCell amount={op.amount} currency={op.currency} direction={op.direction} />
                        </td>
                        <td className="px-4 py-2.5 w-28">
                          <OperationStatusBadge status={op.status} />
                        </td>
                      </tr>
                    ))
                  )}
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>

      <PaymentModal
        open={paymentOpen}
        onClose={() => setPaymentOpen(false)}
        prefilledAccountId={parseInt(id)}
      />
    </RoleGate>
  );
}
