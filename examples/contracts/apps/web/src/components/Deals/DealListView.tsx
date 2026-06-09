"use client";

import { useMemo, useRef, useState, useEffect, useCallback } from "react";
import { useRouter } from "next/navigation";
import { EmptyState } from "@/components/EmptyState";
import { formatCurrency } from "@/lib/format";
import type { DealListRow, NextTask, User } from "@/lib/types";

interface Props {
  /** undefined = состояние загрузки (skeleton), [] = пусто, T[] = данные */
  rows: DealListRow[] | undefined;
  bulkMode: boolean;
  selectedIds: number[];
  onSelect: (id: number, checked: boolean) => void;
  users?: User[];
}

/** Количество skeleton-строк */
const SKELETON_COUNT = 8;
const TD = "px-3 py-2";

function fmtDate(dt: string): string {
  return new Date(dt).toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
}

function TaskCell({ task }: { task: NextTask | null }) {
  if (!task) return <span className="text-gray-400 text-xs">—</span>;

  const overdueCls = task.is_overdue ? "text-danger" : "text-gray-600 dark:text-gray-400";
  const ICONS: Record<string, string> = {
    call: "bi-telephone",
    meeting: "bi-calendar-event",
    task: "bi-check2-square",
    note: "bi-sticky",
  };
  const icon = ICONS[task.kind] ?? "bi-check2-square";
  const dueFmt = task.due_at
    ? new Date(task.due_at).toLocaleDateString("ru-RU", { day: "numeric", month: "short" })
    : "без срока";

  return (
    <div className={`flex items-center gap-1 text-xs ${overdueCls}`}>
      <i className={`bi ${icon} shrink-0`} />
      <span className="truncate max-w-[120px]">{task.title}</span>
      <span className="shrink-0">{dueFmt}</span>
    </div>
  );
}

/** Одна skeleton-строка */
function SkeletonRow({ hasBulk }: { hasBulk: boolean }) {
  const pulse = "animate-pulse h-3.5 rounded bg-gray-100 dark:bg-gray-800";
  return (
    <tr className="border-b border-gray-100 dark:border-gray-800">
      {hasBulk && <td className={TD}><div className={`${pulse} w-4`} /></td>}
      <td className={TD}><div className={`${pulse} w-32`} /></td>
      <td className={TD}><div className={`${pulse} w-24`} /></td>
      <td className={TD}><div className={`${pulse} w-20`} /></td>
      <td className={TD}><div className={`${pulse} w-16 ml-auto`} /></td>
      <td className={TD}><div className={`${pulse} w-16`} /></td>
      <td className={TD}><div className={`${pulse} w-14`} /></td>
      <td className={TD}><div className={`${pulse} w-28`} /></td>
      <td className={TD}><div className={`${pulse} w-10`} /></td>
    </tr>
  );
}

export function DealListView({ rows, bulkMode, selectedIds, onSelect, users }: Props) {
  const router = useRouter();

  // Scroll-detect для sticky thead shadow — паттерн из DataTable
  const wrapRef = useRef<HTMLDivElement>(null);
  const [scrolled, setScrolled] = useState(false);
  const handleScroll = useCallback(() => {
    if (!wrapRef.current) return;
    setScrolled(wrapRef.current.scrollTop > 0);
  }, []);
  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    el.addEventListener("scroll", handleScroll, { passive: true });
    return () => el.removeEventListener("scroll", handleScroll);
  }, [handleScroll]);

  const userMap = useMemo(
    () => new Map((users ?? []).map((u) => [u.id, u.full_name])),
    [users]
  );

  const isLoading = rows === undefined;
  const isEmpty = !isLoading && rows.length === 0;
  const hasData = !isLoading && rows.length > 0;

  // Sticky thead box-shadow активируется после скролла
  const theadCls = [
    "sticky top-0 z-10",
    "bg-white dark:bg-gray-900",
    "border-b border-gray-200 dark:border-gray-700",
    scrolled
      ? "shadow-[0_1px_6px_rgba(0,0,0,0.08)] dark:shadow-[0_1px_6px_rgba(0,0,0,0.3)]"
      : "",
  ].join(" ");

  return (
    <div
      className={[
        "card rounded-2xl overflow-hidden shadow-elev-1 mx-0",
        scrolled ? "scrolled" : "",
      ].join(" ")}
    >
      <div
        ref={wrapRef}
        className="overflow-x-auto overflow-y-auto"
        style={{ maxHeight: "calc(100vh - 220px)" }}
        onScroll={handleScroll}
      >
        <table className="w-full text-sm" aria-label="Список сделок">
          <thead className={theadCls}>
            <tr className="text-gray-500 dark:text-gray-400 text-xs font-medium uppercase tracking-wide">
              {bulkMode && (
                <th className="w-8 px-3 py-2 text-left">
                  <span className="sr-only">Выбор</span>
                </th>
              )}
              <th className="px-3 py-2 text-left">Компания</th>
              <th className="px-3 py-2 text-left">Этап</th>
              <th className="px-3 py-2 text-left">Ответственный</th>
              <th className="px-3 py-2 text-right">Сумма</th>
              <th className="px-3 py-2 text-left">Продукт</th>
              <th className="px-3 py-2 text-left">Город</th>
              <th className="px-3 py-2 text-left">След. задача</th>
              <th className="px-3 py-2 text-left">Создана</th>
            </tr>
          </thead>

          <tbody className={[
            "divide-y divide-gray-100 dark:divide-gray-800",
            hasData ? "blur-fade" : "",
          ].join(" ")}>
            {/* Skeleton */}
            {isLoading && Array.from({ length: SKELETON_COUNT }).map((_, i) => (
              <SkeletonRow key={i} hasBulk={bulkMode} />
            ))}

            {/* Empty state */}
            {isEmpty && (
              <tr>
                <td colSpan={bulkMode ? 9 : 8}>
                  <EmptyState
                    icon="bi-briefcase"
                    title="Нет сделок по выбранным фильтрам"
                    description="Попробуй изменить или сбросить фильтры"
                  />
                </td>
              </tr>
            )}

            {/* Data rows */}
            {hasData && rows.map((row) => {
              const isSelected = selectedIds.includes(row.id);
              return (
                <tr
                  key={row.id}
                  className={[
                    "group border-b border-gray-100 dark:border-gray-800",
                    "transition-colors duration-100",
                    "hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]",
                    "cursor-pointer",
                    isSelected ? "bg-primary/5 dark:bg-primary/10" : "",
                  ].join(" ")}
                  onClick={() => {
                    if (bulkMode) {
                      onSelect(row.id, !isSelected);
                      return;
                    }
                    router.push(`/deals/${row.id}`);
                  }}
                >
                  {bulkMode && (
                    <td className={`w-8 ${TD}`} onClick={(e) => e.stopPropagation()}>
                      <input
                        type="checkbox"
                        className="w-4 h-4 cursor-pointer"
                        checked={isSelected}
                        onChange={(e) => onSelect(row.id, e.target.checked)}
                      />
                    </td>
                  )}
                  <td className={`${TD} max-w-[180px]`}>
                    <span className="truncate block font-medium text-gray-900 dark:text-gray-100">
                      {row.company_name ?? "—"}
                    </span>
                  </td>
                  <td className={TD}>
                    <span
                      className="badge text-xs"
                      style={{
                        backgroundColor: row.stage_color ? `${row.stage_color}22` : "rgba(107,122,153,0.13)",
                        color: row.stage_color ?? "#6B7A99",
                      }}
                    >
                      <span
                        className="w-1.5 h-1.5 rounded-full shrink-0"
                        style={{ backgroundColor: row.stage_color ?? "#6B7A99" }}
                      />
                      {row.stage_name}
                    </span>
                  </td>
                  <td className={`${TD} text-gray-600 dark:text-gray-400`}>
                    {row.owner_user_id ? (userMap.get(row.owner_user_id) ?? "—") : "—"}
                  </td>
                  <td className={`${TD} text-right tabular-nums font-medium text-gray-800 dark:text-gray-200`}>
                    {formatCurrency(row.amount, row.currency)}
                  </td>
                  <td className={TD}>
                    {row.product ? (
                      <span className="badge-info text-[11px]">
                        {row.product}
                      </span>
                    ) : (
                      <span className="text-gray-400">—</span>
                    )}
                  </td>
                  <td className={`${TD} text-gray-600 dark:text-gray-400`}>
                    {row.city ?? "—"}
                  </td>
                  <td className={TD}>
                    <TaskCell task={row.next_task} />
                  </td>
                  <td className={`${TD} text-gray-400 text-xs tabular-nums whitespace-nowrap`}>
                    {fmtDate(row.created_at)}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>
    </div>
  );
}
