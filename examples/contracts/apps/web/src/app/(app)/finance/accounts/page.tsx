"use client";

import { useState, useRef, useCallback, useMemo, memo } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { AccountModal } from "@/components/Finance/AccountModal";
import { PaymentModal } from "@/components/Finance/PaymentModal";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { EmptyState } from "@/components/EmptyState";
import { Combobox } from "@/components/ui/Combobox";
import { useToast } from "@/components/ui/Toast";
import { fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinMoneyAccount, FinLegalEntity, FinAccountBalance } from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;
const MANAGE_ROLES = ["accountant", "cfo", "admin"] as const;

const ACCOUNT_TYPE_ICONS: Record<string, string> = {
  bank:      "bi-bank",
  cash:      "bi-cash",
  acquiring: "bi-credit-card",
  ewallet:   "bi-wallet2",
};

const ACCOUNT_TYPE_LABELS: Record<string, string> = {
  bank:      "Банк",
  cash:      "Касса",
  acquiring: "Эквайринг",
  ewallet:   "Кошелёк",
};

// Skeleton columns: icon, name, type, balance, actions
const SKELETON_COLS = ["3rem", "auto", "9rem", "12rem", "6rem"];

// Shared column template — fixed widths so columns align vertically across
// all entity-group tables (icon | name | type | balance | actions).
function AccountsColgroup() {
  return (
    <colgroup>
      <col className="w-12" />
      <col />
      <col className="w-36" />
      <col className="w-48" />
      <col className="w-24" />
    </colgroup>
  );
}

function AccountBalanceCell({ accountId }: { accountId: number }) {
  const today = new Date().toISOString().slice(0, 10);
  const { data, isLoading } = useSWR<FinAccountBalance>(
    `/api/finance/money-accounts/${accountId}/balance?on_date=${today}`,
    fetcher
  );

  if (isLoading) {
    return <div className="animate-pulse h-4 w-24 bg-gray-100 dark:bg-gray-800 rounded ml-auto" />;
  }
  if (!data) return <span className="text-gray-400 ml-auto">—</span>;

  return (
    <span className="ml-auto font-semibold tabular-nums">
      <MoneyCell amount={data.amount} currency={data.currency} />
    </span>
  );
}

// Отдельный компонент со своим ref/state для scroll-detect — исправляет
// баг «один scrollRef на N таблиц».
const GroupTable = memo(function GroupTable({
  entity,
  accounts: accs,
  canManage,
  onOpenPayment,
  onEditAccount,
}: {
  entity: FinLegalEntity;
  accounts: FinMoneyAccount[];
  canManage: boolean;
  onOpenPayment: (id: number) => void;
  onEditAccount: (acc: FinMoneyAccount | null) => void;
}) {
  const scrollRef = useRef<HTMLDivElement>(null);
  const [scrolled, setScrolled] = useState(false);
  const handleScroll = useCallback(() => {
    if (!scrollRef.current) return;
    setScrolled(scrollRef.current.scrollTop > 0);
  }, []);

  return (
    <div className="card rounded-2xl overflow-hidden shadow-elev-1">
      <div className="px-5 pt-4 pb-2.5 border-b border-gray-100 dark:border-gray-800">
        <h3 className="text-xs font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wider">
          {entity.name}
          <span className="ml-2 font-mono font-normal text-gray-400 dark:text-gray-500 normal-case tracking-normal">
            {entity.functional_currency}
          </span>
        </h3>
      </div>

      <div
        ref={scrollRef}
        className={`fin-table-wrap${scrolled ? " scrolled" : ""} overflow-x-auto fin-table-scroll`}
        onScroll={handleScroll}
      >
        <table className="w-full table-fixed text-sm">
          <AccountsColgroup />
          <thead className="bg-gray-50/60 dark:bg-gray-900/30 border-b border-gray-100 dark:border-gray-800">
            <tr>
              <th className="px-4 py-2" />
              <th className="text-left px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Счёт</th>
              <th className="text-left px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Тип</th>
              <th className="text-right px-4 py-2 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Баланс</th>
              <th className="px-4 py-2" />
            </tr>
          </thead>
          <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
            {accs.map((acc) => (
              <tr
                key={acc.id}
                className="group border-b border-gray-100 dark:border-gray-800 transition-colors duration-100 hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]"
              >
                <td className="px-4 py-3">
                  <i className={`bi ${ACCOUNT_TYPE_ICONS[acc.account_type] ?? "bi-bank"} text-gray-400 dark:text-gray-500 text-base`} />
                </td>
                <td className="px-4 py-3">
                  <span className="block truncate text-sm font-medium text-gray-800 dark:text-gray-200" title={acc.name}>{acc.name}</span>
                </td>
                <td className="px-4 py-3">
                  <span className={`inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium ${acc.is_active ? "bg-green-50 text-green-700 dark:bg-green-900/20 dark:text-green-400" : "bg-gray-100 text-gray-500 dark:bg-gray-800 dark:text-gray-500"}`}>
                    <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
                    {ACCOUNT_TYPE_LABELS[acc.account_type] ?? acc.account_type}
                  </span>
                </td>
                <td className="px-4 py-3 text-right">
                  <AccountBalanceCell accountId={acc.id} />
                </td>
                <td className="px-4 py-3">
                  <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
                    <button
                      type="button"
                      title="Создать операцию"
                      className="p-1.5 text-gray-400 hover:text-primary dark:hover:text-blue-400 rounded transition-colors"
                      onClick={() => onOpenPayment(acc.id)}
                    >
                      <i className="bi bi-plus-circle text-sm" />
                    </button>
                    {canManage && (
                      <button
                        type="button"
                        title="Редактировать"
                        className="p-1.5 text-gray-400 hover:text-primary dark:hover:text-blue-400 rounded transition-colors"
                        onClick={() => onEditAccount(acc)}
                      >
                        <i className="bi bi-pencil text-sm" />
                      </button>
                    )}
                    <Link
                      href={`/finance/accounts/${acc.id}`}
                      className="p-1.5 text-gray-400 hover:text-primary dark:hover:text-blue-400 rounded transition-colors"
                      title="Открыть"
                    >
                      <i className="bi bi-chevron-right text-sm" />
                    </Link>
                  </div>
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      </div>

      {canManage && (
        <div className="px-5 py-3 border-t border-gray-100 dark:border-gray-800">
          <button
            type="button"
            className="text-sm text-primary dark:text-blue-400 hover:underline flex items-center gap-1 transition-colors"
            onClick={() => onEditAccount(null)}
          >
            <i className="bi bi-plus" /> Добавить счёт
          </button>
        </div>
      )}
    </div>
  );
});

export default function AccountsPage() {
  const { user } = useMe();
  const { toast } = useToast();
  const canManage = MANAGE_ROLES.includes(user?.role as never);

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: accounts, isLoading, mutate } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);

  // Фильтр «Юрлицо»: по умолчанию все. Поисковый combobox.
  const [entityFilter, setEntityFilter] = useState<number | null>(null);

  const [modalOpen, setModalOpen] = useState(false);
  const [editAccount, setEditAccount] = useState<FinMoneyAccount | null>(null);
  const [paymentOpen, setPaymentOpen] = useState(false);
  const [paymentAccountId, setPaymentAccountId] = useState<number | undefined>();


  const entityOptions = useMemo(
    () =>
      (entities ?? []).map((e) => ({
        value: e.id,
        label: e.name,
        hint: e.functional_currency,
      })),
    [entities],
  );

  // Group accounts by legal entity (с учётом фильтра юрлица)
  const grouped = (entities ?? [])
    .filter((entity) => entityFilter == null || entity.id === entityFilter)
    .map((entity) => ({
      entity,
      accounts: accounts?.filter((a) => a.legal_entity_id === entity.id) ?? [],
    }))
    .filter((g) => g.accounts.length > 0);

  const hasAnyAccounts = (accounts?.length ?? 0) > 0;

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Счета и Баланс"
          description="расчётные счета и кассы"
          actions={
            canManage ? (
              <button
                type="button"
                className="btn-primary"
                onClick={() => { setEditAccount(null); setModalOpen(true); }}
              >
                <i className="bi bi-plus mr-1" /> Создать счёт
              </button>
            ) : undefined
          }
        />

        <div className="p-6 flex-1 overflow-y-auto space-y-4">
          {/* Фильтр-бар: юрлицо (по умолчанию — все) */}
          <div className="card p-4">
            <div className="flex flex-wrap items-center gap-3">
              <label className="text-sm text-gray-600 dark:text-gray-400">Юрлицо:</label>
              <Combobox<number>
                value={entityFilter}
                onChange={setEntityFilter}
                options={entityOptions}
                placeholder="Все юрлица"
                searchPlaceholder="Поиск юрлица…"
                clearable
                ariaLabel="Фильтр по юрлицу"
                className="w-64"
              />
              <span className="flex items-center gap-1.5 text-xs text-gray-400 dark:text-gray-500 ml-auto">
                <i className="bi bi-info-circle" />
                Баланс производный: начальный остаток + проведённые операции
              </span>
            </div>
          </div>

          {isLoading ? (
            /* Loading skeleton — fin-table паттерн */
            <div className="card rounded-2xl overflow-hidden shadow-elev-1">
              <table className="w-full table-fixed text-sm">
                <AccountsColgroup />
                <thead className="sticky top-0 z-10 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 fin-thead-shadow">
                  <tr>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left px-4 py-2.5" />
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left px-4 py-2.5">Счёт</th>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left px-4 py-2.5">Тип</th>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-right px-4 py-2.5">Баланс</th>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 px-4 py-2.5" />
                  </tr>
                </thead>
                <tbody>
                  <FinTableSkeleton rows={4} cols={SKELETON_COLS} />
                </tbody>
              </table>
            </div>
          ) : !hasAnyAccounts ? (
            <div className="card rounded-2xl shadow-elev-1 overflow-hidden">
              <EmptyState
                icon="bi-bank"
                title="Нет счетов"
                description="Создай расчётный счёт или кассу для начала работы"
                cta={
                  canManage ? (
                    <button
                      type="button"
                      className="btn-primary"
                      onClick={() => { setEditAccount(null); setModalOpen(true); }}
                    >
                      <i className="bi bi-plus mr-1" /> Создать первый счёт
                    </button>
                  ) : undefined
                }
              />
            </div>
          ) : grouped.length === 0 ? (
            <div className="card rounded-2xl shadow-elev-1 overflow-hidden">
              <EmptyState
                icon="bi-funnel"
                title="Нет счетов по фильтру"
                description="У выбранного юрлица нет счетов. Сбросьте фильтр, чтобы увидеть все."
              />
            </div>
          ) : (
            grouped.map(({ entity, accounts: accs }) => (
              <GroupTable
                key={entity.id}
                entity={entity}
                accounts={accs}
                canManage={canManage}
                onOpenPayment={(id) => { setPaymentAccountId(id); setPaymentOpen(true); }}
                onEditAccount={(acc) => { setEditAccount(acc); setModalOpen(true); }}
              />
            ))
          )}
        </div>
      </div>

      <AccountModal
        open={modalOpen}
        onClose={() => { setModalOpen(false); setEditAccount(null); }}
        existing={editAccount}
        onSuccess={() => {
          mutate();
          toast.success(editAccount ? "Счёт обновлён" : "Счёт создан");
        }}
      />

      <PaymentModal
        open={paymentOpen}
        onClose={() => { setPaymentOpen(false); setPaymentAccountId(undefined); }}
        prefilledAccountId={paymentAccountId}
        onSuccess={() => mutate()}
      />
    </RoleGate>
  );
}
