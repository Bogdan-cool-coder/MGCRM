"use client";

import { useMemo, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { TriggerBadge } from "@/components/Automations/TriggerBadge";
import { ActionBadge } from "@/components/Automations/ActionBadge";
import { AutomationStatusToggle } from "@/components/Automations/AutomationStatusToggle";
import { TestRunModal } from "@/components/Automations/TestRunModal";
import { EmptyState } from "@/components/EmptyState";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { SlaCard } from "@/components/Sla/SlaCard";
import { fetcher } from "@/lib/api";
import { TRIGGER_OPTIONS, TRIGGER_LABELS } from "@/lib/automationConfig";
import type { Automation, AutomationTriggerKind, Pipeline } from "@/lib/types";

type ActiveFilter = "all" | "active" | "inactive";

interface Props {
  /** Если true — показывает только SLA (trigger_kind=idle_in_stage_days) */
  slaOnly?: boolean;
}

export function AutomationsListPanel({ slaOnly = false }: Props) {
  const router = useRouter();
  const [dryRunTarget, setDryRunTarget] = useState<Automation | null>(null);
  const [pipelineFilter, setPipelineFilter] = useState<string>("");
  const [triggerFilter, setTriggerFilter] = useState<string>("");
  const [activeFilter, setActiveFilter] = useState<ActiveFilter>("all");

  const queryString = useMemo(() => {
    const params = new URLSearchParams();
    if (slaOnly) {
      params.set("trigger_kind", "idle_in_stage_days");
    } else {
      if (pipelineFilter) params.set("pipeline_id", pipelineFilter);
      if (triggerFilter) params.set("trigger_kind", triggerFilter);
    }
    if (activeFilter !== "all") params.set("is_active", activeFilter === "active" ? "true" : "false");
    const qs = params.toString();
    return qs ? `?${qs}` : "";
  }, [slaOnly, pipelineFilter, triggerFilter, activeFilter]);

  const swrKey = `/automations${queryString}`;

  const { data: automations, mutate, isLoading, error } = useSWR<Automation[]>(swrKey, fetcher);
  const { data: pipelines } = useSWR<Pipeline[]>(slaOnly ? null : "/pipelines", fetcher);

  function resetFilters() {
    setPipelineFilter("");
    setTriggerFilter("");
    setActiveFilter("all");
  }

  const newHref = slaOnly ? "/admin/sla/new" : "/admin/automations/new";
  const newLabel = slaOnly ? "Создать правило" : "Новая автоматизация";

  // ---- SLA-вкладка: карточный вид ----
  if (slaOnly) {
    const active = (automations ?? []).filter((a) => a.is_active).length;
    return (
      <div className="space-y-5">
        {/* Фильтр по состоянию */}
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div className="flex items-center gap-4 text-sm text-gray-600 dark:text-gray-400">
            {!isLoading && !error && (automations ?? []).length > 0 && (
              <>
                <span>
                  <span className="font-semibold text-primary">{active}</span>{" "}Активных
                </span>
                <span>
                  <span className="font-semibold text-primary">{(automations ?? []).length}</span>{" "}Всего правил
                </span>
              </>
            )}
          </div>
          <div className="flex items-center gap-3">
            <select
              className="input text-sm"
              value={activeFilter}
              onChange={(e) => setActiveFilter(e.target.value as ActiveFilter)}
            >
              <option value="all">Любое состояние</option>
              <option value="active">Только активные</option>
              <option value="inactive">Только выключенные</option>
            </select>
            {activeFilter !== "all" && (
              <button className="btn-ghost" onClick={() => setActiveFilter("all")}>
                <i className="bi bi-x-lg" /> Сбросить
              </button>
            )}
            <Link href={newHref} className="btn-primary">
              <i className="bi bi-plus-lg mr-1" />
              {newLabel}
            </Link>
          </div>
        </div>

        {error && (
          <div className="card rounded-2xl shadow-elev-1 p-4 flex items-center gap-3 text-danger text-sm">
            <i className="bi bi-exclamation-triangle text-lg" />
            Не удалось загрузить правила. Попробуйте обновить страницу.
          </div>
        )}

        {isLoading && (
          <div className="space-y-4">
            {[1, 2, 3].map((i) => (
              <div key={i} className="card rounded-2xl shadow-elev-1 p-5 space-y-3 animate-pulse">
                <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/2" />
                <div className="h-3 bg-gray-200 dark:bg-gray-700 rounded w-1/3" />
                <div className="h-3 bg-gray-200 dark:bg-gray-700 rounded w-2/3" />
              </div>
            ))}
          </div>
        )}

        {!isLoading && !error && (automations ?? []).length === 0 && (
          <div className="card rounded-2xl shadow-elev-1">
            <EmptyState
              icon="bi-shield-check"
              title="SLA-правил пока нет"
              description="Создайте первое правило — система сама отследит просрочки и уведомит команду"
              cta={
                <Link href={newHref} className="btn-primary">
                  <i className="bi bi-plus-lg mr-1" />
                  {newLabel}
                </Link>
              }
            />
          </div>
        )}

        {!isLoading && !error && (automations ?? []).length > 0 && (
          <div className="space-y-4">
            {automations!.map((a) => (
              <SlaCard
                key={a.id}
                automation={a}
                onMutate={() => void mutate()}
                onDryRun={(target) => setDryRunTarget(target)}
              />
            ))}
          </div>
        )}

        {dryRunTarget && (
          <TestRunModal
            open
            onClose={() => setDryRunTarget(null)}
            automation={dryRunTarget}
          />
        )}
      </div>
    );
  }

  // ---- Обычная вкладка: DataTable ----
  const columns: DataTableColumn<Automation>[] = [
    {
      key: "is_active",
      header: "Активна",
      width: "5rem",
      skeletonWidth: "2.5rem",
      render: (a) => (
        <div onClick={(e) => e.stopPropagation()}>
          <AutomationStatusToggle
            automationId={a.id}
            isActive={a.is_active}
            onChanged={() => void mutate()}
          />
        </div>
      ),
    },
    {
      key: "name",
      header: "Название",
      skeletonWidth: "60%",
      render: (a) => (
        <>
          <div className="font-medium text-primary dark:text-primary-light">{a.name}</div>
          {a.description && (
            <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-1">
              {a.description}
            </div>
          )}
        </>
      ),
    },
    {
      key: "pipeline_name",
      header: "Воронка / Этап",
      skeletonWidth: "50%",
      render: (a) => (
        <>
          <div className="text-gray-900 dark:text-gray-200">{a.pipeline_name ?? `#${a.pipeline_id}`}</div>
          {a.stage_name && (
            <div className="text-xs text-gray-500 dark:text-gray-400">этап: {a.stage_name}</div>
          )}
        </>
      ),
    },
    {
      key: "trigger_kind",
      header: "Триггер",
      skeletonWidth: "6rem",
      render: (a) => <TriggerBadge kind={a.trigger_kind as AutomationTriggerKind} />,
    },
    {
      key: "action_kind",
      header: "Действие",
      skeletonWidth: "6rem",
      render: (a) => <ActionBadge kind={a.action_kind} />,
    },
    {
      key: "runs_count",
      header: "Запусков",
      align: "right",
      width: "7rem",
      skeletonWidth: "2rem",
      render: (a) =>
        a.runs_count > 0 ? (
          <button
            type="button"
            className="text-primary dark:text-primary-light hover:underline tabular-nums"
            onClick={(e) => {
              e.stopPropagation();
              router.push(`/admin/automations?tab=runs&automation_id=${a.id}`);
            }}
          >
            {a.runs_count}
          </button>
        ) : (
          <span className="text-gray-400 tabular-nums">0</span>
        ),
    },
  ];

  const hasFilters = Boolean(pipelineFilter || triggerFilter || activeFilter !== "all");

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="card rounded-2xl shadow-elev-1 lift p-4 flex flex-wrap items-end gap-3">
        <div className="min-w-[200px]">
          <label className="label">Воронка</label>
          <select
            className="input"
            value={pipelineFilter}
            onChange={(e) => setPipelineFilter(e.target.value)}
          >
            <option value="">Все воронки</option>
            {(pipelines ?? []).map((p) => (
              <option key={p.id} value={String(p.id)}>{p.name} ({p.kind})</option>
            ))}
          </select>
        </div>
        <div className="min-w-[200px]">
          <label className="label">Триггер</label>
          <select
            className="input"
            value={triggerFilter}
            onChange={(e) => setTriggerFilter(e.target.value)}
          >
            <option value="">Все триггеры</option>
            {TRIGGER_OPTIONS.map((o) => (
              <option key={o.value} value={o.value}>{o.label}</option>
            ))}
          </select>
        </div>
        <div className="min-w-[160px]">
          <label className="label">Состояние</label>
          <select
            className="input"
            value={activeFilter}
            onChange={(e) => setActiveFilter(e.target.value as ActiveFilter)}
          >
            <option value="all">Любое</option>
            <option value="active">Только активные</option>
            <option value="inactive">Только выключенные</option>
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
        rows={error ? [] : automations}
        getRowKey={(a) => a.id}
        onRowClick={(a) => router.push(`/admin/automations/${a.id}`)}
        rowActions={(a) => (
          <>
            <button
              type="button"
              className="btn-ghost text-xs px-2 py-1"
              onClick={(e) => {
                e.stopPropagation();
                setDryRunTarget(a);
              }}
              title="Dry-run"
            >
              <i className="bi bi-play-circle mr-1" />
              Dry-run
            </button>
            <button
              type="button"
              className="btn-ghost text-xs px-2 py-1"
              onClick={(e) => { e.stopPropagation(); router.push(`/admin/automations/${a.id}`); }}
              title="Редактировать"
            >
              <i className="bi bi-pencil" />
            </button>
          </>
        )}
        ariaLabel="Список автоматизаций"
        emptyIcon="bi-lightning-charge"
        emptyTitle="Автоматизаций пока нет"
        emptyText="Настройте триггеры и действия — система сама займётся рутиной"
        emptyCta={
          <Link href={newHref} className="btn-primary">
            <i className="bi bi-plus-lg mr-1" /> Создать первую
          </Link>
        }
        isError={!!error}
        errorText="Не удалось загрузить автоматизации"
        skeletonRows={5}
      />

      <div className="text-xs text-gray-500 dark:text-gray-400">
        Активный фильтр триггеров:{" "}
        {triggerFilter ? TRIGGER_LABELS[triggerFilter as AutomationTriggerKind] : "все"}
        {" • "}Всего: {automations?.length ?? 0}
      </div>

      {dryRunTarget && (
        <TestRunModal
          open
          onClose={() => setDryRunTarget(null)}
          automation={dryRunTarget}
        />
      )}
    </div>
  );
}
