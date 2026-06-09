"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { fetcher, api, ApiError } from "@/lib/api";
import type { UserVacation } from "@/lib/types";
import { VacationStatusBadge, VacationTypeBadge } from "./VacationStatusBadge";
import { VacationCreateModal } from "./VacationCreateModal";
import { DataTable } from "@/components/ui/DataTable";
import type { DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";
import { useMe } from "@/lib/auth";

function formatPeriod(start: string, end: string): string {
  const s = new Date(start);
  const e = new Date(end);
  const fmt = (d: Date) =>
    d.toLocaleDateString("ru-RU", { day: "numeric", month: "short" });
  const fmtFull = (d: Date) =>
    d.toLocaleDateString("ru-RU", { day: "numeric", month: "short", year: "numeric" });

  if (s.getFullYear() === e.getFullYear()) {
    return `${fmt(s)} — ${fmtFull(e)}`;
  }
  return `${fmtFull(s)} — ${fmtFull(e)}`;
}

interface Props {
  userId?: number;
}

export function VacationsList({ userId }: Props) {
  const { user } = useMe();
  const { toast } = useToast();
  const [createOpen, setCreateOpen] = useState(false);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const swrKey = userId ? `/admin/vacations?user_id=${userId}` : "/me/vacations";
  const { data, error, isLoading } = useSWR<UserVacation[]>(swrKey, fetcher);

  const isAdminView = !!userId && user && (user.role === "admin" || user.role === "director");

  async function handleDelete(id: number) {
    if (!confirm("Удалить заявку на отпуск?")) return;
    setDeletingId(id);
    try {
      await api(`/me/vacations/${id}`, { method: "DELETE" });
      await mutate(swrKey);
      toast.success("Заявка удалена");
    } catch (err) {
      toast.error(
        "Не удалось удалить",
        err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Что-то пошло не так."
      );
    } finally {
      setDeletingId(null);
    }
  }

  async function handleApprove(id: number) {
    try {
      await api(`/admin/vacations/${id}/approve`, { method: "PATCH" });
      await mutate(swrKey);
      toast.success("Отпуск одобрен");
    } catch (err) {
      toast.error(
        "Ошибка",
        err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Не удалось одобрить"
      );
    }
  }

  async function handleReject(id: number) {
    try {
      await api(`/admin/vacations/${id}/reject`, { method: "PATCH" });
      await mutate(swrKey);
      toast.warning("Отпуск отклонён");
    } catch (err) {
      toast.error(
        "Ошибка",
        err instanceof ApiError ? String((err.detail as Record<string, unknown>)?.detail ?? err.message) : "Не удалось отклонить"
      );
    }
  }

  const columns: DataTableColumn<UserVacation>[] = [
    {
      key: "period",
      header: "Период",
      skeletonWidth: "65%",
      render: (v) => (
        <span className="font-medium text-gray-900 dark:text-gray-100 tabular-nums">
          {formatPeriod(v.start_date, v.end_date)}
        </span>
      ),
    },
    {
      key: "vacation_type",
      header: "Тип",
      skeletonWidth: "40%",
      render: (v) => <VacationTypeBadge type={v.vacation_type} />,
    },
    {
      key: "substitute_name",
      header: "Замещающий",
      skeletonWidth: "50%",
      render: (v) => (
        <span className="text-gray-600 dark:text-gray-400">{v.substitute_name ?? "—"}</span>
      ),
    },
    {
      key: "status",
      header: "Статус",
      skeletonWidth: "45%",
      render: (v) => <VacationStatusBadge status={v.status} />,
    },
  ];

  const tableRows = isLoading ? undefined : (data ?? []);

  return (
    <div>
      {!userId && (
        <div className="flex justify-end mb-4">
          <button className="btn-primary" onClick={() => setCreateOpen(true)}>
            <i className="bi bi-plus-lg mr-1" />
            Новый отпуск
          </button>
        </div>
      )}

      <DataTable<UserVacation>
        columns={columns}
        rows={tableRows}
        getRowKey={(v) => v.id}
        rowActions={(v) => (
          <div className="flex items-center gap-1">
            {isAdminView && v.status === "pending_approval" && (
              <>
                <button
                  type="button"
                  className="btn-ghost text-success text-xs py-1 px-2"
                  onClick={() => handleApprove(v.id)}
                >
                  Одобрить
                </button>
                <button
                  type="button"
                  className="btn-ghost text-danger text-xs py-1 px-2"
                  onClick={() => handleReject(v.id)}
                >
                  Отклонить
                </button>
              </>
            )}
            {!userId && v.status === "pending_approval" && (
              <button
                type="button"
                className="btn-ghost p-1"
                onClick={() => handleDelete(v.id)}
                disabled={deletingId === v.id}
                title="Удалить"
                aria-label="Удалить заявку"
              >
                <i className="bi bi-trash text-danger text-sm" />
              </button>
            )}
          </div>
        )}
        isError={!!error}
        errorText="Не удалось загрузить список отпусков"
        emptyIcon="bi-airplane"
        emptyTitle="Отпусков пока нет"
        emptyText="Подай заявку — она уйдёт на одобрение руководителю"
        emptyCta={
          !userId ? (
            <button className="btn-primary" onClick={() => setCreateOpen(true)}>
              Подать заявку
            </button>
          ) : undefined
        }
        ariaLabel="Список отпусков"
        skeletonRows={4}
      />

      <VacationCreateModal
        open={createOpen}
        onClose={() => setCreateOpen(false)}
        onSuccess={() => mutate(swrKey)}
      />
    </div>
  );
}
