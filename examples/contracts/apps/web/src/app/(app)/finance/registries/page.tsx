"use client";

import { useState, useMemo } from "react";
import { useRouter } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RegistryApprovalBadge, RegistryPaymentBadge } from "@/components/Finance/RegistryStatusBadge";
import { RegistryCreateModal } from "@/components/Finance/RegistryCreateModal";
import { RoleGate } from "@/components/RoleGate";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { fetcher } from "@/lib/api";
import type { FinRegistry, FinLegalEntity, FinMoneyAccount } from "@/lib/types";

const APPROVAL_STATUS_OPTIONS = [
  { value: "", label: "Все статусы согл." },
  { value: "draft", label: "Черновик" },
  { value: "on_review", label: "На согласовании" },
  { value: "approved", label: "Одобрено" },
  { value: "rejected", label: "Отклонено" },
];

const PAYMENT_STATUS_OPTIONS = [
  { value: "", label: "Все статусы оплаты" },
  { value: "new", label: "Не оплачен" },
  { value: "partial", label: "Частично" },
  { value: "paid", label: "Оплачен" },
];

export default function RegistriesPage() {
  const router = useRouter();
  const [createOpen, setCreateOpen] = useState(false);
  const [approvalStatusFilter, setApprovalStatusFilter] = useState("");
  const [paymentStatusFilter, setPaymentStatusFilter] = useState("");
  const [entityFilter, setEntityFilter] = useState("");

  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);
  const { data: accounts } = useSWR<FinMoneyAccount[]>("/api/finance/money-accounts", fetcher);

  const swrKey = useMemo(() => {
    const params = new URLSearchParams();
    if (approvalStatusFilter) params.set("approval_status", approvalStatusFilter);
    if (entityFilter) params.set("legal_entity_id", entityFilter);
    const qs = params.toString();
    return `/api/finance/registries${qs ? `?${qs}` : ""}`;
  }, [approvalStatusFilter, entityFilter]);

  const { data: registries, isLoading, error } = useSWR<FinRegistry[]>(swrKey, fetcher);

  // client-side payment status filter (backend supports approval_status but not payment_status in list)
  const filtered = useMemo(() => {
    if (!registries) return registries; // undefined = loading
    return registries.filter((r) =>
      paymentStatusFilter ? r.payment_status === paymentStatusFilter : true
    );
  }, [registries, paymentStatusFilter]);

  const accountName = (id: number) =>
    accounts?.find((a) => a.id === id)?.name ?? `#${id}`;

  const columns: DataTableColumn<FinRegistry>[] = [
    {
      key: "number",
      header: "№",
      width: "6%",
      skeletonWidth: "60%",
      render: (r) => (
        <span className="font-mono text-xs text-gray-500 dark:text-gray-400">
          {r.number ?? `#${r.id}`}
        </span>
      ),
    },
    {
      key: "registry_date",
      header: "Дата",
      width: "10%",
      skeletonWidth: "70%",
      render: (r) => (
        <span className="text-gray-500 dark:text-gray-400">
          {new Date(r.registry_date).toLocaleDateString("ru-RU")}
        </span>
      ),
    },
    {
      key: "source_account_id",
      header: "Счёт",
      width: "18%",
      skeletonWidth: "80%",
      render: (r) => accountName(r.source_account_id),
    },
    {
      key: "title",
      header: "Название",
      skeletonWidth: "85%",
      render: (r) => (
        <span className="text-gray-600 dark:text-gray-300 max-w-[200px] truncate block">
          {r.title ?? "—"}
        </span>
      ),
    },
    {
      key: "total_amount",
      header: "Сумма",
      width: "10%",
      align: "right",
      skeletonWidth: "60%",
      render: () => (
        // total_amount only in RegistryDetailOut — show dash in listing
        <span className="text-gray-400 dark:text-gray-500 text-xs">—</span>
      ),
    },
    {
      key: "approval_status",
      header: "Согласование",
      width: "14%",
      skeletonWidth: "75%",
      render: (r) => <RegistryApprovalBadge status={r.approval_status} />,
    },
    {
      key: "payment_status",
      header: "Оплата",
      width: "12%",
      skeletonWidth: "65%",
      render: (r) => <RegistryPaymentBadge status={r.payment_status} />,
    },
  ];

  return (
    <RoleGate allowed={["accountant", "cfo", "admin"]} fallback={
      <div className="p-8 text-gray-400 dark:text-gray-500">Нет доступа к реестрам платежей</div>
    }>
      <div className="flex flex-col h-full">
        <PageHeader
          title="Реестры платежей"
          actions={
            <button type="button" className="btn-primary" onClick={() => setCreateOpen(true)}>
              <i className="bi bi-plus mr-1" />
              Новый реестр
            </button>
          }
        />

        <div className="p-6 flex-1 overflow-y-auto space-y-4">
          {/* Filters */}
          <div className="card p-4">
            <div className="flex flex-wrap gap-3">
              <select
                className="input text-sm w-auto"
                value={approvalStatusFilter}
                onChange={(e) => setApprovalStatusFilter(e.target.value)}
              >
                {APPROVAL_STATUS_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>

              <select
                className="input text-sm w-auto"
                value={paymentStatusFilter}
                onChange={(e) => setPaymentStatusFilter(e.target.value)}
              >
                {PAYMENT_STATUS_OPTIONS.map((o) => (
                  <option key={o.value} value={o.value}>{o.label}</option>
                ))}
              </select>

              <select
                className="input text-sm w-auto"
                value={entityFilter}
                onChange={(e) => setEntityFilter(e.target.value)}
              >
                <option value="">Все юрлица</option>
                {(legalEntities ?? []).map((le) => (
                  <option key={le.id} value={String(le.id)}>{le.name}</option>
                ))}
              </select>
            </div>
          </div>

          <DataTable<FinRegistry>
            columns={columns}
            rows={error ? [] : (isLoading ? undefined : filtered)}
            getRowKey={(r) => r.id}
            onRowClick={(r) => router.push(`/finance/registries/${r.id}`)}
            isError={!!error}
            errorText="Не удалось загрузить реестры"
            emptyIcon="bi-list-check"
            emptyTitle="Реестров пока нет"
            emptyText="Создай реестр, чтобы собрать расходные операции для проведения"
            emptyCta={
              <button type="button" className="btn-primary" onClick={() => setCreateOpen(true)}>
                Новый реестр
              </button>
            }
            skeletonRows={5}
            ariaLabel="Реестры платежей"
          />
        </div>

        <RegistryCreateModal open={createOpen} onClose={() => setCreateOpen(false)} />
      </div>
    </RoleGate>
  );
}
