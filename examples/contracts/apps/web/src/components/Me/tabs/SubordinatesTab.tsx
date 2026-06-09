"use client";

import Link from "next/link";
import useSWR from "swr";
import { fetcher } from "@/lib/api";
import { formatCurrency } from "@/lib/format";
import { EmptyState } from "@/components/EmptyState";
import { MkStatusBadge } from "../mk/MkStatusBadge";
import type { MeSubordinate } from "@/lib/types";

interface Props {
  period: string;
}

function statusBadge(pct: number) {
  if (pct >= 80)
    return <span className="badge bg-success/10 text-success font-medium">На треке</span>;
  if (pct >= 50)
    return <span className="badge bg-warning/10 text-warning font-medium">Риск</span>;
  return <span className="badge bg-danger/10 text-danger font-medium">Тревога</span>;
}

export function SubordinatesTab({ period }: Props) {
  const { data: subordinates, isLoading, error } = useSWR<MeSubordinate[]>(
    `/me/subordinates?period=${period}`,
    fetcher,
  );

  return (
    <div className="space-y-4">
      {/* Шапка */}
      <div className="flex items-center gap-3">
        <span className="text-[11px] uppercase tracking-wider font-semibold text-gray-400 dark:text-gray-500">
          Команда
        </span>
        {subordinates && (
          <span className="badge bg-gray-100 dark:bg-gray-700 text-gray-500 dark:text-gray-400">
            {subordinates.length} менеджеров
          </span>
        )}
      </div>

      {isLoading && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-5 space-y-2 animate-pulse">
          {[1, 2, 3].map((i) => (
            <div key={i} className="h-12 bg-gray-100 dark:bg-gray-700 rounded-xl" />
          ))}
        </div>
      )}

      {!isLoading && error && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 p-8">
          <EmptyState icon="bi-exclamation-circle" title="Не удалось загрузить данные" />
        </div>
      )}

      {!isLoading && !error && subordinates && (
        <div className="rounded-2xl bg-white dark:bg-gray-800/60 border border-gray-200 dark:border-white/10 shadow-elev-1 overflow-hidden">
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead className="bg-gray-50 dark:bg-gray-700/50 border-b border-gray-100 dark:border-gray-700">
                <tr>
                  <th className="text-left px-5 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">ФИО</th>
                  <th className="text-left px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Отдел</th>
                  <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">План</th>
                  <th className="text-right px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Факт</th>
                  <th className="px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">%</th>
                  <th className="text-center px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wider hidden sm:table-cell">FTM</th>
                  <th className="px-4 py-3 text-[11px] font-semibold text-gray-500 uppercase tracking-wider">Статус</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-gray-50 dark:divide-gray-700/50">
                {subordinates.map((s, idx) => (
                  <tr
                    key={s.id}
                    className="blur-fade hover:bg-gray-50 dark:hover:bg-gray-700/30 cursor-pointer transition-colors"
                    style={{ "--blur-fade-duration": `${0.2 + idx * 0.04}s` } as React.CSSProperties}
                    onClick={() => { window.location.href = `/me?user_id=${s.id}`; }}
                  >
                    <td className="px-5 py-3.5">
                      <Link
                        href={`/me?user_id=${s.id}`}
                        className="text-primary hover:underline font-semibold"
                        onClick={(e) => e.stopPropagation()}
                      >
                        {s.full_name}
                      </Link>
                    </td>
                    <td className="px-4 py-3.5 text-gray-500 text-sm hidden md:table-cell">
                      {s.department_name ?? "—"}
                    </td>
                    <td className="px-4 py-3.5 text-right tabular-nums text-sm text-gray-500">
                      {formatCurrency(s.personal_income_plan, s.personal_income_currency)}
                    </td>
                    <td className="px-4 py-3.5 text-right tabular-nums text-sm font-semibold text-gray-900 dark:text-gray-100">
                      {formatCurrency(s.personal_income_fact, s.personal_income_currency)}
                    </td>
                    <td className="px-4 py-3.5">
                      <MkStatusBadge pct={s.personal_income_pct} />
                    </td>
                    <td className="px-4 py-3.5 text-center text-sm text-gray-600 dark:text-gray-400 hidden sm:table-cell">
                      {s.ftm_count_fact} / {s.ftm_count_plan}
                    </td>
                    <td className="px-4 py-3.5">
                      {statusBadge(s.personal_income_pct)}
                    </td>
                  </tr>
                ))}
                {subordinates.length === 0 && (
                  <tr>
                    <td colSpan={7} className="px-5 py-10">
                      <EmptyState icon="bi-people" title="Нет подопечных" />
                    </td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </div>
      )}
    </div>
  );
}
