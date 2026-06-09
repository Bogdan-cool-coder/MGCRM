"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import { BorderBeam } from "@/components/magicui/BorderBeam";
import { EmptyState } from "@/components/EmptyState";
import type { HotDeal } from "@/lib/types";

export function HotDealsWidget() {
  const { data: rawDeals, isLoading, error } = useSWR<HotDeal[]>(
    "/deals/hot?owner=me&limit=10",
    fetcher,
  );
  // Клиентский лимит — бэкенд может проигнорировать limit
  const deals = rawDeals?.slice(0, 10);

  return (
    <div className="relative rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-2 p-6 lift flex flex-col min-h-[220px]">
      <BorderBeam colorFrom="#F79009" colorTo="#fec84b" duration={5} />

      <h3 className="text-base font-semibold mb-4 flex items-center gap-2">
        <span className="h-7 w-7 grid place-items-center rounded-lg bg-warning-50 dark:bg-warning-500/10 text-warning-600 shrink-0">
          <i className="bi bi-fire text-sm" aria-hidden="true" />
        </span>
        HOT-сделки
      </h3>

      {isLoading && (
        <div className="flex-1 space-y-2" aria-busy="true" aria-label="Загружаем данные">
          {[1, 2, 3].map((i) => (
            <div key={i} className="flex items-center gap-2 py-2">
              <div className="w-2 h-2 rounded-full bg-gray-200 dark:bg-gray-700 animate-pulse shrink-0" />
              <div className="flex-1 h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
              <div className="w-14 h-4 bg-gray-100 dark:bg-gray-700 rounded animate-pulse" />
            </div>
          ))}
        </div>
      )}

      {!isLoading && error && (
        <div className="flex-1 flex items-center justify-center">
          <EmptyState icon="bi-exclamation-triangle" title="Не удалось загрузить" />
        </div>
      )}

      {!isLoading && !error && (!deals || deals.length === 0) && (
        <div className="flex-1 flex items-center justify-center">
          <EmptyState icon="bi-fire" title="Нет горящих сделок" />
        </div>
      )}

      {!isLoading && !error && deals && deals.length > 0 && (
        <div className="flex-1 space-y-1">
          {deals.map((d) => {
            const href = d.company_id ? `/companies/${d.company_id}` : "/deals";
            return (
              <Link
                key={d.id}
                href={href}
                className="flex items-center gap-2 py-1.5 border-b border-gray-100 dark:border-gray-700/50 last:border-0 rounded hover:bg-gray-50 dark:hover:bg-gray-700 px-1 -mx-1 transition-colors"
              >
                <span
                  className="w-2 h-2 rounded-full shrink-0"
                  style={{ backgroundColor: d.stage_color ?? "#F79009" }}
                  aria-hidden="true"
                />
                <span className="flex-1 text-sm truncate font-medium" title={d.title}>{d.title}</span>
                {d.amount != null && (
                  <span className="text-xs tabular-nums text-gray-600 dark:text-gray-300 shrink-0">
                    {formatCurrency(d.amount, d.currency)}
                  </span>
                )}
                {d.heat_reason === "deadline" && d.days_to_close != null && (
                  <span className={`text-xs shrink-0 font-semibold ${d.days_to_close <= 0 ? "text-danger-600" : d.days_to_close <= 3 ? "text-warning-600" : "text-gray-500"}`}>
                    {d.days_to_close <= 0 ? "просрочено" : `${d.days_to_close} дн.`}
                  </span>
                )}
                {d.heat_reason === "idle" && (
                  <span className="text-xs shrink-0 text-gray-400">{d.idle_days} дн. стоит</span>
                )}
              </Link>
            );
          })}
        </div>
      )}

      <div className="mt-3 pt-2 border-t border-gray-100 dark:border-gray-700">
        <Link href="/deals" className="text-xs text-primary dark:text-blue-300 hover:underline inline-flex items-center gap-1">
          Все HOT-сделки <i className="bi bi-arrow-right" aria-hidden="true" />
        </Link>
      </div>
    </div>
  );
}
