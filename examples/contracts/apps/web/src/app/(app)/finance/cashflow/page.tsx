"use client";

import { Fragment, useState, useMemo, useEffect } from "react";
import Link from "next/link";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { EmptyState } from "@/components/EmptyState";
import { formatAmount } from "@/components/Finance/MoneyCell";
import { fetcher } from "@/lib/api";
import { periodToRange } from "@/lib/finance";
import type { FinCashflowReport, FinCashflowActivity, FinLegalEntity } from "@/lib/types";

const FINANCE_ROLES = ["accountant", "cfo", "director", "admin"] as const;

const ACTIVITY_LABELS: Record<FinCashflowActivity, string> = {
  operating: "Операционная деятельность",
  investing: "Инвестиционная деятельность",
  financing: "Финансовая деятельность",
};

const ACTIVITY_ORDER: FinCashflowActivity[] = ["operating", "investing", "financing"];

// Skeleton columns: статья, приток, отток, нетто
const SKELETON_COLS = ["44%", "18%", "18%", "18%"];

interface CashflowItem {
  category_id: number;
  category_name: string;
  inflow: number;
  outflow: number;
  net: number;
}

interface CashflowGroup {
  activity: FinCashflowActivity;
  items: CashflowItem[];
  total_inflow: number;
  total_outflow: number;
  total_net: number;
}

function groupByActivity(report: FinCashflowReport): CashflowGroup[] {
  const byAct = new Map<FinCashflowActivity, CashflowItem[]>();
  for (const r of report.rows) {
    const inflow = r.net_func > 0 ? r.net_func : 0;
    const outflow = r.net_func < 0 ? -r.net_func : 0;
    const item: CashflowItem = {
      category_id: r.category_id,
      category_name: r.category_name,
      inflow,
      outflow,
      net: r.net_func,
    };
    const list = byAct.get(r.activity) ?? [];
    list.push(item);
    byAct.set(r.activity, list);
  }
  return ACTIVITY_ORDER.filter((a) => byAct.has(a)).map((activity) => {
    const items = byAct.get(activity) ?? [];
    return {
      activity,
      items,
      total_inflow: items.reduce((a, i) => a + i.inflow, 0),
      total_outflow: items.reduce((a, i) => a + i.outflow, 0),
      total_net: items.reduce((a, i) => a + i.net, 0),
    };
  });
}

function formatPeriod(period: string): string {
  const [y, m] = period.split("-");
  const date = new Date(Number(y), Number(m) - 1, 1);
  return date.toLocaleString("ru-RU", { month: "long", year: "numeric" });
}

function getPeriodOptions(): { value: string; label: string }[] {
  const now = new Date();
  const opts: { value: string; label: string }[] = [];
  for (let i = 5; i >= -1; i--) {
    const d = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const val = `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, "0")}`;
    opts.push({ value: val, label: formatPeriod(val) });
  }
  return opts;
}

function currentPeriod(): string {
  const now = new Date();
  return `${now.getFullYear()}-${String(now.getMonth() + 1).padStart(2, "0")}`;
}

export default function CashflowPage() {
  const [period, setPeriod] = useState<string>(currentPeriod);
  const [entityId, setEntityId] = useState<string>("");
  const [expandedGroups, setExpandedGroups] = useState<Set<string>>(
    new Set(["operating", "investing", "financing"])
  );

  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  useEffect(() => {
    if (!entityId && entities && entities.length > 0) setEntityId(String(entities[0].id));
  }, [entities, entityId]);

  const { date_from, date_to } = periodToRange(period);
  const swrKey = entityId
    ? `/api/finance/reports/cashflow-simple?legal_entity_id=${entityId}&date_from=${date_from}&date_to=${date_to}`
    : null;
  const { data: report, isLoading, error } = useSWR<FinCashflowReport>(swrKey, fetcher);

  const groups = useMemo(() => (report ? groupByActivity(report) : []), [report]);

  function toggleGroup(activity: string) {
    setExpandedGroups((prev) => {
      const next = new Set(prev);
      if (next.has(activity)) next.delete(activity);
      else next.add(activity);
      return next;
    });
  }

  const periodOpts = getPeriodOptions();

  return (
    <RoleGate allowed={[...FINANCE_ROLES]}>
      <div className="flex flex-col h-full">
        <PageHeader
          title="ДДС — движение денег"
          description="Простой отчёт о движении денежных средств (прямой метод)"
        />

        <div className="p-6 flex flex-col gap-4">
          {/* Фильтр-бар */}
          <div className="card rounded-2xl shadow-elev-1 p-4">
            <div className="flex flex-wrap items-center gap-2">
              <select
                className="input text-sm"
                value={period}
                onChange={(e) => setPeriod(e.target.value)}
              >
                {periodOpts.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>
              <select
                className="input text-sm"
                value={entityId}
                onChange={(e) => setEntityId(e.target.value)}
              >
                <option value="">Все юрлица</option>
                {entities?.map((e) => (
                  <option key={e.id} value={e.id}>{e.name}</option>
                ))}
              </select>
            </div>
          </div>

          {/* Таблица — fin-table v2 */}
          <div className="card rounded-2xl overflow-hidden shadow-elev-1">
            {/* Sticky thead */}
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="sticky top-0 z-10 bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 fin-thead-shadow">
                  <tr>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-left px-4 py-2.5">Статья</th>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-right px-4 py-2.5 w-32">Приток</th>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-right px-4 py-2.5 w-32">Отток</th>
                    <th className="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 text-right px-4 py-2.5 w-32">Нетто</th>
                  </tr>
                </thead>
                <tbody>
                  {isLoading ? (
                    <FinTableSkeleton rows={8} cols={SKELETON_COLS} />
                  ) : error ? (
                    <tr>
                      <td colSpan={4}>
                        <div className="px-4 py-6 text-center text-sm text-danger">
                          <i className="bi bi-exclamation-triangle mr-2" />
                          Не удалось загрузить отчёт
                        </div>
                      </td>
                    </tr>
                  ) : !report || groups.length === 0 ? (
                    <tr>
                      <td colSpan={4}>
                        <EmptyState
                          icon="bi-bar-chart"
                          title="Нет данных за выбранный период"
                          description="Попробуй изменить период или юрлицо"
                        />
                      </td>
                    </tr>
                  ) : (
                    <>
                      {groups.map((group) => {
                        const expanded = expandedGroups.has(group.activity);
                        return (
                          <Fragment key={group.activity}>
                            {/* Группа — кликабельная строка */}
                            <tr
                              className="border-b border-gray-200 dark:border-gray-700 cursor-pointer hover:bg-gray-50 dark:hover:bg-gray-800/60 transition-colors"
                              onClick={() => toggleGroup(group.activity)}
                            >
                              <td className="px-4 py-3">
                                <span className="font-semibold text-sm text-gray-800 dark:text-gray-200 flex items-center gap-2">
                                  <i className={`bi ${expanded ? "bi-chevron-down" : "bi-chevron-right"} text-gray-400 text-xs transition-transform`} />
                                  {ACTIVITY_LABELS[group.activity]}
                                </span>
                              </td>
                              <td className="px-4 py-3 text-right tabular-nums text-sm font-semibold text-success">
                                {group.total_inflow > 0 ? formatAmount(group.total_inflow) : "—"}
                              </td>
                              <td className="px-4 py-3 text-right tabular-nums text-sm font-semibold text-danger">
                                {group.total_outflow > 0 ? formatAmount(group.total_outflow) : "—"}
                              </td>
                              <td className={`px-4 py-3 text-right tabular-nums text-sm font-bold ${group.total_net >= 0 ? "text-gray-800 dark:text-gray-100" : "text-danger"}`}>
                                {group.total_net >= 0 ? "+" : "−"}{formatAmount(Math.abs(group.total_net))}
                              </td>
                            </tr>

                            {/* Строки статей */}
                            {expanded && group.items.map((item) => (
                              <tr
                                key={`item-${item.category_id}`}
                                className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50/50 dark:hover:bg-gray-800/40 transition-colors"
                              >
                                <td className="px-4 py-2.5 pl-10">
                                  <Link
                                    href={`/finance/operations?category=${item.category_id}&period=${period}`}
                                    className="text-sm text-gray-600 dark:text-gray-400 hover:text-primary dark:hover:text-blue-400 flex items-center gap-1 transition-colors"
                                    onClick={(e) => e.stopPropagation()}
                                  >
                                    {item.category_name}
                                    <i className="bi bi-arrow-up-right-square text-xs text-gray-300 dark:text-gray-600" />
                                  </Link>
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-sm text-success">
                                  {item.inflow > 0 ? formatAmount(item.inflow) : "—"}
                                </td>
                                <td className="px-4 py-2.5 text-right tabular-nums text-sm text-danger">
                                  {item.outflow > 0 ? formatAmount(item.outflow) : "—"}
                                </td>
                                <td className={`px-4 py-2.5 text-right tabular-nums text-sm ${item.net >= 0 ? "text-gray-700 dark:text-gray-300" : "text-danger"}`}>
                                  {item.net >= 0 ? "+" : "−"}{formatAmount(Math.abs(item.net))}
                                </td>
                              </tr>
                            ))}
                          </Fragment>
                        );
                      })}
                    </>
                  )}
                </tbody>

                {/* Total-bar */}
                {report && groups.length > 0 && (
                  <tfoot>
                    <tr className="border-t-2 border-gray-200 dark:border-gray-600 bg-gray-50 dark:bg-gray-800/60">
                      <td className="px-4 py-3 font-bold text-sm text-gray-800 dark:text-gray-200">Итого</td>
                      <td className="px-4 py-3 text-right tabular-nums text-sm font-bold text-success">
                        {formatAmount(report.inflow)}
                      </td>
                      <td className="px-4 py-3 text-right tabular-nums text-sm font-bold text-danger">
                        {formatAmount(Math.abs(report.outflow))}
                      </td>
                      <td className={`px-4 py-3 text-right tabular-nums text-sm font-bold ${report.net >= 0 ? "text-gray-800 dark:text-gray-100" : "text-danger"}`}>
                        {report.net >= 0 ? "+" : "−"}{formatAmount(Math.abs(report.net))}
                      </td>
                    </tr>
                  </tfoot>
                )}
              </table>
            </div>
          </div>
        </div>
      </div>
    </RoleGate>
  );
}
