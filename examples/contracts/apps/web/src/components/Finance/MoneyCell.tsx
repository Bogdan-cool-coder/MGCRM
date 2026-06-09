"use client";

import type { FinDirection } from "@/lib/types";
import { formatCurrency, formatAmount as formatAmountLib } from "@/lib/format";

interface Props {
  amount: number | string;
  currency: string;
  direction?: FinDirection;
  /** Явно указать позитивность (переопределяет логику direction) */
  positive?: boolean;
}

export function MoneyCell({ amount, currency, direction, positive }: Props) {
  const n = typeof amount === "string" ? parseFloat(amount) : amount;

  let colorClass = "text-gray-700 dark:text-gray-300";
  let sign = "";

  if (direction === "transfer") {
    colorClass = "text-gray-700 dark:text-gray-300";
  } else if (positive !== undefined) {
    colorClass = positive ? "text-success" : "text-danger";
    sign = positive ? "+" : "−";
  } else if (direction === "in") {
    colorClass = "text-success";
    sign = "+";
  } else if (direction === "out") {
    colorClass = "text-danger";
    sign = "−";
  }

  // formatCurrency включает символ валюты после числа (напр. "4 000 ₽", "4,000 $")
  const formatted = formatCurrency(Math.abs(n), currency);

  return (
    <span className={`tabular-nums font-semibold ${colorClass}`}>
      {sign}{formatted}
    </span>
  );
}

/**
 * Re-export для обратной совместимости.
 * Все существующие импорты `import { formatAmount } from "@/components/Finance/MoneyCell"`
 * продолжают работать — теперь это formatAmount из @/lib/format (только число, без символа).
 */
export { formatAmountLib as formatAmount };
