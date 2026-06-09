"use client";

import { formatCurrency, formatAmount } from "@/lib/format";

interface Props {
  inflow: number;
  outflow: number;
  /** Опционально — в Ф0 sum-footer считается без разреза по валюте. */
  currency?: string;
}

export function FinSumFooter({ inflow, outflow, currency }: Props) {
  const net = inflow - outflow;
  const netPositive = net >= 0;

  return (
    <div className="flex items-center gap-6 px-4 py-3 border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/60 text-sm">
      <span className="flex items-center gap-1.5">
        <i className="bi bi-arrow-up-circle text-success" />
        <span className="text-gray-500 dark:text-gray-400">Приток:</span>
        <span className="tabular-nums font-semibold text-success">
          {currency ? formatCurrency(inflow, currency) : formatAmount(inflow)}
        </span>
      </span>
      <span className="flex items-center gap-1.5">
        <i className="bi bi-arrow-down-circle text-danger" />
        <span className="text-gray-500 dark:text-gray-400">Отток:</span>
        <span className="tabular-nums font-semibold text-danger">
          {currency ? formatCurrency(outflow, currency) : formatAmount(outflow)}
        </span>
      </span>
      <span className="flex items-center gap-1.5 ml-auto">
        <span className="text-gray-500 dark:text-gray-400">Итого:</span>
        <span className={`tabular-nums font-semibold ${netPositive ? "text-primary dark:text-white" : "text-danger"}`}>
          {netPositive ? "+" : "−"}{currency ? formatCurrency(Math.abs(net), currency) : formatAmount(Math.abs(net))}
        </span>
      </span>
    </div>
  );
}
