"use client";

import { useState, useMemo, useCallback } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { ApprovalScenarioEditor } from "@/components/Finance/ApprovalScenarioEditor";
import { RoleGate } from "@/components/RoleGate";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { fetcher } from "@/lib/api";
import type { FinApprovalScenario, FinLegalEntity, FinOpType } from "@/lib/types";

const APPLIES_TO_LABELS: Record<string, string> = {
  operation: "Операция",
  registry: "Реестр",
  request: "Заявка",
  invoice: "Счёт",
};

const FILTER_OPTIONS = [
  { value: "", label: "Все типы" },
  { value: "operation", label: "Операция" },
  { value: "registry", label: "Реестр" },
  { value: "request", label: "Заявка" },
  { value: "invoice", label: "Счёт" },
];

export default function ApprovalScenariosPage() {
  const [appliesToFilter, setAppliesToFilter] = useState("");
  const [editorOpen, setEditorOpen] = useState(false);
  const [editScenario, setEditScenario] = useState<FinApprovalScenario | null>(null);

  const swrKey = `/api/finance/approval-scenarios${appliesToFilter ? `?applies_to=${appliesToFilter}` : ""}`;
  const { data: scenarios, isLoading, error } = useSWR<FinApprovalScenario[]>(swrKey, fetcher);
  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: opTypes } = useSWR<FinOpType[]>("/api/finance/op-types", fetcher);

  const entityName = (id: number | null) =>
    id ? (legalEntities?.find((le) => le.id === id)?.name ?? `#${id}`) : "—";
  const opTypeName = (id: number | null) =>
    id ? (opTypes?.find((t) => t.id === id)?.name ?? `#${id}`) : "—";

  function openCreate() {
    setEditScenario(null);
    setEditorOpen(true);
  }

  function openEdit(sc: FinApprovalScenario) {
    setEditScenario(sc);
    setEditorOpen(true);
  }

  function handleClose() {
    setEditorOpen(false);
    setEditScenario(null);
  }

  const columns = useMemo<DataTableColumn<FinApprovalScenario>[]>(() => [
    {
      key: "name",
      header: "Название",
      render: (sc) => (
        <span className="font-medium text-gray-800 dark:text-gray-100">{sc.name}</span>
      ),
    },
    {
      key: "applies_to",
      header: "Тип объекта",
      width: "9rem",
      render: (sc) => (
        <span className="text-gray-600 dark:text-gray-300">
          {APPLIES_TO_LABELS[sc.applies_to] ?? sc.applies_to}
        </span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "op_type_id",
      header: "Тип операции",
      width: "12rem",
      render: (sc) => (
        <span className="text-gray-500 dark:text-gray-400">{opTypeName(sc.op_type_id)}</span>
      ),
      skeletonWidth: "7rem",
    },
    {
      key: "legal_entity_id",
      header: "Юрлицо",
      width: "10rem",
      render: (sc) => (
        <span className="text-gray-500 dark:text-gray-400">{entityName(sc.legal_entity_id)}</span>
      ),
      skeletonWidth: "7rem",
    },
    {
      key: "min_amount",
      header: "Диапазон сумм",
      width: "10rem",
      render: (sc) => (
        <span className="tabular-nums text-gray-500 dark:text-gray-400">
          {sc.min_amount || sc.max_amount
            ? `${sc.min_amount ?? 0} — ${sc.max_amount ?? "∞"}`
            : "—"}
        </span>
      ),
      skeletonWidth: "6rem",
    },
    {
      key: "priority",
      header: "Приоритет",
      align: "center",
      width: "7rem",
      render: (sc) => (
        <span className="tabular-nums text-gray-600 dark:text-gray-300 font-mono text-xs">
          {sc.priority}
        </span>
      ),
      skeletonWidth: "2rem",
    },
    {
      key: "is_active",
      header: "Статус",
      width: "7rem",
      render: (sc) =>
        sc.is_active ? (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-success/10 text-success">
            <span className="w-1.5 h-1.5 rounded-full bg-current opacity-70" />
            Активен
          </span>
        ) : (
          <span className="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-400 dark:bg-gray-700/60 dark:text-gray-500">
            Архив
          </span>
        ),
      skeletonWidth: "4rem",
    },
  ], [opTypeName, entityName]);

  const rowActions = useCallback((sc: FinApprovalScenario) => (
    <button
      type="button"
      className="btn-ghost text-xs px-2 py-1"
      onClick={(e) => { e.stopPropagation(); openEdit(sc); }}
      title="Редактировать"
    >
      <i className="bi bi-pencil" />
    </button>
  ), [openEdit]);

  return (
    <RoleGate
      allowed={["cfo", "admin"]}
      fallback={
        <div className="p-8 text-gray-400 dark:text-gray-500">
          Нет доступа к сценариям согласования
        </div>
      }
    >
      <div className="flex flex-col h-full">
        <PageHeader
          title="Сценарии согласования"
          actions={
            <button type="button" className="btn-primary" onClick={openCreate}>
              <i className="bi bi-plus mr-1" />
              Создать сценарий
            </button>
          }
        />

        <div className="p-6 flex-1 overflow-auto space-y-4">
          {/* Фильтр */}
          <div className="card p-4">
            <select
              className="input text-sm w-auto"
              value={appliesToFilter}
              onChange={(e) => setAppliesToFilter(e.target.value)}
            >
              {FILTER_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>

          <DataTable
            columns={columns}
            rows={isLoading ? undefined : (scenarios ?? [])}
            getRowKey={(sc) => sc.id}
            rowActions={rowActions}
            isError={!!error}
            errorText="Не удалось загрузить сценарии"
            emptyIcon="bi-diagram-3"
            emptyTitle="Сценарии не настроены"
            emptyText="Без сценария согласование невозможно — создайте хотя бы один"
            emptyCta={
              <button type="button" className="btn-primary" onClick={openCreate}>
                Создать сценарий
              </button>
            }
            ariaLabel="Сценарии согласования"
            skeletonRows={6}
          />
        </div>

        <ApprovalScenarioEditor
          open={editorOpen}
          scenario={editScenario}
          onClose={handleClose}
        />
      </div>
    </RoleGate>
  );
}
