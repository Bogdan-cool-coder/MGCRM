"use client";

/**
 * Виджет «Горячие — прогноз» (DEALS 2.0 Ф3).
 *
 * GET /api/analytics/deals/hot
 * Сделки в этапе hot с ожидаемым сроком и суммой.
 * Подсветка просроченных и близких.
 * Сортировка по сроку: просроченные первыми.
 */
import useSWR from "swr";
import Link from "next/link";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import { EmptyState } from "@/components/EmptyState";

interface HotAnalyticsItem {
  deal_id: number;
  deal_title: string;
  company_name: string;
  company_id: number | null;
  deal_amount: number | null;
  deal_currency: string | null;
  owner_name: string;
  owner_user_id: number | null;
  expected_close_date: string | null;
  days_to_close: number | null;
  is_overdue: boolean;
}

interface HotForecastData {
  total_pipeline: number;
  primary_currency: string | null;
  count: number;
  overdue_count: number;
  closing_this_week: number;
  avg_amount: number;
}

interface HotAnalyticsResponse {
  items: HotAnalyticsItem[];
  total_deals: number;
  forecast: HotForecastData;
}

function fmtDate(iso: string | null | undefined): string {
  if (!iso) return "—";
  const d = new Date(iso + "T00:00:00");
  return d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
}

function DaysChip({ days }: { days: number | null }) {
  if (days === null) return <span className="text-gray-400 text-xs">нет даты</span>;
  if (days < 0)
    return (
      <span className="text-xs font-semibold text-danger">
        просрочено {Math.abs(days)} дн.
      </span>
    );
  if (days === 0)
    return <span className="text-xs font-semibold text-danger">сегодня</span>;
  if (days <= 3)
    return <span className="text-xs font-semibold text-warning">{days} дн.</span>;
  if (days <= 7)
    return <span className="text-xs font-medium text-warning">{days} дн.</span>;
  return <span className="text-xs text-gray-500">{days} дн.</span>;
}

export function HotForecastWidget() {
  const { data, isLoading, error } = useSWR<HotAnalyticsResponse>(
    "/analytics/deals/hot",
    fetcher,
    { revalidateOnFocus: false, dedupingInterval: 60_000 },
  );

  const items = data?.items ?? [];
  const fc = data?.forecast;

  return (
    <div className="card p-5 flex flex-col min-h-[220px]">
      {/* Заголовок */}
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-h5 flex items-center gap-2">
          <i className="bi bi-fire text-danger" />
          Горячие — прогноз
        </h3>
        {fc && fc.count > 0 && fc.primary_currency && (
          <span className="text-sm font-semibold text-primary">
            {formatCurrency(fc.total_pipeline, fc.primary_currency)}
          </span>
        )}
      </div>

      {/* Аггрегат-чипы */}
      {fc && fc.count > 0 && (
        <div className="flex flex-wrap gap-2 mb-3">
          <span className="badge badge-info text-xs">
            {fc.count} сделок
          </span>
          {fc.overdue_count > 0 && (
            <span className="badge badge-danger text-xs">
              {fc.overdue_count} просрочено
            </span>
          )}
          {fc.closing_this_week > 0 && (
            <span className="badge badge-warning text-xs">
              {fc.closing_this_week} закрываются на неделе
            </span>
          )}
        </div>
      )}

      {isLoading && (
        <div className="flex-1 animate-pulse space-y-2">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-9 bg-gray-100 dark:bg-gray-700 rounded" />
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
          <EmptyState icon="bi-fire" title="Нет горячих сделок" />
        </div>
      )}

      {!isLoading && !error && items.length > 0 && (
        <div className="flex-1 space-y-1 overflow-y-auto max-h-52">
          <div className="grid grid-cols-[1fr_auto_auto_auto] gap-x-2 text-xs font-medium text-gray-500 pb-1 border-b border-gray-100 dark:border-gray-700">
            <span>Компания</span>
            <span className="text-right">Сумма</span>
            <span className="text-right">Срок</span>
            <span className="text-right">Осталось</span>
          </div>
          {items.map((item) => (
            <div
              key={item.deal_id}
              className={`grid grid-cols-[1fr_auto_auto_auto] gap-x-2 text-sm py-1.5 border-b border-gray-100 dark:border-gray-700 last:border-0 ${
                item.is_overdue ? "bg-danger/5 dark:bg-danger/10 -mx-1 px-1 rounded" : ""
              }`}
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
              <span className="tabular-nums text-right whitespace-nowrap text-gray-700 dark:text-gray-200">
                {formatCurrency(item.deal_amount, item.deal_currency)}
              </span>
              <span className="text-right text-gray-500 whitespace-nowrap">
                {fmtDate(item.expected_close_date)}
              </span>
              <span className="text-right whitespace-nowrap">
                <DaysChip days={item.days_to_close} />
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
