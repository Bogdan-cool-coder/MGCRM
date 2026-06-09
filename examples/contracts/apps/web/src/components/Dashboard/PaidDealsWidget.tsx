"use client";

/**
 * Виджет «Оплачено» (DEALS 2.0 Ф3).
 *
 * GET /api/analytics/deals/paid
 * Сводка завершённых сделок с первой оплатой.
 * Компания | Договор | Сумма | Дата.
 */
import useSWR from "swr";
import Link from "next/link";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import { EmptyState } from "@/components/EmptyState";

interface PaidItem {
  deal_id: number;
  deal_title: string;
  company_name: string;
  company_id: number | null;
  deal_amount: number | null;
  deal_currency: string | null;
  owner_name: string;
  owner_user_id: number | null;
  contract_number: string | null;
  first_payment_date: string | null;
  first_payment_amount: number | null;
  first_payment_currency: string | null;
  paid_at: string | null;
}

interface PaidSummary {
  total_count: number;
  total_amount_by_currency: Record<string, number>;
  primary_currency: string | null;
  grand_total: number;
}

interface PaidDealsResponse {
  items: PaidItem[];
  total_deals: number;
  summary: PaidSummary;
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso.length === 10 ? iso + "T00:00:00" : iso);
  return d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
}

export function PaidDealsWidget() {
  const { data, isLoading, error } = useSWR<PaidDealsResponse>(
    "/analytics/deals/paid",
    fetcher,
    { revalidateOnFocus: false, dedupingInterval: 60_000 },
  );

  const items = data?.items ?? [];
  const summary = data?.summary;

  return (
    <div className="card p-5 flex flex-col min-h-[220px]">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-h5 flex items-center gap-2">
          <i className="bi bi-check-circle text-success" />
          Оплачено
        </h3>
        {summary && summary.total_count > 0 && (
          <div className="flex items-center gap-2">
            <span className="badge badge-success text-xs">
              {summary.total_count} сд.
            </span>
            {summary.primary_currency && (
              <span className="text-sm font-semibold text-success">
                {formatCurrency(summary.grand_total, summary.primary_currency)}
              </span>
            )}
          </div>
        )}
      </div>

      {isLoading && (
        <div className="flex-1 animate-pulse space-y-2">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-8 bg-gray-100 dark:bg-gray-700 rounded" />
          ))}
        </div>
      )}

      {!isLoading && error && (
        <div className="flex-1 flex items-center justify-center">
          <EmptyState icon="bi-exclamation-triangle" title="Не удалось загрузить" />
        </div>
      )}

      {!isLoading && !error && items.length === 0 && (
        <div className="flex-1 flex items-center justify-center">
          <EmptyState icon="bi-check-circle" title="Нет оплаченных сделок" />
        </div>
      )}

      {!isLoading && !error && items.length > 0 && (
        <div className="flex-1 space-y-1 overflow-y-auto max-h-52">
          <div className="grid grid-cols-[1fr_auto_auto_auto] gap-x-2 text-xs font-medium text-gray-500 pb-1 border-b border-gray-100 dark:border-gray-700">
            <span>Компания</span>
            <span className="text-right">Договор</span>
            <span className="text-right">Сумма</span>
            <span className="text-right">Дата</span>
          </div>
          {items.map((item) => (
            <div
              key={item.deal_id}
              className="grid grid-cols-[1fr_auto_auto_auto] gap-x-2 text-sm py-1.5 border-b border-gray-100 dark:border-gray-700 last:border-0"
            >
              <div className="truncate min-w-0">
                {item.company_id ? (
                  <Link
                    href={`/companies/${item.company_id}`}
                    className="text-primary hover:underline"
                    title={item.company_name}
                  >
                    {item.company_name || item.deal_title}
                  </Link>
                ) : (
                  <span className="truncate" title={item.company_name}>
                    {item.company_name || item.deal_title}
                  </span>
                )}
              </div>
              <span className="text-right text-gray-500 text-xs tabular-nums whitespace-nowrap">
                {item.contract_number || "—"}
              </span>
              <span className="tabular-nums text-right whitespace-nowrap font-medium text-success">
                {formatCurrency(
                  item.first_payment_amount ?? item.deal_amount,
                  item.first_payment_currency ?? item.deal_currency,
                )}
              </span>
              <span className="tabular-nums text-right text-gray-500 whitespace-nowrap">
                {fmtDate(item.first_payment_date ?? item.paid_at)}
              </span>
            </div>
          ))}
        </div>
      )}

      <div className="mt-3 pt-2 border-t border-gray-100 dark:border-gray-700">
        <a href="/deals" className="text-xs text-primary hover:underline">
          К доске сделок →
        </a>
      </div>
    </div>
  );
}
