"use client";

import { useState, useMemo } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { CohortMatrix } from "@/components/CohortMatrix";
import { RetentionLineChart } from "@/components/RetentionLineChart";
import { EmptyState } from "@/components/EmptyState";
import { BlurFade } from "@/components/magicui/BlurFade";
import { Combobox } from "@/components/ui/Combobox";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import type {
  CohortAnalyticsResponse,
  CohortMembersResponse,
  Platform,
} from "@/lib/types";

// Skeleton-строки для загрузки
function SkeletonRow({ cols }: { cols: number }) {
  return (
    <tr className="border-b border-gray-100 dark:border-gray-800">
      {Array.from({ length: cols }).map((_, i) => (
        <td key={i} className="px-3 py-2">
          <div className="animate-pulse h-4 bg-gray-200 dark:bg-gray-700 rounded" />
        </td>
      ))}
    </tr>
  );
}

// Модалка с участниками когорты
function CohortMembersModal({
  cohortMonth,
  onClose,
}: {
  cohortMonth: string;
  onClose: () => void;
}) {
  const { data, isLoading, error } = useSWR<CohortMembersResponse>(
    `/api/analytics/cohorts/${cohortMonth}/members`,
    fetcher,
    { revalidateOnFocus: false }
  );

  // Вычисляем LTV: fee_actual * (churn_date - cohort_start_date) в месяцах
  function calcLtv(
    feeActual: number | null,
    cohortStart: string | null,
    churnDate: string | null
  ): number | null {
    if (!feeActual || !cohortStart) return null;
    const end = churnDate ? new Date(churnDate) : new Date();
    const start = new Date(cohortStart);
    const diffMs = end.getTime() - start.getTime();
    const months = Math.max(0, Math.round(diffMs / (1000 * 60 * 60 * 24 * 30)));
    return feeActual * months;
  }

  return (
    <Modal
      open={true}
      onClose={onClose}
      title={
        data
          ? `Когорта ${cohortMonth} — ${data.count} участников`
          : `Когорта ${cohortMonth}`
      }
      width="xl"
    >
      {error && (
        <div className="rounded-md bg-danger/10 border border-danger/20 px-4 py-3 text-danger text-sm">
          Не удалось загрузить участников когорты
        </div>
      )}
      {isLoading && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 dark:border-gray-700">
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Контрагент</th>
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Дата старта</th>
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Текущий этап</th>
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Дата ухода</th>
                <th className="text-right px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">LTV</th>
              </tr>
            </thead>
            <tbody>
              {[1, 2, 3, 4, 5].map((i) => <SkeletonRow key={i} cols={5} />)}
            </tbody>
          </table>
        </div>
      )}
      {data && !isLoading && (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 dark:border-gray-700">
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Контрагент</th>
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Дата старта</th>
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Текущий этап</th>
                <th className="text-left px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">Дата ухода</th>
                <th className="text-right px-3 py-2 text-gray-600 dark:text-gray-400 font-semibold">LTV</th>
              </tr>
            </thead>
            <tbody>
              {data.members.length === 0 && (
                <tr>
                  <td colSpan={5} className="px-3 py-6 text-center text-gray-400 dark:text-gray-500">
                    Нет участников
                  </td>
                </tr>
              )}
              {data.members.map((member) => {
                const ltv = calcLtv(
                  member.fee_actual,
                  member.cohort_start_date,
                  member.churn_date
                );
                return (
                  <tr
                    key={member.subscription_id}
                    className={
                      "border-b border-gray-100 dark:border-gray-800 last:border-0 " +
                      (member.is_churned
                        ? "bg-red-50 dark:bg-red-950/20"
                        : "")
                    }
                  >
                    <td className="px-3 py-2">
                      <span className="font-medium text-primary dark:text-primary-light">
                        {member.counterparty_name}
                      </span>
                      {!member.is_active && (
                        <span className="ml-2 badge bg-danger/10 text-danger text-xs">
                          Неактивна
                        </span>
                      )}
                    </td>
                    <td className="px-3 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                      {member.cohort_start_date
                        ? new Date(member.cohort_start_date).toLocaleDateString("ru-RU")
                        : "—"}
                    </td>
                    <td className="px-3 py-2 whitespace-nowrap">
                      {member.current_stage_code ? (
                        <span className="badge bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300 text-xs font-mono">
                          {member.current_stage_code}
                        </span>
                      ) : "—"}
                    </td>
                    <td className="px-3 py-2 text-gray-600 dark:text-gray-400 whitespace-nowrap">
                      {member.churn_date
                        ? new Date(member.churn_date).toLocaleDateString("ru-RU")
                        : "—"}
                    </td>
                    <td className="px-3 py-2 text-right whitespace-nowrap font-medium">
                      {ltv !== null ? formatCurrency(ltv, null) : "—"}
                    </td>
                  </tr>
                );
              })}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  );
}

export default function CohortAnalyticsPage() {
  // Фильтры
  const [periods, setPeriods] = useState<number>(12);
  const [productCode, setProductCode] = useState<string>("");
  const [countryCode, setCountryCode] = useState<string>("");

  // Drill-down: выбранная когорта для модалки
  const [selectedCohort, setSelectedCohort] = useState<string | null>(null);

  // SWR для данных платформ (product_code список)
  const { data: platforms } = useSWR<Platform[]>("/api/platforms", fetcher, {
    revalidateOnFocus: false,
    dedupingInterval: 300_000,
  });

  // SWR для когортных данных
  const swrKey = useMemo(() => {
    const params = new URLSearchParams({ periods: String(periods) });
    if (productCode) params.set("product_code", productCode);
    if (countryCode) params.set("country_code", countryCode);
    return `/api/analytics/cohorts?${params.toString()}`;
  }, [periods, productCode, countryCode]);

  const { data, isLoading, error, mutate } = useSWR<CohortAnalyticsResponse>(
    swrKey,
    fetcher,
    { revalidateOnFocus: false, dedupingInterval: 120_000 }
  );

  // Уникальные страны — берём из данных когорт, если бэк возвращает country_code;
  // иначе отдаём пустой список (фильтр будет скрыт или не заполнен)
  const uniqueCountries = useMemo(() => {
    if (!data?.cohorts) return [];
    const seen = new Set<string>();
    const result: { value: string; label: string }[] = [];
    for (const c of data.cohorts) {
      const cc = (c as unknown as Record<string, unknown>).country_code;
      if (typeof cc === "string" && cc && !seen.has(cc)) {
        seen.add(cc);
        result.push({ value: cc, label: cc.toUpperCase() });
      }
    }
    return result;
  }, [data]);

  // LTV таблица: сортировка по когорте desc
  const sortedLtvRows = useMemo(() => {
    if (!data?.cohorts) return [];
    return [...data.cohorts].sort((a, b) =>
      b.cohort_month.localeCompare(a.cohort_month)
    );
  }, [data]);

  const churnPct = data ? (data.monthly_churn_rate * 100).toFixed(1) : null;

  return (
    <div className="flex flex-col min-h-0">
      <PageHeader
        title="Когортная аналитика"
        description="Retention-матрица клиентов CS-реестра по месяцам активации"
        actions={
          <div className="flex items-center gap-2">
            <button
              onClick={() => void mutate()}
              className="btn-ghost"
              title="Обновить данные"
              type="button"
            >
              <i className="bi bi-arrow-clockwise" />
            </button>
            <form
              method="POST"
              action={`/api/analytics/cohorts/export?${new URLSearchParams(
                Object.fromEntries([
                  ["periods", String(periods)],
                  ...(productCode ? [["product_code", productCode]] : []),
                  ...(countryCode ? [["country_code", countryCode]] : []),
                ])
              ).toString()}`}
              target="_blank"
            >
              <button type="submit" className="btn-secondary">
                <i className="bi bi-download mr-1" />
                Экспорт .xlsx
              </button>
            </form>
          </div>
        }
      />

      <div className="p-6 space-y-6">
        {/* Фильтры v2 */}
        <div className="card rounded-2xl shadow-elev-1 p-4 flex flex-wrap gap-3 items-end">
          <div className="min-w-[160px]">
            <Combobox
              label="Глубина"
              value={String(periods)}
              onChange={(v) => v && setPeriods(Number(v))}
              options={[
                { value: "3", label: "3 месяца" },
                { value: "6", label: "6 месяцев" },
                { value: "12", label: "12 месяцев" },
                { value: "24", label: "24 месяца" },
              ]}
            />
          </div>

          <div className="min-w-[200px]">
            <Combobox
              label="Продукт"
              value={productCode || null}
              onChange={(v) => setProductCode(v ?? "")}
              options={(platforms ?? []).map((p) => ({ value: p.code, label: p.name }))}
              placeholder="Все продукты"
              clearable
              isLoading={platforms === undefined}
            />
          </div>

          <div className="min-w-[180px]">
            <Combobox
              label="Страна"
              value={countryCode || null}
              onChange={(v) => setCountryCode(v ?? "")}
              options={uniqueCountries}
              placeholder="Все страны"
              clearable
            />
          </div>

          {/* Quarterly — disabled, скоро */}
          <div title="Квартальные когорты — скоро">
            <label className="label mb-1 block text-gray-300 dark:text-gray-600">Тип</label>
            <div className="input opacity-50 cursor-not-allowed text-sm text-gray-400 flex items-center justify-between select-none">
              <span>Ежемесячный</span>
              <span className="badge badge-neutral text-[10px] ml-2">Скоро</span>
            </div>
          </div>
        </div>

        {/* Ошибка загрузки */}
        {error && !isLoading && (
          <div className="rounded-md bg-danger/10 border border-danger/20 px-4 py-3 text-danger text-sm">
            Не удалось загрузить данные когортной аналитики
          </div>
        )}

        {/* KPI-карточки с blur-fade stagger */}
        <div className="grid grid-cols-2 lg:grid-cols-4 gap-4">
          {/* MRR */}
          <BlurFade delay={0} duration={0.3}>
            <div className="card rounded-2xl p-4 shadow-elev-1 lift">
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <div className="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wide mb-1">
                    Текущий MRR
                  </div>
                  <div className="text-xl font-bold text-primary dark:text-gray-100 truncate">
                    {isLoading ? (
                      <span className="inline-block animate-pulse h-6 w-28 bg-gray-100 dark:bg-gray-700 rounded" />
                    ) : data ? formatCurrency(data.current_mrr, null) : "—"}
                  </div>
                  <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                    Monthly Recurring Revenue
                  </div>
                </div>
                <i className="bi bi-cash-stack text-2xl text-primary shrink-0 ml-2 opacity-70" />
              </div>
            </div>
          </BlurFade>

          {/* Projected LTV */}
          <BlurFade delay={0.06} duration={0.3}>
            <div className="card rounded-2xl p-4 shadow-elev-1 lift">
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <div className="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wide mb-1">
                    Projected LTV
                  </div>
                  <div className="text-xl font-bold text-success truncate">
                    {isLoading ? (
                      <span className="inline-block animate-pulse h-6 w-28 bg-gray-100 dark:bg-gray-700 rounded" />
                    ) : data ? (
                      data.monthly_churn_rate === 0 ? "—" : formatCurrency(data.projected_ltv, null)
                    ) : "—"}
                  </div>
                  <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                    MRR / Churn Rate
                  </div>
                </div>
                <i className="bi bi-graph-up-arrow text-2xl text-success shrink-0 ml-2 opacity-70" />
              </div>
            </div>
          </BlurFade>

          {/* Total Avg LTV */}
          <BlurFade delay={0.12} duration={0.3}>
            <div className="card rounded-2xl p-4 shadow-elev-1 lift">
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <div className="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wide mb-1">
                    Средний LTV / клиент
                  </div>
                  <div className="text-xl font-bold text-info truncate">
                    {isLoading ? (
                      <span className="inline-block animate-pulse h-6 w-28 bg-gray-100 dark:bg-gray-700 rounded" />
                    ) : data ? formatCurrency(data.total_avg_ltv, null) : "—"}
                  </div>
                  <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                    По всем когортам
                  </div>
                </div>
                <i className="bi bi-people text-2xl text-info shrink-0 ml-2 opacity-70" />
              </div>
            </div>
          </BlurFade>

          {/* Monthly Churn Rate */}
          <BlurFade delay={0.18} duration={0.3}>
            <div className="card rounded-2xl p-4 shadow-elev-1 lift">
              <div className="flex items-start justify-between">
                <div className="flex-1 min-w-0">
                  <div className="text-xs text-gray-500 dark:text-gray-400 font-semibold uppercase tracking-wide mb-1">
                    Месячный Churn
                  </div>
                  <div className="flex items-baseline gap-2">
                    <span className="text-xl font-bold text-danger">
                      {isLoading ? (
                        <span className="inline-block animate-pulse h-6 w-16 bg-gray-100 dark:bg-gray-700 rounded" />
                      ) : churnPct !== null ? `${churnPct}%` : "—"}
                    </span>
                    {data && !isLoading && (
                      <>
                        {data.monthly_churn_rate > 0.05 && (
                          <span className="badge badge-danger text-xs">Высокий</span>
                        )}
                        {data.monthly_churn_rate > 0 && data.monthly_churn_rate < 0.02 && (
                          <span className="badge badge-success text-xs">Низкий</span>
                        )}
                      </>
                    )}
                  </div>
                  <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
                    Средний по всем когортам
                  </div>
                </div>
                <i className="bi bi-arrow-down-circle text-2xl text-danger shrink-0 ml-2 opacity-70" />
              </div>
            </div>
          </BlurFade>
        </div>

        {/* Cohort Matrix */}
        <BlurFade delay={0.1} duration={0.35}>
          <div>
            <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3 uppercase tracking-wide">
              Матрица retention
            </h2>
            {isLoading && (
              <div className="card rounded-2xl overflow-x-auto animate-pulse shadow-elev-1">
                <table className="w-full text-sm border-collapse">
                  <thead>
                    <tr>
                      {[...Array(Math.min(periods + 2, 8))].map((_, i) => (
                        <th key={i} className="px-3 py-2.5 bg-gray-50 dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700">
                          <div className="h-3 bg-gray-200 dark:bg-gray-700 rounded" />
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {[1, 2, 3, 4, 5].map((i) => <SkeletonRow key={i} cols={Math.min(periods + 2, 8)} />)}
                  </tbody>
                </table>
              </div>
            )}
            {!isLoading && data && (
              <CohortMatrix
                cohorts={data.cohorts}
                retentionPct={data.retention_pct}
                matrix={data.matrix}
                maxOffset={periods}
                onCohortClick={(month) => setSelectedCohort(month)}
              />
            )}
          </div>
        </BlurFade>

        {/* Retention Line Chart */}
        {!isLoading && data && data.cohorts.length > 0 && (
          <BlurFade delay={0.15} duration={0.35}>
            <div>
              <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3 uppercase tracking-wide">
                Динамика retention по когортам
              </h2>
              <RetentionLineChart
                retentionPct={data.retention_pct}
                maxOffset={periods}
              />
            </div>
          </BlurFade>
        )}

        {/* LTV таблица по когортам */}
        {!isLoading && data && sortedLtvRows.length > 0 && (
          <BlurFade delay={0.2} duration={0.35}>
            <div>
              <h2 className="text-xs font-semibold text-gray-500 dark:text-gray-400 mb-3 uppercase tracking-wide">
                LTV по когортам
              </h2>
              <div className="card rounded-2xl overflow-hidden shadow-elev-1">
                <table className="w-full text-sm">
                  <thead>
                    <tr className="border-b border-gray-200 dark:border-gray-700">
                      <th className="text-left px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900">
                        Когорта
                      </th>
                      <th className="text-center px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900">
                        Размер
                      </th>
                      <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900">
                        Avg LTV
                      </th>
                      <th className="text-right px-4 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 bg-gray-50 dark:bg-gray-900">
                        Projected LTV
                      </th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                    {sortedLtvRows.map((row) => (
                      <tr
                        key={row.cohort_month}
                        className="hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06] transition-colors cursor-pointer group"
                        onClick={() => setSelectedCohort(row.cohort_month)}
                      >
                        <td className="px-4 py-2.5 font-semibold text-primary dark:text-primary-light whitespace-nowrap">
                          <span className="inline-flex items-center gap-1.5">
                            {row.cohort_month}
                            <i className="bi bi-chevron-right text-[9px] opacity-0 group-hover:opacity-40 transition-opacity" />
                          </span>
                        </td>
                        <td className="px-4 py-2.5 text-center tabular-nums text-gray-600 dark:text-gray-400">
                          {row.initial_count}
                        </td>
                        <td className="px-4 py-2.5 text-right tabular-nums font-medium text-gray-800 dark:text-gray-200">
                          {formatCurrency(row.avg_ltv, null)}
                        </td>
                        <td className="px-4 py-2.5 text-right text-gray-400 dark:text-gray-600">—</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </BlurFade>
        )}

        {/* Пустое состояние */}
        {!isLoading && !error && data && data.cohorts.length === 0 && (
          <BlurFade delay={0} duration={0.3}>
            <div className="card rounded-2xl shadow-elev-1">
              <EmptyState
                icon="bi-grid-3x3"
                title="Нет данных для выбранных фильтров"
                description="Попробуйте изменить глубину, продукт или страну"
              />
            </div>
          </BlurFade>
        )}
      </div>

      {/* Drill-down Modal */}
      {selectedCohort && (
        <CohortMembersModal
          cohortMonth={selectedCohort}
          onClose={() => setSelectedCohort(null)}
        />
      )}
    </div>
  );
}
