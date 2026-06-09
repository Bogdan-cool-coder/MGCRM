"use client";

import { useState, useMemo, useCallback } from "react";
import { useRouter } from "next/navigation";
import Link from "next/link";
import useSWR, { mutate } from "swr";
import { PageHeader } from "@/components/PageHeader";
import { RequestStatusBadge } from "@/components/Finance/RequestStatusBadge";
import { RequestCreateModal } from "@/components/Finance/RequestCreateModal";
import { MoneyCell } from "@/components/Finance/MoneyCell";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { DatePicker } from "@/components/ui/DatePicker";
import { useToast } from "@/components/ui/Toast";
import { api, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { FinRequest, FinLegalEntity } from "@/lib/types";

const REQUEST_TYPE_LABELS: Record<string, string> = {
  salary: "Зарплата",
  commission: "Комиссия",
  expense_reimbursement: "Возмещение расходов",
  payment: "Платёж",
};

const STATUS_OPTIONS = [
  { value: "", label: "Все статусы" },
  { value: "draft", label: "Черновик" },
  { value: "submitted", label: "На согласовании" },
  { value: "approved", label: "Одобрено" },
  { value: "rejected", label: "Отклонено" },
  { value: "paid", label: "Оплачено" },
  { value: "cancelled", label: "Отменено" },
];

const TYPE_OPTIONS = [
  { value: "", label: "Все типы" },
  { value: "salary", label: "Зарплата" },
  { value: "commission", label: "Комиссия" },
  { value: "expense_reimbursement", label: "Возмещение расходов" },
  { value: "payment", label: "Платёж" },
];

export default function RequestsPage() {
  const { user } = useMe();
  const router = useRouter();
  const { toast } = useToast();
  const [createOpen, setCreateOpen] = useState(false);
  const [typeFilter, setTypeFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [entityFilter, setEntityFilter] = useState("");
  const [dateFrom, setDateFrom] = useState("");
  const [dateTo, setDateTo] = useState("");
  const [submittingId, setSubmittingId] = useState<number | null>(null);

  const { data: legalEntities } = useSWR<FinLegalEntity[]>("/api/finance/legal-entities", fetcher);

  const swrKey = useMemo(() => {
    const params = new URLSearchParams();
    if (statusFilter) params.set("status", statusFilter);
    if (entityFilter) params.set("legal_entity_id", entityFilter);
    if (typeFilter) params.set("request_type", typeFilter);
    const qs = params.toString();
    return `/api/finance/requests${qs ? `?${qs}` : ""}`;
  }, [statusFilter, entityFilter, typeFilter]);

  const { data: requests, isLoading, error } = useSWR<FinRequest[]>(swrKey, fetcher);

  const isManager = user?.role === "manager";
  const canSeeAll = user && ["accountant", "cfo", "director", "admin"].includes(user.role);

  // Client-side date filter on desired_date
  const filtered = useMemo(() => {
    if (!requests) return undefined;
    if (!dateFrom && !dateTo) return requests;
    return requests.filter((req) => {
      const d = req.desired_date;
      if (!d) return true;
      if (dateFrom && d < dateFrom) return false;
      if (dateTo && d > dateTo) return false;
      return true;
    });
  }, [requests, dateFrom, dateTo]);

  async function handleSubmit(req: FinRequest) {
    if (!confirm("Отправить заявку на согласование?")) return;
    setSubmittingId(req.id);
    try {
      await api(`/api/finance/requests/${req.id}/submit`, { method: "POST" });
      await mutate(swrKey);
      toast.success("Заявка отправлена на согласование");
    } catch (err) {
      toast.error(err instanceof Error ? err.message : "Не удалось отправить заявку");
    } finally {
      setSubmittingId(null);
    }
  }

  const columns = useMemo<DataTableColumn<FinRequest>[]>(() => [
    {
      key: "number",
      header: "№",
      width: "8rem",
      render: (req) => (
        <span className="font-mono text-xs text-gray-500 dark:text-gray-400">
          {req.number ?? `#${req.id}`}
        </span>
      ),
      skeletonWidth: "4rem",
    },
    {
      key: "request_type",
      header: "Тип",
      render: (req) => (
        <span className="text-gray-700 dark:text-gray-200">
          {REQUEST_TYPE_LABELS[req.request_type] ?? req.request_type}
        </span>
      ),
    },
    {
      key: "amount",
      header: "Сумма",
      align: "right",
      width: "10rem",
      render: (req) => (
        <MoneyCell amount={req.amount} currency={req.currency} direction="out" />
      ),
      skeletonWidth: "6rem",
    },
    {
      key: "desired_date",
      header: "Желаемая дата",
      width: "9rem",
      render: (req) => (
        <span className="text-gray-500 dark:text-gray-400 tabular-nums">
          {req.desired_date ? new Date(req.desired_date).toLocaleDateString("ru-RU") : "—"}
        </span>
      ),
      skeletonWidth: "5rem",
    },
    {
      key: "status",
      header: "Статус",
      width: "10rem",
      render: (req) => <RequestStatusBadge status={req.status} />,
      skeletonWidth: "6rem",
    },
    ...(canSeeAll
      ? [{
          key: "requester_user_id",
          header: "Заявитель",
          width: "8rem",
          render: (req: FinRequest) => (
            <span className="text-gray-500 dark:text-gray-400 text-xs">
              {req.requester_user_id ?? "—"}
            </span>
          ),
          skeletonWidth: "4rem",
        }]
      : []),
  ], [canSeeAll]);

  const handleRowClick = useCallback(
    (req: FinRequest) => router.push(`/finance/requests/${req.id}`),
    [router]
  );

  const rowActions = useCallback((req: FinRequest) => (
    <>
      {req.status === "draft" &&
        (isManager || req.requester_user_id === user?.id) && (
          <button
            type="button"
            className="btn-ghost text-primary text-sm px-2 py-1"
            disabled={submittingId === req.id}
            onClick={(e) => { e.stopPropagation(); handleSubmit(req); }}
            title="Отправить на согласование"
          >
            {submittingId === req.id ? (
              <i className="bi bi-hourglass text-xs" />
            ) : (
              <i className="bi bi-send" />
            )}
          </button>
        )}
      <Link
        href={`/finance/requests/${req.id}`}
        className="btn-ghost text-sm px-2 py-1"
        title="Открыть"
        onClick={(e) => e.stopPropagation()}
      >
        <i className="bi bi-eye" />
      </Link>
    </>
  ), [isManager, user?.id, submittingId, handleSubmit]);

  return (
    <div className="flex flex-col h-full">
      <PageHeader
        title="Заявки"
        actions={
          <button type="button" className="btn-primary" onClick={() => setCreateOpen(true)}>
            <i className="bi bi-plus mr-1" />
            Создать заявку
          </button>
        }
      />

      <div className="p-6 flex-1 overflow-auto space-y-4">
        {/* Фильтры */}
        <div className="card p-4">
          <div className="flex flex-wrap gap-3">
            <select
              className="input text-sm w-auto"
              value={typeFilter}
              onChange={(e) => setTypeFilter(e.target.value)}
            >
              {TYPE_OPTIONS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>

            <select
              className="input text-sm w-auto"
              value={statusFilter}
              onChange={(e) => setStatusFilter(e.target.value)}
            >
              {STATUS_OPTIONS.map((o) => (
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

            <DatePicker
              value={dateFrom || null}
              onChange={(v) => setDateFrom(v ?? "")}
              placeholder="Дата с"
              className="w-auto"
            />
            <DatePicker
              value={dateTo || null}
              onChange={(v) => setDateTo(v ?? "")}
              placeholder="Дата по"
              className="w-auto"
            />

            {(typeFilter || statusFilter || entityFilter || dateFrom || dateTo) && (
              <button
                className="btn-ghost text-sm text-gray-500"
                onClick={() => {
                  setTypeFilter("");
                  setStatusFilter("");
                  setEntityFilter("");
                  setDateFrom("");
                  setDateTo("");
                }}
              >
                <i className="bi bi-x mr-1" />
                Сбросить
              </button>
            )}
          </div>
        </div>

        <DataTable
          columns={columns}
          rows={isLoading ? undefined : filtered}
          getRowKey={(req) => req.id}
          onRowClick={handleRowClick}
          rowActions={rowActions}
          isError={!!error}
          errorText="Не удалось загрузить заявки"
          emptyIcon="bi-file-earmark-text"
          emptyTitle="Заявок пока нет"
          emptyText="Создайте первую заявку, чтобы запустить процесс согласования"
          emptyCta={
            <button type="button" className="btn-primary" onClick={() => setCreateOpen(true)}>
              Создать заявку
            </button>
          }
          ariaLabel="Список заявок"
          skeletonRows={7}
        />
      </div>

      <RequestCreateModal open={createOpen} onClose={() => setCreateOpen(false)} />
    </div>
  );
}
