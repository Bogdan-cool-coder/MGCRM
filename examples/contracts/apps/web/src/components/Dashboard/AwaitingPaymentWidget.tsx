"use client";

/**
 * Виджет «Ожидаем оплату» (DEALS 2.0 Ф3).
 *
 * GET /api/analytics/deals/awaiting-payment
 * Показывает список сделок в подстатусе await_payment с ближайшими
 * ожидаемыми платежами из contract_payments (payment_date >= today).
 * Внизу — разбивка по месяцам.
 */
import useSWR from "swr";
import Link from "next/link";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import { EmptyState } from "@/components/EmptyState";

interface PaymentRow {
  payment_id: number;
  payment_date: string;
  amount: number;
  currency: string;
  notes: string | null;
}

interface AwaitingItem {
  deal_id: number;
  deal_title: string;
  company_name: string;
  company_id: number | null;
  deal_amount: number | null;
  deal_currency: string | null;
  owner_name: string;
  owner_user_id: number | null;
  expected_close_date: string | null;
  has_contract: boolean;
  payments: PaymentRow[];
}

interface MonthBucket {
  [currency: string]: number;
}

interface AwaitingPaymentResponse {
  items: AwaitingItem[];
  total_deals: number;
  aggregation: {
    by_week: Record<string, MonthBucket>;
    by_month: Record<string, MonthBucket>;
    grand_total: number;
    primary_currency: string | null;
  };
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso + "T00:00:00");
  return d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
}

export function AwaitingPaymentWidget() {
  const { data, isLoading, error } = useSWR<AwaitingPaymentResponse>(
    "/analytics/deals/awaiting-payment",
    fetcher,
    { revalidateOnFocus: false, dedupingInterval: 60_000 },
  );

  const items = data?.items ?? [];
  const agg = data?.aggregation;
  const byMonth = agg ? Object.entries(agg.by_month) : [];

  return (
    <div className="card p-5 flex flex-col min-h-[220px]">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-h5 flex items-center gap-2">
          <i className="bi bi-hourglass-split text-warning" />
          Ожидаем оплату
        </h3>
        {data && data.total_deals > 0 && agg?.primary_currency && (
          <span className="text-sm font-semibold text-warning">
            {formatCurrency(agg.grand_total, agg.primary_currency)}
          </span>
        )}
      </div>

      {isLoading && (
        <div className="flex-1 animate-pulse space-y-2">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-10 bg-gray-100 dark:bg-gray-700 rounded" />
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
          <EmptyState icon="bi-hourglass" title="Нет сделок в ожидании оплаты" />
        </div>
      )}

      {!isLoading && !error && items.length > 0 && (
        <>
          {/* Список сделок */}
          <div className="flex-1 space-y-1 overflow-y-auto max-h-48">
            <div className="grid grid-cols-[1fr_auto_auto_auto] gap-x-2 text-xs font-medium text-gray-500 pb-1 border-b border-gray-100 dark:border-gray-700">
              <span>Компания</span>
              <span className="text-right">Сумма</span>
              <span className="text-right">Дата</span>
              <span className="text-right">Менеджер</span>
            </div>
            {items.map((item) => {
              // Ближайший платёж
              const nextPayment = item.payments[0] ?? null;
              return (
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
                    {!item.has_contract && (
                      <span className="ml-1 text-[10px] text-gray-400">(без договора)</span>
                    )}
                  </div>
                  <span className="tabular-nums text-right whitespace-nowrap">
                    {nextPayment
                      ? formatCurrency(nextPayment.amount, nextPayment.currency)
                      : formatCurrency(item.deal_amount, item.deal_currency)}
                  </span>
                  <span className="tabular-nums text-right text-gray-500 whitespace-nowrap">
                    {nextPayment ? fmtDate(nextPayment.payment_date) : (item.expected_close_date ? fmtDate(item.expected_close_date) : "—")}
                  </span>
                  <span className="text-right text-gray-500 truncate max-w-[80px]" title={item.owner_name}>
                    {item.owner_name || "—"}
                  </span>
                </div>
              );
            })}
          </div>

          {/* Разбивка по месяцам */}
          {byMonth.length > 0 && (
            <div className="mt-3 pt-2 border-t border-gray-100 dark:border-gray-700">
              <div className="text-xs text-gray-500 mb-1">По месяцам:</div>
              <div className="flex flex-wrap gap-2">
                {byMonth.slice(0, 6).map(([month, buckets]) => {
                  const primaryCur = agg?.primary_currency;
                  const val = primaryCur ? buckets[primaryCur] : Object.values(buckets)[0];
                  if (val == null) return null;
                  const cur = primaryCur ?? Object.keys(buckets)[0];
                  return (
                    <span
                      key={month}
                      className="badge badge-info text-xs tabular-nums"
                      title={month}
                    >
                      {month}: {formatCurrency(val, cur)}
                    </span>
                  );
                })}
              </div>
            </div>
          )}
        </>
      )}

      <div className="mt-3 pt-2 border-t border-gray-100 dark:border-gray-700">
        <a href="/deals" className="text-xs text-primary hover:underline">
          К доске сделок →
        </a>
      </div>
    </div>
  );
}
