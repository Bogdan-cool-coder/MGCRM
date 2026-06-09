"use client";

import { formatCurrency } from "@/lib/format";
import type { MkCommissionItem } from "@/lib/types";

interface Props {
  items: MkCommissionItem[];
}

export function MkBreakdown({ items }: Props) {
  if (items.length === 0) {
    return (
      <div className="text-sm text-gray-400 py-3 text-center">
        Нет зачтённых платежей в этом периоде
      </div>
    );
  }

  const total = items.reduce((acc, i) => acc + i.commission_amount, 0);

  return (
    <div className="mt-3">
      <div className="text-xs font-semibold text-gray-500 uppercase tracking-wide mb-2">
        Детализация комиссии по сделкам
      </div>
      <table className="w-full text-xs">
        <thead>
          <tr className="text-gray-400 border-b border-gray-100 dark:border-gray-700">
            <th className="text-left py-1.5 pr-3">Клиент</th>
            <th className="text-left py-1.5 pr-3">Договор</th>
            <th className="text-right py-1.5 pr-3">Платёж</th>
            <th className="text-right py-1.5">Комиссия</th>
          </tr>
        </thead>
        <tbody className="divide-y divide-gray-50 dark:divide-gray-800">
          {items.map((item, i) => (
            <tr key={i} className="hover:bg-gray-50 dark:hover:bg-gray-800/50">
              <td className="py-1.5 pr-3 font-medium">{item.client_name}</td>
              <td className="py-1.5 pr-3 text-gray-500">{item.contract_number ?? "—"}</td>
              <td className="py-1.5 pr-3 text-right tabular-nums">
                {formatCurrency(item.payment_amount, item.currency)}
              </td>
              <td className="py-1.5 text-right tabular-nums font-medium">
                {formatCurrency(item.commission_amount, "UZS")}
              </td>
            </tr>
          ))}
        </tbody>
        <tfoot>
          <tr className="border-t-2 border-gray-200 dark:border-gray-600 font-semibold">
            <td colSpan={3} className="py-1.5 pr-3 text-gray-600">Итого комиссия</td>
            <td className="py-1.5 text-right tabular-nums">
              {formatCurrency(total, "UZS")}
            </td>
          </tr>
        </tfoot>
      </table>
    </div>
  );
}
