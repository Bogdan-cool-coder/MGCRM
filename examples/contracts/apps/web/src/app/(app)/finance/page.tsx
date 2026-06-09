"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { KpiCard } from "@/components/Dashboard/KpiCard";
import { EmptyState } from "@/components/EmptyState";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { formatCurrency } from "@/lib/format";
import { PaymentModal } from "@/components/Finance/PaymentModal";
import { fetcher } from "@/lib/api";
import { periodToRange } from "@/lib/finance";
import type {
  FinBalancesResponse,
  FinOperationListResponse,
  FinCashflowReport,
  FinLegalEntity,
  FinBalanceRow,
} from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;

function currentPeriod(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

function todayStr(): string {
  return new Date().toISOString().slice(0, 10);
}

/** Суммируем остатки по всем счетам в функц. валюте */
function sumBalances(rows: FinBalanceRow[]): number {
  return rows.reduce((acc, r) => acc + (r.amount_func ?? r.amount), 0);
}

export default function FinanceDashboardPage() {
  const [paymentOpen, setPaymentOpen] = useState(false);

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const entityId = entities && entities.length > 0 ? entities[0].id : null;
  const funcCurrency = entities?.find((e) => e.id === entityId)?.functional_currency;

  const { data: balancesResp, isLoading: balancesLoading } = useSWR<FinBalancesResponse>(
    entityId ? `/api/finance/balances?legal_entity_id=${entityId}&on_date=${todayStr()}` : null,
    fetcher
  );
  const balances = balancesResp?.rows ?? [];

  const { data: pendingOps, isLoading: pendingLoading } = useSWR<FinOperationListResponse>(
    `/api/finance/operations?status=to_pay&date_to=${todayStr()}&limit=5`,
    fetcher
  );

  const { date_from, date_to } = periodToRange(currentPeriod());
  const { data: cashflow, isLoading: cashflowLoading } = useSWR<FinCashflowReport>(
    entityId
      ? `/api/finance/reports/cashflow-simple?legal_entity_id=${entityId}&date_from=${date_from}&date_to=${date_to}`
      : null,
    fetcher
  );

  // KPI values: undefined = loading skeleton, number = rendered
  const totalBalance: number | undefined =
    balancesLoading || !balancesResp ? undefined : sumBalances(balances);
  const inflowVal: number | undefined =
    cashflowLoading || (!cashflow && entityId !== null) ? undefined : cashflow?.inflow;
  const outflowVal: number | undefined =
    cashflowLoading || (!cashflow && entityId !== null) ? undefined : cashflow ? Math.abs(cashflow.outflow) : undefined;
  const netVal: number | undefined =
    cashflowLoading || (!cashflow && entityId !== null) ? undefined : cashflow?.net;

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Финансы"
          eyebrow="Управленческий учёт"
          sticky
          actions={
            <button className="btn-primary" onClick={() => setPaymentOpen(true)}>
              <i className="bi bi-plus mr-1" aria-hidden="true" />
              Создать операцию
            </button>
          }
        />

        <div className="p-6 flex flex-col gap-6">

          {/* ── KPI-ряд (Design v2) ──────────────────────────────────────── */}
          <section aria-label="Ключевые показатели">
            <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
              {/* Остаток на счетах — в функц. валюте */}
              <KpiCard
                label="Остаток на счетах"
                value={totalBalance}
                suffix={funcCurrency ? ` ${funcCurrency}` : ""}
                href="/finance/accounts"
                iconClass="bi-bank"
                iconBg="bg-info-50 dark:bg-info-500/10"
                iconColor="text-info-600"
              />

              {/* Приток за месяц */}
              <KpiCard
                label="Приток за месяц"
                value={inflowVal}
                suffix={funcCurrency ? ` ${funcCurrency}` : ""}
                href="/finance/cashflow"
                iconClass="bi-arrow-up-circle"
                iconBg="bg-success-50 dark:bg-success-500/10"
                iconColor="text-success-600"
              />

              {/* Отток за месяц */}
              <KpiCard
                label="Отток за месяц"
                value={outflowVal}
                suffix={funcCurrency ? ` ${funcCurrency}` : ""}
                href="/finance/cashflow"
                iconClass="bi-arrow-down-circle"
                iconBg="bg-danger-50 dark:bg-danger-500/10"
                iconColor="text-danger-600"
              />

              {/* Нетто за месяц */}
              <KpiCard
                label="Нетто за месяц"
                value={netVal}
                suffix={funcCurrency ? ` ${funcCurrency}` : ""}
                href="/finance/cashflow"
                iconClass="bi-graph-up-arrow"
                iconBg="bg-primary/5 dark:bg-white/5"
                iconColor="text-primary dark:text-gray-300"
                invertColor={false}
              />
            </div>
          </section>

          {/* ── Счета (bento-ряд карточек) ───────────────────────────────── */}
          <section aria-label="Остатки по счетам">
            <SectionEyebrow label="Счета" href="/finance/accounts" linkLabel="Все счета" />

            {balancesLoading ? (
              /* Skeleton: 3 плитки */
              <div className="flex gap-3">
                {[1, 2, 3].map((i) => (
                  <div
                    key={i}
                    className="rounded-2xl h-[100px] min-w-[200px] animate-pulse bg-gray-100 dark:bg-gray-700"
                    aria-busy="true"
                    aria-label="Загружаем данные"
                  />
                ))}
              </div>
            ) : balances.length === 0 ? (
              <div className="rounded-2xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-elev-1 p-6">
                <EmptyState
                  icon="bi-bank"
                  title="Нет счетов"
                  description="Добавьте первый счёт, чтобы начать вести учёт денежных средств"
                  cta={
                    <Link href="/finance/accounts" className="btn-primary text-sm">
                      Добавить счёт
                    </Link>
                  }
                />
              </div>
            ) : (
              <div className="flex gap-3 overflow-x-auto pb-1">
                {balances.map((b) => (
                  <AccountBalanceCardV2
                    key={b.money_account_id}
                    balance={b}
                    accountId={b.money_account_id}
                    funcCurrency={funcCurrency}
                  />
                ))}
              </div>
            )}
          </section>

          {/* ── Bento нижний ряд: К оплате + ДДС ───────────────────────── */}
          <div className="grid grid-cols-1 md:grid-cols-2 gap-4">

            {/* К оплате сегодня */}
            <section
              className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 flex flex-col"
              aria-label="К оплате сегодня"
            >
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-300 flex items-center gap-2">
                  <span className="h-7 w-7 grid place-items-center rounded-lg bg-warning-50 dark:bg-warning-500/10 shrink-0">
                    <i className="bi bi-hourglass-split text-xs text-warning-600" aria-hidden="true" />
                  </span>
                  К оплате сегодня
                </h2>
                <Link
                  href="/finance/operations?status=to_pay"
                  className="text-xs text-primary dark:text-blue-400 hover:underline"
                >
                  Все →
                </Link>
              </div>

              {pendingLoading ? (
                <div className="space-y-2.5 flex-1">
                  {[1, 2, 3].map((i) => (
                    <div key={i} className="flex items-center justify-between">
                      <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-1/2" />
                      <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-1/4" />
                    </div>
                  ))}
                </div>
              ) : !pendingOps?.items?.length ? (
                <div className="flex-1 flex items-center justify-center">
                  <EmptyState
                    icon="bi-check2-circle"
                    title="Нет платежей на сегодня"
                  />
                </div>
              ) : (
                <ul className="space-y-1 flex-1">
                  {pendingOps.items.map((op) => (
                    <li
                      key={op.id}
                      className="group flex items-center justify-between text-sm rounded-lg px-2 py-1.5 hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors"
                    >
                      <Link
                        href={`/finance/operations/${op.id}`}
                        className="text-gray-700 dark:text-gray-300 hover:text-primary dark:hover:text-blue-400 truncate flex-1 pr-3"
                      >
                        {op.purpose || op.number || `Операция #${op.id}`}
                      </Link>
                      <MoneyCell
                        amount={op.amount}
                        currency={op.currency}
                        direction="out"
                      />
                    </li>
                  ))}
                </ul>
              )}
            </section>

            {/* ДДС за месяц */}
            <section
              className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 flex flex-col"
              aria-label="ДДС за месяц"
            >
              <div className="flex items-center justify-between mb-4">
                <h2 className="text-sm font-semibold text-gray-700 dark:text-gray-300 flex items-center gap-2">
                  <span className="h-7 w-7 grid place-items-center rounded-lg bg-info-50 dark:bg-info-500/10 shrink-0">
                    <i className="bi bi-bar-chart-line text-xs text-info-600" aria-hidden="true" />
                  </span>
                  ДДС за месяц
                </h2>
                <Link
                  href="/finance/cashflow"
                  className="text-xs text-primary dark:text-blue-400 hover:underline"
                >
                  Открыть ДДС →
                </Link>
              </div>

              {cashflowLoading ? (
                <div className="space-y-3 flex-1">
                  {[1, 2, 3].map((i) => (
                    <div key={i} className="flex items-center justify-between">
                      <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-2/5" />
                      <div className="h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-1/3" />
                    </div>
                  ))}
                </div>
              ) : !cashflow ? (
                <div className="flex-1 flex items-center justify-center">
                  <EmptyState
                    icon="bi-bar-chart"
                    title="Нет данных за этот месяц"
                  />
                </div>
              ) : (
                <div className="space-y-2 flex-1">
                  <CashflowRow
                    label="Приток"
                    iconClass="bi-arrow-up-circle"
                    iconColor="text-success-500"
                    amount={cashflow.inflow}
                    currency={funcCurrency ?? ""}
                    direction="in"
                  />
                  <CashflowRow
                    label="Отток"
                    iconClass="bi-arrow-down-circle"
                    iconColor="text-danger-500"
                    amount={Math.abs(cashflow.outflow)}
                    currency={funcCurrency ?? ""}
                    direction="out"
                  />
                  <div className="border-t border-gray-100 dark:border-gray-700 pt-2 mt-1">
                    <div className="flex items-center justify-between text-sm">
                      <span className="font-medium text-gray-700 dark:text-gray-300">
                        Нетто
                      </span>
                      <span
                        className={[
                          "tabular-nums font-bold",
                          cashflow.net >= 0
                            ? "text-success-600 dark:text-success-500"
                            : "text-danger-600 dark:text-danger-500",
                        ].join(" ")}
                      >
                        {cashflow.net >= 0 ? "+" : "−"}
                        {formatCurrency(Math.abs(cashflow.net), funcCurrency ?? null)}
                      </span>
                    </div>
                  </div>
                </div>
              )}
            </section>

          </div>
        </div>

        <PaymentModal open={paymentOpen} onClose={() => setPaymentOpen(false)} />
      </div>
    </RoleGate>
  );
}

// ── Subcomponents ─────────────────────────────────────────────────────────────

interface SectionEyebrowProps {
  label: string;
  href?: string;
  linkLabel?: string;
}

function SectionEyebrow({ label, href, linkLabel }: SectionEyebrowProps) {
  return (
    <div className="flex items-center justify-between mb-3">
      <p className="text-[11px] uppercase tracking-wider text-gray-400 dark:text-gray-500 font-semibold">
        {label}
      </p>
      {href && linkLabel && (
        <Link href={href} className="text-xs text-primary dark:text-blue-400 hover:underline">
          {linkLabel} →
        </Link>
      )}
    </div>
  );
}

interface AccountBalanceCardV2Props {
  balance: FinBalanceRow;
  accountId: number;
  funcCurrency?: string;
}

/** AccountBalanceCard переведён на Design v2: rounded-2xl, shadow-elev-1, lift, Magic spotlight. */
function AccountBalanceCardV2({ balance, accountId, funcCurrency }: AccountBalanceCardV2Props) {
  const sameCurrency = !funcCurrency || balance.currency === funcCurrency;

  return (
    <Link
      href={`/finance/accounts/${accountId}`}
      className="lift kpi-magic rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-4 min-w-[200px] shrink-0 flex flex-col gap-1 relative overflow-hidden"
    >
      {/* spotlight overlay */}
      <div
        className="kpi-magic-spotlight pointer-events-none absolute inset-0 opacity-0 transition-opacity duration-300 rounded-2xl"
        aria-hidden="true"
      />
      <p className="text-[11px] text-gray-500 dark:text-gray-400 uppercase tracking-wider truncate relative z-10">
        {balance.account_name}
      </p>
      <p className="text-2xl font-bold tabular-nums mt-1 text-gray-900 dark:text-gray-100 relative z-10">
        {formatCurrency(balance.amount, balance.currency)}
      </p>
      {!sameCurrency && (
        <p className="text-xs text-gray-400 dark:text-gray-500 relative z-10">
          ≈ {formatCurrency(balance.amount_func, funcCurrency ?? null)}
        </p>
      )}
    </Link>
  );
}

interface CashflowRowProps {
  label: string;
  iconClass: string;
  iconColor: string;
  amount: number;
  currency: string;
  direction: "in" | "out";
}

function CashflowRow({ label, iconClass, iconColor, amount, currency, direction }: CashflowRowProps) {
  return (
    <div className="flex items-center justify-between text-sm py-0.5">
      <span className={`flex items-center gap-1.5 text-gray-600 dark:text-gray-400`}>
        <i className={`bi ${iconClass} ${iconColor}`} aria-hidden="true" />
        {label}
      </span>
      <MoneyCell amount={amount} currency={currency} direction={direction} />
    </div>
  );
}
