"use client";

import Link from "next/link";
import { formatCurrency } from "@/lib/format";
import type { FinBalanceRow } from "@/lib/types";

interface Props {
  balance: FinBalanceRow;
  accountId: number;
  /** Функц.валюта юрлица — для подписи amount_func, если отличается от валюты счёта. */
  funcCurrency?: string;
}

export function AccountBalanceCard({ balance, accountId, funcCurrency }: Props) {
  const sameCurrency = !funcCurrency || balance.currency === funcCurrency;

  return (
    <Link
      href={`/finance/accounts/${accountId}`}
      className="card p-4 min-w-[180px] hover:shadow-md transition-shadow flex flex-col gap-1 shrink-0"
    >
      <p className="text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider truncate">
        {balance.account_name}
      </p>
      <p className="text-xl font-bold tabular-nums mt-1 dark:text-gray-100">
        {formatCurrency(balance.amount, balance.currency)}
      </p>
      {!sameCurrency && (
        <p className="text-xs text-gray-400 dark:text-gray-500">
          ≈ {formatCurrency(balance.amount_func, funcCurrency ?? null)}
        </p>
      )}
    </Link>
  );
}
