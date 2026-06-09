"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";

/**
 * Тайл «Сделки без задач» (Wave 2b, KPI-ряд).
 * Amber-тон при count>0, success-тон при нуле (всё под контролем).
 * Число — deep-link на /deals?no_tasks=true.
 */
export function DealsWithoutTasksTile() {
  const { data } = useSWR<{ count: number }>("/analytics/deals/without-tasks", fetcher);
  const count = data?.count;
  const isZero = count === 0;

  const borderColor = isZero ? "border-success-300 dark:border-success-500/30" : "border-warning-300 dark:border-warning-500/30";
  const surfaceBg = isZero ? "bg-success-50 dark:bg-success-500/10" : "bg-warning-50 dark:bg-warning-500/10";

  return (
    <Link
      href="/deals?no_tasks=true"
      className={`rounded-2xl shadow-elev-1 hover:shadow-elev-2 lift p-5 block border ${borderColor} ${surfaceBg}`}
    >
      <div className="flex items-center justify-between mb-2">
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">Сделки без задач</span>
        <span className={`h-8 w-8 grid place-items-center rounded-lg ${isZero ? "bg-success-100 dark:bg-success-500/20 text-success-600" : "bg-warning-100 dark:bg-warning-500/20 text-warning-600"} shrink-0`}>
          <i className="bi bi-clipboard-x text-sm" aria-hidden="true" />
        </span>
      </div>
      <div className={`text-2xl font-bold tabular-nums mt-1 ${isZero ? "text-success-700 dark:text-success-500" : "text-warning-700 dark:text-warning-500"}`}>
        {count ?? "—"}
      </div>
      <div className="text-[11px] text-gray-400 dark:text-gray-500 mt-1">
        {isZero ? "все под контролем" : "требуют задачи"}
      </div>
    </Link>
  );
}
