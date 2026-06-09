"use client";

import { useEffect, useMemo, useState } from "react";
import Link from "next/link";
import { useSearchParams } from "next/navigation";
import useSWR from "swr";
import { RunStatusBadge } from "@/components/Automations/RunStatusBadge";
import { RunDetailsModal } from "@/components/Automations/RunDetailsModal";
import { RetryRunButton } from "@/components/Automations/RetryRunButton";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { fetcher } from "@/lib/api";
import { formatDateTime } from "@/lib/dates";
import {
  ACTION_KIND_FILTER_OPTIONS,
  RUN_STATUS_LABELS,
  TARGET_TYPE_LABELS,
  TARGET_TYPE_OPTIONS,
} from "@/lib/automationConfig";
import type {
  Automation,
  AutomationActionKind,
  AutomationRun,
  AutomationRunStatus,
  AutomationTargetType,
} from "@/lib/types";

const PAGE_SIZE = 50;

const STATUS_FILTER_OPTIONS: { value: AutomationRunStatus | ""; label: string }[] = [
  { value: "", label: "Все статусы" },
  { value: "pending", label: RUN_STATUS_LABELS.pending },
  { value: "success", label: RUN_STATUS_LABELS.success },
  { value: "failed", label: RUN_STATUS_LABELS.failed },
  { value: "skipped", label: RUN_STATUS_LABELS.skipped },
];

export function AutomationRunsPanel() {
  const searchParams = useSearchParams();

  const [automationFilter, setAutomationFilter] = useState<string>("");
  const [targetTypeFilter, setTargetTypeFilter] = useState<string>("");
  const [targetIdFilter, setTargetIdFilter] = useState<string>("");
  const [actionKindFilter, setActionKindFilter] = useState<AutomationActionKind | "">("");
  const [statusFilter, setStatusFilter] = useState<AutomationRunStatus | "">("");
  const [pageOffset, setPageOffset] = useState(0);
  const [selectedRun, setSelectedRun] = useState<AutomationRun | null>(null);

  // Подхватываем automation_id=... из query (если открыли по ссылке из таблицы)
  useEffect(() => {
    const fromUrl = searchParams.get("automation_id");
    if (fromUrl && fromUrl !== automationFilter) {
      setAutomationFilter(fromUrl);
      setPageOffset(0);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [searchParams]);

  // Сбрасываем offset при любом изменении фильтров
  useEffect(() => {
    setPageOffset(0);
  }, [automationFilter, targetTypeFilter, targetIdFilter, actionKindFilter, statusFilter]);

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (automationFilter) params.set("automation_id", automationFilter);
    if (targetTypeFilter) params.set("target_type", targetTypeFilter);
    if (targetIdFilter) params.set("target_id", targetIdFilter);
    if (actionKindFilter) params.set("action_kind", actionKindFilter);
    if (statusFilter) params.set("status", statusFilter);
    params.set("limit", String(PAGE_SIZE));
    params.set("offset", String(pageOffset));
    return `?${params.toString()}`;
  }, [automationFilter, targetTypeFilter, targetIdFilter, actionKindFilter, statusFilter, pageOffset]);

  const { data: runs, isLoading, error, mutate } = useSWR<AutomationRun[]>(
    `/automation-runs${queryString}`,
    fetcher,
  );
  const { data: automations } = useSWR<Automation[]>("/automations", fetcher);

  const list = runs ?? [];
  const hasMore = list.length === PAGE_SIZE;

  function resetFilters() {
    setAutomationFilter("");
    setTargetTypeFilter("");
    setTargetIdFilter("");
    setActionKindFilter("");
    setStatusFilter("");
  }

  const hasFilters = Boolean(
    automationFilter || targetTypeFilter || targetIdFilter || actionKindFilter || statusFilter,
  );

  const columns: DataTableColumn<AutomationRun>[] = [
    {
      key: "started_at",
      header: "Когда",
      width: "12rem",
      skeletonWidth: "8rem",
      render: (r) => (
        <span className="whitespace-nowrap text-gray-700 dark:text-gray-300">{formatDateTime(r.started_at)}</span>
      ),
    },
    {
      key: "automation_name",
      header: "Автоматизация",
      skeletonWidth: "55%",
      render: (r) =>
        r.automation_name ? (
          <Link
            href={`/admin/automations/${r.automation_id}`}
            className="text-primary dark:text-primary-light hover:underline"
            onClick={(e) => e.stopPropagation()}
          >
            {r.automation_name}
          </Link>
        ) : (
          <span className="text-gray-500">#{r.automation_id}</span>
        ),
    },
    {
      key: "target",
      header: "Цель",
      width: "10rem",
      skeletonWidth: "5rem",
      render: (r) => {
        const label = TARGET_TYPE_LABELS[r.target_type as AutomationTargetType] ?? r.target_type;
        return (
          <span className="font-mono text-xs text-gray-600 dark:text-gray-400 whitespace-nowrap">
            {label} #{r.target_id}
          </span>
        );
      },
    },
    {
      key: "status",
      header: "Статус",
      width: "8rem",
      skeletonWidth: "5rem",
      render: (r) => <RunStatusBadge status={r.status} />,
    },
    {
      key: "error_text",
      header: "Сообщение",
      skeletonWidth: "70%",
      render: (r) => (
        <div className="text-xs text-gray-600 dark:text-gray-400 line-clamp-1">
          {r.error_text ?? "—"}
        </div>
      ),
    },
  ];

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="card rounded-2xl shadow-elev-1 lift p-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[260px]">
          <label className="label">Автоматизация</label>
          <select
            className="input"
            value={automationFilter}
            onChange={(e) => setAutomationFilter(e.target.value)}
          >
            <option value="">Все</option>
            {(automations ?? []).map((a) => (
              <option key={a.id} value={String(a.id)}>{a.name}</option>
            ))}
          </select>
        </div>
        <div className="min-w-[160px]">
          <label className="label">Тип цели</label>
          <select
            className="input"
            value={targetTypeFilter}
            onChange={(e) => setTargetTypeFilter(e.target.value)}
          >
            <option value="">Любой</option>
            {TARGET_TYPE_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>
        <div className="min-w-[140px]">
          <label className="label">ID цели</label>
          <input
            className="input"
            type="number"
            min={1}
            value={targetIdFilter}
            onChange={(e) => setTargetIdFilter(e.target.value)}
            placeholder="#"
          />
        </div>
        <div className="min-w-[220px]">
          <label className="label">Действие</label>
          <select
            className="input"
            value={actionKindFilter}
            onChange={(e) => setActionKindFilter(e.target.value as AutomationActionKind | "")}
          >
            {ACTION_KIND_FILTER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>
        <div className="min-w-[180px]">
          <label className="label">Статус</label>
          <select
            className="input"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as AutomationRunStatus | "")}
          >
            {STATUS_FILTER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>
        {hasFilters && (
          <button className="btn-ghost" onClick={resetFilters}>
            <i className="bi bi-x-lg mr-1" /> Сбросить
          </button>
        )}
      </div>

      {/* Table */}
      <DataTable
        columns={columns}
        rows={error ? [] : runs}
        getRowKey={(r) => r.id}
        onRowClick={(r) => setSelectedRun(r)}
        rowActions={(r) => (
          <>
            {r.status === "failed" && (
              <RetryRunButton
                runId={r.id}
                onRetried={() => void mutate()}
              />
            )}
            <button
              className="btn-ghost text-primary dark:text-primary-light p-1"
              title="Подробности"
              onClick={(e) => { e.stopPropagation(); setSelectedRun(r); }}
            >
              <i className="bi bi-arrows-angle-expand" />
            </button>
          </>
        )}
        ariaLabel="История запусков автоматизаций"
        emptyIcon="bi-play-circle"
        emptyTitle="Запусков ещё не было"
        emptyText="История запусков появится после первых срабатываний автоматизаций"
        isError={!!error}
        errorText="Не удалось загрузить историю запусков"
        skeletonRows={8}
      />

      {/* Pagination */}
      <div className="flex items-center justify-between gap-2 text-sm">
        <div className="text-gray-600 dark:text-gray-400">
          {list.length > 0
            ? `Показано ${pageOffset + 1}–${pageOffset + list.length}`
            : "Нет записей"}
        </div>
        <div className="flex items-center gap-2">
          <button
            className="btn-ghost"
            disabled={pageOffset === 0}
            onClick={() => setPageOffset((o) => Math.max(0, o - PAGE_SIZE))}
          >
            <i className="bi bi-chevron-left mr-1" /> Назад
          </button>
          <button
            className="btn-secondary"
            disabled={!hasMore}
            onClick={() => setPageOffset((o) => o + PAGE_SIZE)}
          >
            Показать ещё <i className="bi bi-chevron-right ml-1" />
          </button>
        </div>
      </div>

      <RunDetailsModal
        open={selectedRun !== null}
        onClose={() => setSelectedRun(null)}
        run={selectedRun}
      />
    </div>
  );
}
