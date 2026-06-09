/**
 * DataTable<T> — универсальный tabular-компонент v2.
 *
 * Покрывает:
 *  • реестр клиентов  (sticky thead + row-actions + blur-fade + EmptyState + внешние фильтры)
 *  • финансовые таблицы (total-bar через footer, semantic суммы через columns.render)
 *  • любые CRUD-списки  (compact density, onRowClick, isError)
 *
 * USAGE: см. apps/web/docs/datatable-usage.md
 */

"use client";

import { useCallback, useEffect, useRef, useState } from "react";
import { EmptyState } from "@/components/EmptyState";

// ─── Типы ────────────────────────────────────────────────────────────────────

export type ColumnAlign = "left" | "right" | "center";
export type TableDensity = "comfortable" | "compact";

export interface DataTableColumn<T> {
  /** Уникальный ключ колонки — используется для skeleton-ширин и key. */
  key: string;
  /** Заголовок th. */
  header: React.ReactNode;
  /** Выравнивание ячеек (и заголовка). По умолчанию: left. */
  align?: ColumnAlign;
  /** CSS width для th (например "12rem", "8%"). Опционально. */
  width?: string;
  /** Доп. className для td. */
  className?: string;
  /**
   * Кастомный рендер ячейки.
   * Если не указан — рендерится `String(row[key])` (для примитивных значений).
   */
  render?: (row: T) => React.ReactNode;
  /**
   * Ширина skeleton-блока внутри ячейки (CSS строка: "60%", "5rem", …).
   * По умолчанию "60%".
   */
  skeletonWidth?: string;
  /** Пометить колонку как sortable (отображает иконку, вызывает onSort). */
  sortable?: boolean;
}

export interface DataTableProps<T> {
  /** Описание колонок. */
  columns: DataTableColumn<T>[];
  /**
   * Данные. undefined = состояние загрузки (показываем skeleton).
   * [] = пустой результат (показываем EmptyState).
   */
  rows: T[] | undefined;
  /** Уникальный ключ строки. */
  getRowKey: (row: T) => string | number;
  /** Клик по строке. Включает cursor-pointer и role="button" на tr. */
  onRowClick?: (row: T) => void;
  /**
   * Кнопки/ссылки действий справа в строке — появляются при hover.
   * Рендерятся в отдельную последнюю td с opacity-transition.
   */
  rowActions?: (row: T) => React.ReactNode;
  /** Прилипающий заголовок при скролле (default: true). */
  stickyHeader?: boolean;
  /** Плотность строк (default: "comfortable"). */
  density?: TableDensity;
  /** Максимальная высота scroll-контейнера (default: "70vh"). */
  maxHeight?: string;
  /** ARIA-метка для таблицы. */
  ariaLabel?: string;
  // ── EmptyState ──────────────────────────────────────────────────────────
  emptyIcon?: string;
  emptyTitle?: string;
  emptyText?: string;
  emptyCta?: React.ReactNode;
  // ── Error state ─────────────────────────────────────────────────────────
  isError?: boolean;
  errorText?: string;
  // ── Footer (total-bar) ──────────────────────────────────────────────────
  /**
   * Содержимое tfoot. Получает число колонок (с учётом rowActions-колонки)
   * чтобы помочь расставить colSpan.
   */
  footer?: (totalColumns: number) => React.ReactNode;
  // ── Sorting ─────────────────────────────────────────────────────────────
  sortKey?: string;
  sortDir?: "asc" | "desc";
  onSort?: (key: string) => void;
  // ── Skeleton ─────────────────────────────────────────────────────────────
  /** Количество skeleton-строк при загрузке (default: 6). */
  skeletonRows?: number;
  /**
   * CSS table-layout. "auto" (default) — браузер распределяет ширины сам.
   * "fixed" — ширины берутся строго из width колонок; колонки без width
   * делят оставшееся место поровну. Используй когда нужно чтобы узкие
   * колонки (напр. «Тип») не уезжали вправо из-за длинного контента соседей.
   */
  tableLayout?: "auto" | "fixed";
}

// ─── Вспомогательные константы ───────────────────────────────────────────────

const ALIGN_CLS: Record<ColumnAlign, string> = {
  left:   "text-left",
  right:  "text-right",
  center: "text-center",
};

const DENSITY_TD: Record<TableDensity, string> = {
  comfortable: "px-4 py-2.5",
  compact:     "px-3 py-1.5",
};

const DENSITY_TH: Record<TableDensity, string> = {
  comfortable: "px-4 py-2.5",
  compact:     "px-3 py-2",
};

// ─── Компонент ───────────────────────────────────────────────────────────────

export function DataTable<T>({
  columns,
  rows,
  getRowKey,
  onRowClick,
  rowActions,
  stickyHeader = true,
  density = "comfortable",
  maxHeight = "70vh",
  ariaLabel,
  emptyIcon = "bi-table",
  emptyTitle = "Нет данных",
  emptyText,
  emptyCta,
  isError = false,
  errorText = "Не удалось загрузить данные",
  footer,
  sortKey,
  sortDir,
  onSort,
  skeletonRows = 6,
  tableLayout = "auto",
}: DataTableProps<T>) {
  // ── Scroll-detect для sticky thead shadow ──────────────────────────────────
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

  // ── Число колонок для colSpan ───────────────────────────────────────────────
  const totalColumns = columns.length + (rowActions ? 1 : 0);

  // ── Общий класс td по density ──────────────────────────────────────────────
  const tdCls = DENSITY_TD[density];
  const thCls = [
    DENSITY_TH[density],
    "text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400",
  ].join(" ");

  // ── Отдельные состояния ────────────────────────────────────────────────────
  const isLoading = rows === undefined;
  const isEmpty   = !isLoading && !isError && rows.length === 0;
  const hasData   = !isLoading && !isError && rows.length > 0;

  return (
    <div
      className={[
        "card rounded-2xl overflow-hidden shadow-elev-1 dt-table-wrap",
        scrolled ? "scrolled" : "",
      ].join(" ")}
    >
      <div
        ref={wrapRef}
        className="overflow-x-auto overflow-y-auto"
        style={{ maxHeight }}
        onScroll={handleScroll}
      >
        <table
          className={["w-full text-sm", tableLayout === "fixed" ? "table-fixed" : ""].join(" ").trim()}
          role={onRowClick ? "grid" : "table"}
          aria-label={ariaLabel}
        >
          {/* ── thead ──────────────────────────────────────────────────── */}
          <thead
            className={[
              stickyHeader ? "sticky top-0 z-10" : "",
              "bg-white dark:bg-gray-900",
              "border-b border-gray-200 dark:border-gray-700",
              "dt-thead-shadow",
            ].join(" ")}
          >
            <tr>
              {columns.map((col) => (
                <th
                  key={col.key}
                  scope="col"
                  className={[
                    thCls,
                    ALIGN_CLS[col.align ?? "left"],
                    col.sortable ? "cursor-pointer select-none hover:text-primary dark:hover:text-blue-300 transition-colors" : "",
                  ].join(" ")}
                  style={col.width ? { width: col.width } : undefined}
                  onClick={col.sortable && onSort ? () => onSort(col.key) : undefined}
                  aria-sort={
                    col.sortable
                      ? sortKey === col.key
                        ? sortDir === "asc" ? "ascending" : "descending"
                        : "none"
                      : undefined
                  }
                >
                  <span className="inline-flex items-center gap-1">
                    {col.header}
                    {col.sortable && (
                      <SortIcon active={sortKey === col.key} dir={sortDir} />
                    )}
                  </span>
                </th>
              ))}
              {/* placeholder-заголовок для колонки row-actions */}
              {rowActions && (
                <th scope="col" className={`${thCls} w-10`} aria-label="Действия" />
              )}
            </tr>
          </thead>

          {/* ── tbody ──────────────────────────────────────────────────── */}
          <tbody
            className={[
              "divide-y divide-gray-100 dark:divide-gray-800",
              hasData ? "blur-fade" : "",
            ].join(" ")}
            style={hasData ? ({ "--blur-fade-duration": "0.2s" } as React.CSSProperties) : undefined}
          >
            {/* Skeleton */}
            {isLoading && (
              <SkeletonRows
                count={skeletonRows}
                columns={columns}
                hasActions={!!rowActions}
                tdCls={tdCls}
              />
            )}

            {/* Error */}
            {isError && (
              <tr>
                <td colSpan={totalColumns}>
                  <div className="px-4 py-6 text-center text-sm text-danger">
                    <i className="bi bi-exclamation-triangle mr-2" />
                    {errorText}
                  </div>
                </td>
              </tr>
            )}

            {/* Empty */}
            {isEmpty && (
              <tr>
                <td colSpan={totalColumns}>
                  <EmptyState
                    icon={emptyIcon}
                    title={emptyTitle}
                    description={emptyText}
                    cta={emptyCta}
                  />
                </td>
              </tr>
            )}

            {/* Data rows */}
            {hasData &&
              rows.map((row) => (
                <DataRow
                  key={getRowKey(row)}
                  row={row}
                  columns={columns}
                  onRowClick={onRowClick}
                  rowActions={rowActions}
                  tdCls={tdCls}
                />
              ))}
          </tbody>

          {/* ── tfoot / total-bar ──────────────────────────────────────── */}
          {footer && hasData && (
            <tfoot>{footer(totalColumns)}</tfoot>
          )}
        </table>
      </div>
    </div>
  );
}

// ─── DataRow ─────────────────────────────────────────────────────────────────

interface DataRowProps<T> {
  row: T;
  columns: DataTableColumn<T>[];
  onRowClick?: (row: T) => void;
  rowActions?: (row: T) => React.ReactNode;
  tdCls: string;
}

function DataRow<T>({ row, columns, onRowClick, rowActions, tdCls }: DataRowProps<T>) {
  const isClickable = !!onRowClick;

  function handleKeyDown(e: React.KeyboardEvent<HTMLTableRowElement>) {
    if (isClickable && (e.key === "Enter" || e.key === " ")) {
      e.preventDefault();
      onRowClick!(row);
    }
  }

  return (
    <tr
      className={[
        "group border-b border-gray-100 dark:border-gray-800",
        "transition-colors duration-100",
        "hover:bg-primary/[0.03] dark:hover:bg-primary/[0.06]",
        isClickable ? "cursor-pointer" : "",
      ].join(" ")}
      onClick={isClickable ? () => onRowClick!(row) : undefined}
      onKeyDown={isClickable ? handleKeyDown : undefined}
      tabIndex={isClickable ? 0 : undefined}
      role={isClickable ? "row" : undefined}
    >
      {columns.map((col) => (
        <td
          key={col.key}
          className={[
            tdCls,
            ALIGN_CLS[col.align ?? "left"],
            col.align === "right" ? "tabular-nums" : "",
            col.className ?? "",
          ].join(" ")}
        >
          {col.render
            ? col.render(row)
            : (() => {
                const val = (row as Record<string, unknown>)[col.key];
                return val != null ? String(val) : "—";
              })()}
        </td>
      ))}

      {rowActions && (
        <td className={`${tdCls} text-right`}>
          <div className="opacity-0 group-hover:opacity-100 transition-opacity duration-100 flex items-center justify-end gap-1">
            {rowActions(row)}
          </div>
        </td>
      )}
    </tr>
  );
}

// ─── Skeleton rows ────────────────────────────────────────────────────────────

interface SkeletonRowsProps<T> {
  count: number;
  columns: DataTableColumn<T>[];
  hasActions: boolean;
  tdCls: string;
}

function SkeletonRows<T>({ count, columns, hasActions, tdCls }: SkeletonRowsProps<T>) {
  return (
    <>
      {Array.from({ length: count }).map((_, i) => (
        <tr key={i} className="border-b border-gray-100 dark:border-gray-800">
          {columns.map((col) => (
            <td key={col.key} className={tdCls}>
              <div
                className="animate-pulse h-4 bg-gray-100 dark:bg-gray-800 rounded"
                style={{ width: col.skeletonWidth ?? "60%" }}
              />
            </td>
          ))}
          {hasActions && (
            <td className={tdCls}>
              <div className="animate-pulse h-4 w-6 bg-gray-100 dark:bg-gray-800 rounded ml-auto" />
            </td>
          )}
        </tr>
      ))}
    </>
  );
}

// ─── Sort icon ────────────────────────────────────────────────────────────────

function SortIcon({ active, dir }: { active: boolean; dir?: "asc" | "desc" }) {
  if (!active) {
    return <i className="bi bi-chevron-expand text-[10px] opacity-40" />;
  }
  return (
    <i
      className={[
        dir === "asc" ? "bi bi-chevron-up" : "bi bi-chevron-down",
        "text-[10px] text-primary dark:text-blue-300",
      ].join(" ")}
    />
  );
}
