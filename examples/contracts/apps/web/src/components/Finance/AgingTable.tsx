"use client";

import React from "react";

/**
 * AgingTable — таблица AR/AP aging.
 * Бакеты: current, 0-30, 31-60, 61-90, 90+
 * Backend возвращает AgingReportOut: docs[], by_bucket{}, total, total_amount.
 */

import type { FinAgingReport, FinAgingDoc } from "@/lib/types";
import { MoneyCell, formatAmount } from "./MoneyCell";

const BUCKETS = ["current", "0-30", "31-60", "61-90", "90+"] as const;

const BUCKET_LABELS: Record<string, string> = {
  current:  "Текущий",
  "0-30":   "0–30 дн.",
  "31-60":  "31–60 дн.",
  "61-90":  "61–90 дн.",
  "90+":    "90+ дн.",
};

const BUCKET_COLORS: Record<string, string> = {
  current:  "text-success",
  "0-30":   "text-gray-700 dark:text-gray-300",
  "31-60":  "text-warning",
  "61-90":  "text-orange-600 dark:text-orange-400",
  "90+":    "text-danger",
};

interface Props {
  report: FinAgingReport;
}

export function AgingTable({ report }: Props) {
  // Группируем docs по counterparty_company_id
  const byCounterparty = new Map<number | null, FinAgingDoc[]>();
  for (const doc of report.docs) {
    const key = doc.counterparty_company_id;
    const arr = byCounterparty.get(key) ?? [];
    arr.push(doc);
    byCounterparty.set(key, arr);
  }

  return (
    <div className="card overflow-hidden">
      {/* Сводка по бакетам */}
      <div className="grid grid-cols-5 border-b border-gray-200 dark:border-gray-700">
        {BUCKETS.map((bucket) => {
          const amount = parseFloat(String(report.by_bucket[bucket] ?? "0"));
          return (
            <div key={bucket} className="p-4 text-center border-r last:border-r-0 border-gray-200 dark:border-gray-700">
              <div className="text-xs text-gray-500 dark:text-gray-400 mb-1">
                {BUCKET_LABELS[bucket]}
              </div>
              <div className={`text-base tabular-nums font-bold ${BUCKET_COLORS[bucket]}`}>
                {formatAmount(amount)}
              </div>
            </div>
          );
        })}
      </div>

      {/* Строки по контрагентам */}
      <div className="overflow-x-auto">
        <table className="w-full text-sm">
          <thead>
            <tr className="bg-gray-50 dark:bg-gray-800 text-gray-500 dark:text-gray-400 text-xs">
              <th className="text-left px-4 py-3 font-medium">Контрагент / Документ</th>
              <th className="text-right px-4 py-3 font-medium">Дата погашения</th>
              <th className="text-right px-4 py-3 font-medium">Остаток</th>
              <th className="text-center px-4 py-3 font-medium">Бакет</th>
            </tr>
          </thead>
          <tbody>
            {Array.from(byCounterparty.entries()).map(([cpId, docs]) => (
              <React.Fragment key={cpId ?? "none"}>
                {/* Строка контрагента */}
                <tr
                  className="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/40"
                >
                  <td colSpan={4} className="px-4 py-2 font-medium text-gray-700 dark:text-gray-300 text-xs uppercase tracking-wide">
                    {cpId ? `Контрагент #${cpId}` : "Без контрагента"}
                  </td>
                </tr>
                {/* Строки документов */}
                {docs.map((doc) => (
                  <tr
                    key={`doc-${doc.doc_id}`}
                    className="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50"
                  >
                    <td className="px-4 py-2.5 pl-8 text-gray-600 dark:text-gray-400">
                      {doc.number ?? `#${doc.doc_id}`}
                    </td>
                    <td className="px-4 py-2.5 text-right text-gray-500 dark:text-gray-500">
                      {doc.due_date ?? "—"}
                    </td>
                    <td className="px-4 py-2.5 text-right">
                      <MoneyCell amount={doc.outstanding} currency={doc.currency} />
                    </td>
                    <td className="px-4 py-2.5 text-center">
                      <span className={`text-xs font-medium ${BUCKET_COLORS[doc.bucket]}`}>
                        {BUCKET_LABELS[doc.bucket] ?? doc.bucket}
                      </span>
                    </td>
                  </tr>
                ))}
              </React.Fragment>
            ))}
          </tbody>
        </table>
      </div>

      {/* Footer */}
      <div className="flex items-center justify-end gap-4 px-4 py-3 border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/60 text-sm">
        <span className="text-gray-500 dark:text-gray-400">Итого:</span>
        <span className="tabular-nums font-bold text-gray-900 dark:text-gray-100">
          {formatAmount(parseFloat(String(report.total)))}
        </span>
      </div>
    </div>
  );
}
