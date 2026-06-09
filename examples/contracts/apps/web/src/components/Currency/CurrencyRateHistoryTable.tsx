"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import type { CurrencyRate } from "@/lib/types";
import { formatDate } from "@/lib/dates";

const PAIRS = [
  { from: "RUB", to: "UZS" },
  { from: "KZT", to: "UZS" },
  { from: "USD", to: "UZS" },
  { from: "AED", to: "UZS" },
];

export function CurrencyRateHistoryTable() {
  const [pair, setPair] = useState("RUB_UZS");
  const [from, to] = pair.split("_");

  const { data: history, isLoading } = useSWR<CurrencyRate[]>(
    `/currency-rates?from=${from}&to=${to}&limit=30`,
    fetcher,
  );

  function calcDelta(i: number, arr: CurrencyRate[]): { val: number; pct: number } | null {
    if (i >= arr.length - 1) return null;
    const curr = arr[i].rate;
    const prev = arr[i + 1].rate;
    if (!prev) return null;
    return { val: curr - prev, pct: ((curr - prev) / prev) * 100 };
  }

  return (
    <div className="card overflow-hidden">
      <div className="px-5 py-4 border-b border-gray-200 dark:border-gray-700 flex items-center justify-between">
        <h3 className="text-h5">История курсов</h3>
        <select
          className="input w-36"
          value={pair}
          onChange={(e) => setPair(e.target.value)}
        >
          {PAIRS.map((p) => (
            <option key={`${p.from}_${p.to}`} value={`${p.from}_${p.to}`}>
              {p.from} → {p.to}
            </option>
          ))}
        </select>
      </div>

      {isLoading && (
        <div className="p-5 space-y-2 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-10 bg-gray-100 dark:bg-gray-700 rounded" />
          ))}
        </div>
      )}

      {!isLoading && history && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead className="bg-gray-50 dark:bg-gray-700/50">
              <tr>
                <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Дата</th>
                <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Курс</th>
                <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Изменение</th>
                <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Источник</th>
              </tr>
            </thead>
            <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
              {history.map((r, i) => {
                const delta = calcDelta(i, history);
                return (
                  <tr key={r.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                    <td className="px-4 py-3 text-gray-600">{formatDate(r.rate_date)}</td>
                    <td className="px-4 py-3 tabular-nums font-medium">{(r.rate ?? 0).toLocaleString("ru-RU")}</td>
                    <td className="px-4 py-3 tabular-nums">
                      {delta ? (
                        <span className={delta.val > 0 ? "text-success" : delta.val < 0 ? "text-danger" : "text-gray-400"}>
                          {delta.val > 0 ? "+" : ""}{(delta.val ?? 0).toFixed(2)} ({delta.val > 0 ? "+" : ""}{(delta.pct ?? 0).toFixed(2)}%)
                        </span>
                      ) : (
                        <span className="text-gray-400">—</span>
                      )}
                    </td>
                    <td className="px-4 py-3">
                      {r.source === "manual" ? (
                        <span className="badge bg-warning/10 text-warning">Вручную</span>
                      ) : (
                        <span className="badge bg-info/10 text-info">API</span>
                      )}
                    </td>
                  </tr>
                );
              })}
              {history.length === 0 && (
                <tr>
                  <td colSpan={4} className="px-4 py-8 text-center text-gray-400 text-sm">
                    Нет данных за период
                  </td>
                </tr>
              )}
            </tbody>
          </table>
        </div>
      )}
    </div>
  );
}
