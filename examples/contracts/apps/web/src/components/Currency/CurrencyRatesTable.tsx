"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { ManualRateModal } from "./ManualRateModal";
import type { CurrencyRate } from "@/lib/types";
import { formatDate } from "@/lib/dates";

interface Props {
  onRefreshed?: () => void;
  currencyFilter?: string[];
}

function formatRate(n: number) {
  return n.toLocaleString("ru-RU", { maximumFractionDigits: 8 });
}

function getStatusBadge(r: CurrencyRate): { label: string; cls: string } {
  if (r.source === "manual") {
    return { label: "Manual override", cls: "bg-warning/10 text-warning" };
  }
  // Считаем "свежим" если дата курса — сегодня или вчера
  const rateTime = new Date(r.rate_date).getTime();
  const now = Date.now();
  const diffH = (now - rateTime) / 3_600_000;
  if (diffH < 48) {
    const h = Math.round(diffH);
    return { label: `Auto-fetched ${h > 0 ? `${h} ч. назад` : "сегодня"}`, cls: "bg-success/10 text-success" };
  }
  return { label: "Не задано", cls: "bg-gray-100 text-gray-500" };
}

export function CurrencyRatesTable({ onRefreshed, currencyFilter }: Props) {
  const { data: rates, isLoading, error, mutate } = useSWR<CurrencyRate[]>("/currency-rates", fetcher);
  const [editRate, setEditRate] = useState<CurrencyRate | null>(null);

  const filteredRates = rates && currencyFilter && currencyFilter.length > 0
    ? rates.filter((r) => currencyFilter.includes(r.from_currency) || currencyFilter.includes(r.to_currency))
    : rates;

  return (
    <>
      <div className="card overflow-hidden">
        <div className="px-5 py-4 border-b border-gray-200 dark:border-gray-700">
          <h3 className="text-h5">Актуальные курсы</h3>
        </div>

        {isLoading && (
          <div className="p-5 space-y-2 animate-pulse">
            {[1, 2, 3, 4].map((i) => (
              <div key={i} className="h-10 bg-gray-100 dark:bg-gray-700 rounded" />
            ))}
          </div>
        )}

        {!isLoading && error && (
          <div className="p-5 text-sm text-danger">Не удалось загрузить курсы</div>
        )}

        {!isLoading && !error && rates && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700/50">
                <tr>
                  <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Пара</th>
                  <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Курс</th>
                  <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Дата</th>
                  <th className="text-left px-4 py-3 text-xs font-medium text-gray-500 uppercase tracking-wide">Статус</th>
                  <th className="px-4 py-3" />
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-100 dark:divide-gray-700">
                {(filteredRates ?? []).map((r) => {
                  const status = getStatusBadge(r);
                  return (
                    <tr key={r.id} className="hover:bg-gray-50 dark:hover:bg-gray-700/30">
                      <td className="px-4 py-3 font-medium">
                        {r.from_currency} → {r.to_currency}
                      </td>
                      <td className="px-4 py-3 tabular-nums">{formatRate(r.rate)}</td>
                      <td className="px-4 py-3 text-gray-500">{formatDate(r.rate_date)}</td>
                      <td className="px-4 py-3">
                        <span className={`badge text-xs ${status.cls}`}>{status.label}</span>
                      </td>
                      <td className="px-4 py-3">
                        <button
                          onClick={() => setEditRate(r)}
                          className="text-gray-400 hover:text-primary transition-colors"
                          title="Редактировать вручную"
                        >
                          <i className="bi bi-pencil" />
                        </button>
                      </td>
                    </tr>
                  );
                })}
                {(filteredRates ?? []).length === 0 && (
                  <tr>
                    <td colSpan={5} className="px-4 py-8 text-center text-gray-400 text-sm">
                      Нет данных
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        )}
      </div>

      <ManualRateModal
        open={!!editRate}
        onClose={() => setEditRate(null)}
        onSaved={() => {
          mutate();
          onRefreshed?.();
        }}
        editRate={editRate}
      />
    </>
  );
}
