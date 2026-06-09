"use client";

import Link from "next/link";
import { EmptyState } from "@/components/EmptyState";
import { formatCurrency } from "@/lib/format";
import type { MeDashboardDeal } from "@/lib/types";

interface Props {
  deals?: MeDashboardDeal[];
  isLoading?: boolean;
}

function heatBadge(score: number | null) {
  if (score == null) return "bg-info/10 text-info";
  if (score >= 70) return "bg-danger/10 text-danger";
  if (score >= 40) return "bg-warning/10 text-warning";
  return "bg-info/10 text-info";
}

function heatIcon(score: number | null) {
  if (score != null && score >= 70) return "bi-fire";
  return "bi-kanban";
}

export function ActiveDealsWidget({ deals, isLoading }: Props) {
  return (
    <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 flex flex-col">
      <div className="flex items-center justify-between mb-4">
        <span className="text-sm font-semibold text-gray-900 dark:text-white flex items-center gap-2">
          <i className="bi bi-kanban text-primary" aria-hidden="true" />
          Горячие сделки
        </span>
        <Link href="/deals" className="btn-ghost text-xs">
          Все сделки
          <i className="bi bi-arrow-right ml-1" aria-hidden="true" />
        </Link>
      </div>

      {isLoading && (
        <div className="space-y-2 animate-pulse">
          {[1, 2, 3, 4, 5].map((i) => (
            <div key={i} className="h-11 bg-gray-100 dark:bg-gray-700 rounded-xl" />
          ))}
        </div>
      )}

      {!isLoading && (!deals || deals.length === 0) && (
        <div className="flex-1">
          <EmptyState
            icon="bi-kanban"
            title="Нет активных сделок"
            description="Горячие сделки появятся здесь"
          />
        </div>
      )}

      {!isLoading && deals && deals.length > 0 && (
        <div className="space-y-1">
          {deals.slice(0, 10).map((d, idx) => (
            <Link
              key={d.id}
              href={`/deals/${d.id}`}
              className="blur-fade flex items-center gap-3 py-2 px-2.5 rounded-xl hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors lift"
              style={{ "--blur-fade-duration": `${0.3 + idx * 0.05}s` } as React.CSSProperties}
            >
              <span className={`w-7 h-7 grid place-items-center rounded-lg shrink-0 ${heatBadge(d.heat_score)}`}>
                <i className={`bi ${heatIcon(d.heat_score)} text-xs`} aria-hidden="true" />
              </span>
              <div className="flex-1 min-w-0">
                <div className="text-sm font-medium truncate">{d.title}</div>
                <div className="text-xs text-gray-500 truncate">
                  {d.counterparty_name} · {d.stage_name}
                </div>
              </div>
              <div className="text-right shrink-0">
                {d.amount != null && (
                  <div className="text-xs font-medium tabular-nums text-gray-700 dark:text-gray-300">
                    {formatCurrency(d.amount, d.currency)}
                  </div>
                )}
              </div>
            </Link>
          ))}
          {deals.length > 10 && (
            <Link
              href="/deals"
              className="flex items-center justify-center gap-1.5 py-2 px-2.5 rounded-xl text-xs text-gray-500 dark:text-gray-400 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
            >
              <i className="bi bi-three-dots" aria-hidden="true" />
              Ещё {deals.length - 10} сделок
              <i className="bi bi-arrow-right ml-0.5" aria-hidden="true" />
            </Link>
          )}
        </div>
      )}
    </div>
  );
}
