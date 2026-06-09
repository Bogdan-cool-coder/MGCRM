"use client";

import { useState, useMemo, useRef, useEffect } from "react";
import { useRouter, useSearchParams } from "next/navigation";
import useSWR from "swr";
import Link from "next/link";
import { PageHeader } from "@/components/PageHeader";
import { RoleGate } from "@/components/RoleGate";
import { EmptyState } from "@/components/EmptyState";
import { ReportFilterBar, type ReportFilters } from "@/components/Finance/ReportFilterBar";
import { formatCurrency, formatAmount } from "@/lib/format";
import { FinTableSkeleton } from "@/components/Finance/FinTableSkeleton";
import { fetcher } from "@/lib/api";
import type { FinEntriesListOut, FinJournalEntry, FinAccountGl, FinLegalEntity } from "@/lib/types";

// Главная книга (raw GL-журнал) гейтится capability view_journal на backend —
// которого нет у director. Выравниваем фронт под backend: только accountant/cfo/admin.
const GL_ROLES = ["accountant", "cfo", "admin"] as const;

const PAGE_SIZE = 50;

const ENTRY_STATUS_OPTIONS = [
  { value: "", label: "Все статусы" },
  { value: "posted", label: "Проведено" },
  { value: "reversed", label: "Сторнировано" },
  { value: "draft", label: "Черновик" },
];

const ENTRY_STATUS_BADGES: Record<string, string> = {
  posted:   "badge-success",
  reversed: "badge-warning",
  draft:    "badge-neutral",
};

const ENTRY_STATUS_LABELS: Record<string, string> = {
  posted:   "Проведено",
  reversed: "Сторнировано",
  draft:    "Черновик",
};

const ENTRY_KIND_LABELS: Record<string, string> = {
  operation:      "Операция",
  manual_journal: "Ручной журнал",
  reversal:       "Сторно",
  opening:        "Ввод остатка",
};

function buildGlUrl(f: ReportFilters, status: string, accountGlId: string, offset: number): string | null {
  if (!f.entity && !f.date_from && !f.date_to && !accountGlId && !status) return null;
  const p = new URLSearchParams({ limit: String(PAGE_SIZE), offset: String(offset) });
  if (f.entity) p.set("legal_entity_id", f.entity);
  if (f.date_from) p.set("date_from", f.date_from);
  if (f.date_to) p.set("date_to", f.date_to);
  if (status) p.set("status", status);
  if (accountGlId) p.set("account_gl_id", accountGlId);
  return `/api/finance/entries?${p}`;
}

function EntryRow({ entry, currency }: { entry: FinJournalEntry; currency: string }) {
  const [expanded, setExpanded] = useState(false);
  const badgeCls = ENTRY_STATUS_BADGES[entry.status] ?? "badge-neutral";
  const statusLabel = ENTRY_STATUS_LABELS[entry.status] ?? entry.status;
  const kindLabel = ENTRY_KIND_LABELS[entry.kind] ?? entry.kind;

  let sourceLink: string | null = null;
  if (entry.source === "operation" && entry.source_ref_id != null) {
    sourceLink = `/finance/operations/${entry.source_ref_id}`;
  } else if (entry.source === "manual_journal" && entry.source_ref_id != null) {
    sourceLink = `/finance/journals/${entry.source_ref_id}`;
  }

  const dtTotal = entry.lines.reduce((s, l) => s + (l.amount_func > 0 ? l.amount_func : 0), 0);
  const ktTotal = entry.lines.reduce((s, l) => s + (l.amount_func < 0 ? Math.abs(l.amount_func) : 0), 0);

  return (
    <>
      <tr
        className="border-t border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800/50 transition-colors cursor-pointer"
        onClick={() => setExpanded(!expanded)}
      >
        <td className="px-4 py-2.5 text-gray-400 dark:text-gray-500 w-8">
          <i className={`bi ${expanded ? "bi-chevron-down" : "bi-chevron-right"} text-xs`} />
        </td>
        <td className="px-2 py-2.5">
          <span className={`badge text-xs ${badgeCls}`}>{statusLabel}</span>
        </td>
        <td className="px-3 py-2.5 text-sm font-mono text-xs text-gray-700 dark:text-gray-300">
          {entry.date}
        </td>
        <td className="px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400">
          {kindLabel}
        </td>
        <td className="px-3 py-2.5 text-sm text-gray-700 dark:text-gray-300 max-w-[200px] truncate" title={entry.memo ?? undefined}>
          {entry.memo ?? <span className="text-gray-300 dark:text-gray-600">—</span>}
        </td>
        <td className="px-4 py-2.5 text-right tabular-nums text-sm text-gray-700 dark:text-gray-300">
          {formatCurrency(dtTotal, currency)}
        </td>
        <td className="px-4 py-2.5 text-right tabular-nums text-sm text-gray-700 dark:text-gray-300">
          {formatCurrency(ktTotal, currency)}
        </td>
        <td className="px-3 py-2.5 text-xs">
          {sourceLink ? (
            <Link
              href={sourceLink}
              className="text-primary dark:text-blue-400 hover:underline"
              onClick={(e) => e.stopPropagation()}
            >
              <i className="bi bi-arrow-up-right-square mr-1" />
              {entry.source === "operation" ? `Опер. #${entry.source_ref_id}` : `Журнал #${entry.source_ref_id}`}
            </Link>
          ) : (
            <span className="text-gray-400 dark:text-gray-500">{entry.source}</span>
          )}
        </td>
      </tr>

      {/* Строки проводки (раскрытые) */}
      {expanded && entry.lines.map((line) => (
        <tr
          key={line.id}
          className="border-t border-gray-100/50 dark:border-gray-700/50 bg-gray-50/80 dark:bg-gray-900/30 text-xs"
        >
          <td className="px-4 py-1.5" />
          <td className="px-2 py-1.5">
            <span className={`badge text-[10px] ${line.amount_func > 0 ? "badge-info" : "badge-danger"}`}>
              {line.amount_func > 0 ? "Дт" : "Кт"}
            </span>
          </td>
          <td className="px-3 py-1.5 text-gray-500 dark:text-gray-400 font-mono">
            GL-{line.account_gl_id}
          </td>
          <td className="px-3 py-1.5 text-gray-500 dark:text-gray-400">
            {line.amount_func > 0 ? "дебет" : "кредит"}
          </td>
          <td className="px-3 py-1.5 text-gray-500 dark:text-gray-400 truncate max-w-[200px]">
            {line.comment ?? "—"}
          </td>
          <td className="px-4 py-1.5 text-right tabular-nums text-gray-700 dark:text-gray-300">
            {line.amount_func > 0 ? formatAmount(line.amount_func) : "—"}
          </td>
          <td className="px-4 py-1.5 text-right tabular-nums text-gray-700 dark:text-gray-300">
            {line.amount_func < 0 ? formatAmount(Math.abs(line.amount_func)) : "—"}
          </td>
          <td className="px-3 py-1.5 text-gray-400 dark:text-gray-500">
            {line.currency !== currency && (
              <span className="font-mono text-[10px]">
                {formatCurrency(line.amount, line.currency)}
                {line.fx_rate != null && ` @ ${line.fx_rate}`}
              </span>
            )}
          </td>
        </tr>
      ))}
    </>
  );
}

export default function GlReportPage() {
  const searchParams = useSearchParams();
  const router = useRouter();
  const wrapRef = useRef<HTMLDivElement>(null);

  const [filters, setFilters] = useState<ReportFilters>(() => ({
    entity: searchParams.get("entity") ?? "",
    date_from: searchParams.get("date_from") ?? "",
    date_to: searchParams.get("date_to") ?? "",
  }));
  const [entryStatus, setEntryStatus] = useState(searchParams.get("status") ?? "");
  const [accountGlId, setAccountGlId] = useState(searchParams.get("account_gl_id") ?? "");
  const [offset, setOffset] = useState(0);

  const apiUrl = useMemo(() => buildGlUrl(filters, entryStatus, accountGlId, offset), [filters, entryStatus, accountGlId, offset]);
  const { data: result, isLoading, error } = useSWR<FinEntriesListOut>(apiUrl, fetcher);
  const { data: glAccounts } = useSWR<FinAccountGl[]>("/api/finance/chart-of-accounts", fetcher);
  const { data: entities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const entity = entities?.find((e) => String(e.id) === filters.entity);
  const currency = entity?.functional_currency ?? "";

  // Scroll-shadow на fin-table-wrap
  useEffect(() => {
    const el = wrapRef.current;
    if (!el) return;
    const onScroll = () => {
      el.classList.toggle("scrolled", el.scrollTop > 4);
    };
    el.addEventListener("scroll", onScroll, { passive: true });
    return () => el.removeEventListener("scroll", onScroll);
  }, []);

  function handleFiltersChange(f: ReportFilters) {
    setFilters(f);
    setOffset(0);
    syncUrl(f, entryStatus, accountGlId, 0);
  }

  function handleStatusChange(s: string) {
    setEntryStatus(s);
    setOffset(0);
    syncUrl(filters, s, accountGlId, 0);
  }

  function handleAccountChange(a: string) {
    setAccountGlId(a);
    setOffset(0);
    syncUrl(filters, entryStatus, a, 0);
  }

  function syncUrl(f: ReportFilters, s: string, a: string, o: number) {
    const p = new URLSearchParams();
    if (f.entity) p.set("entity", f.entity);
    if (f.date_from) p.set("date_from", f.date_from);
    if (f.date_to) p.set("date_to", f.date_to);
    if (s) p.set("status", s);
    if (a) p.set("account_gl_id", a);
    if (o > 0) p.set("offset", String(o));
    router.replace(`/finance/reports/gl?${p}`, { scroll: false });
  }

  const total = result?.total ?? 0;
  const totalPages = Math.ceil(total / PAGE_SIZE);
  const currentPage = Math.floor(offset / PAGE_SIZE) + 1;

  return (
    <RoleGate
      allowed={[...GL_ROLES]}
      fallback={
        <div className="p-8 text-center text-gray-500 dark:text-gray-400">
          <i className="bi bi-lock text-3xl mb-3 block text-gray-300 dark:text-gray-600" />
          <p className="text-sm">Главная книга доступна только бухгалтеру, CFO и администратору.</p>
          <Link href="/finance/reports" className="text-primary dark:text-blue-400 hover:underline text-sm mt-2 inline-block">
            <i className="bi bi-arrow-left mr-1" />
            К отчётам
          </Link>
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Главная книга (GL)"
          description="Полный листинг проводок. Только чтение. Drill-down к операции или ручному журналу."
          actions={
            <Link href="/finance/reports" className="btn-ghost text-sm">
              <i className="bi bi-arrow-left mr-1" />
              К отчётам
            </Link>
          }
        />

        <div className="p-6 flex flex-col gap-4">
          <ReportFilterBar filters={filters} onChange={handleFiltersChange} />

          {/* Дополнительные фильтры GL */}
          <div className="flex flex-wrap gap-2">
            <select
              className="input text-sm w-auto"
              value={entryStatus}
              onChange={(e) => handleStatusChange(e.target.value)}
            >
              {ENTRY_STATUS_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>

            <select
              className="input text-sm w-auto min-w-[200px]"
              value={accountGlId}
              onChange={(e) => handleAccountChange(e.target.value)}
            >
              <option value="">Все GL-счета</option>
              {glAccounts?.map((a) => (
                <option key={a.id} value={String(a.id)}>
                  {a.code} — {a.name}
                </option>
              ))}
            </select>
          </div>

          {!apiUrl && (
            <EmptyState
              icon="bi-journal-bookmark"
              title="Задайте фильтр"
              description="Задайте хотя бы один фильтр для отображения главной книги"
            />
          )}

          {apiUrl && error && (
            <div className="card p-6 text-center text-danger">
              <i className="bi bi-exclamation-triangle mr-2" />
              Не удалось загрузить главную книгу
            </div>
          )}

          {apiUrl && (isLoading || result) && (
            <>
              <div className="card overflow-hidden">
                {/* Метаинфо */}
                {result && (
                  <div className="px-5 py-3 border-b border-gray-100 dark:border-gray-700 flex items-center justify-between">
                    <span className="text-sm text-gray-600 dark:text-gray-400">
                      Проводок: <strong className="text-gray-800 dark:text-gray-200">{total}</strong>
                      {" "}(стр. {currentPage} из {totalPages || 1})
                    </span>
                    <span className="text-xs text-gray-400 dark:text-gray-500">
                      <i className="bi bi-eye mr-1" />
                      Только чтение
                    </span>
                  </div>
                )}

                <div
                  ref={wrapRef}
                  className="fin-table-wrap overflow-x-auto max-h-[60vh] overflow-y-auto"
                >
                  <table className="w-full text-sm min-w-[900px]">
                    <thead className="fin-thead-shadow bg-gray-50 dark:bg-gray-900/30 sticky top-0 z-10">
                      <tr>
                        <th className="w-8 px-4 py-2.5" />
                        <th className="text-left px-2 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-28">Статус</th>
                        <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-24">Дата</th>
                        <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-28">Тип</th>
                        <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium">Назначение</th>
                        <th className="text-right px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-32">Дт</th>
                        <th className="text-right px-4 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-32">Кт</th>
                        <th className="text-left px-3 py-2.5 text-xs text-gray-500 dark:text-gray-400 font-medium w-36">Источник</th>
                      </tr>
                    </thead>
                    <tbody>
                      {isLoading && !result ? (
                        <FinTableSkeleton
                          rows={8}
                          cols={["2rem", "6rem", "6rem", "7rem", "30%", "8rem", "8rem", "9rem"]}
                        />
                      ) : result && result.items.length === 0 ? (
                        <tr>
                          <td colSpan={8} className="py-12 text-center">
                            <EmptyState
                              icon="bi-journal-x"
                              title="Нет проводок"
                              description="Нет проводок по заданным фильтрам"
                            />
                          </td>
                        </tr>
                      ) : (
                        result?.items.map((entry) => (
                          <EntryRow key={entry.id} entry={entry} currency={currency} />
                        ))
                      )}
                    </tbody>
                  </table>
                </div>
              </div>

              {/* Пагинация */}
              {result && totalPages > 1 && (
                <div className="flex items-center justify-between">
                  <button
                    className="btn-secondary text-sm"
                    disabled={offset === 0}
                    onClick={() => {
                      const o = Math.max(0, offset - PAGE_SIZE);
                      setOffset(o);
                      syncUrl(filters, entryStatus, accountGlId, o);
                    }}
                  >
                    <i className="bi bi-chevron-left mr-1" />
                    Назад
                  </button>
                  <span className="text-sm text-gray-500 dark:text-gray-400">
                    Страница {currentPage} из {totalPages}
                  </span>
                  <button
                    className="btn-secondary text-sm"
                    disabled={offset + PAGE_SIZE >= total}
                    onClick={() => {
                      const o = offset + PAGE_SIZE;
                      setOffset(o);
                      syncUrl(filters, entryStatus, accountGlId, o);
                    }}
                  >
                    Вперёд
                    <i className="bi bi-chevron-right ml-1" />
                  </button>
                </div>
              )}
            </>
          )}
        </div>
      </div>
    </RoleGate>
  );
}
