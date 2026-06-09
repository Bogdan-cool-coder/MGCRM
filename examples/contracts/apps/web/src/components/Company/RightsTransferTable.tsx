"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { fetcher } from "@/lib/api";
import { UserSelect } from "@/components/UserSelect";
import { DatePicker } from "@/components/ui/DatePicker";
import { DataTable } from "@/components/ui/DataTable";
import type { DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";
import { RightsTransferViewModal } from "./RightsTransferViewModal";
import { RightsTransferRevertModal } from "./RightsTransferRevertModal";
import { type RightsTransfer, TRANSFER_CATEGORY_LABELS } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

function formatRelative(iso: string): string {
  const diff = Date.now() - new Date(iso).getTime();
  const minutes = Math.floor(diff / 60_000);
  if (minutes < 60) return `${minutes} мин. назад`;
  const hours = Math.floor(minutes / 60);
  if (hours < 24) return `${hours} ч. назад`;
  const days = Math.floor(hours / 24);
  return `${days} дн. назад`;
}

// Soft-badge helper
function TransferStatusBadge({ tr }: { tr: RightsTransfer }) {
  if (tr.reverted_at) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400">
        Откатан
      </span>
    );
  }
  if (tr.is_reversible) {
    return (
      <span className="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium bg-info/10 text-info dark:bg-info/20">
        Активен
      </span>
    );
  }
  return <span className="text-gray-400">—</span>;
}

export function RightsTransferTable() {
  const { toast } = useToast();
  const [fromUserId, setFromUserId] = useState("");
  const [toUserId, setToUserId] = useState("");
  const [since, setSince] = useState<string | null>(null);
  const [until, setUntil] = useState<string | null>(null);
  const [statusFilter, setStatusFilter] = useState("all");

  const [viewTransfer, setViewTransfer] = useState<RightsTransfer | null>(null);
  const [revertTransfer, setRevertTransfer] = useState<RightsTransfer | null>(null);

  const queryParams = new URLSearchParams();
  if (fromUserId) queryParams.set("from_user_id", fromUserId);
  if (toUserId) queryParams.set("to_user_id", toUserId);
  if (since) queryParams.set("after_date", since);
  if (until) queryParams.set("until", until);
  if (statusFilter !== "all") queryParams.set("status", statusFilter);

  const swrKey = `/admin/rights-transfers?${queryParams.toString()}`;
  const { data, error, isLoading } = useSWR<RightsTransfer[]>(swrKey, fetcher);

  function handleRevertSuccess() {
    toast.success("Передача откатана", "Записи восстановлены.");
    void mutate(swrKey);
  }

  const columns: DataTableColumn<RightsTransfer>[] = [
    {
      key: "users",
      header: "От → К",
      skeletonWidth: "65%",
      render: (tr) => (
        <div className="flex items-center gap-1.5 flex-wrap">
          <span className="font-medium text-gray-900 dark:text-gray-100">{tr.from_user_name}</span>
          <i className="bi bi-arrow-right text-gray-400 text-xs" />
          <span className="font-medium text-gray-900 dark:text-gray-100">{tr.to_user_name}</span>
        </div>
      ),
    },
    {
      key: "categories",
      header: "Категории",
      skeletonWidth: "50%",
      render: (tr) => (
        <div className="flex flex-wrap gap-1">
          {tr.categories.map((cat) => (
            <span
              key={cat}
              className="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium bg-info/10 text-info dark:bg-info/20"
            >
              {TRANSFER_CATEGORY_LABELS[cat]}
            </span>
          ))}
        </div>
      ),
    },
    {
      key: "reason",
      header: "Причина",
      skeletonWidth: "55%",
      render: (tr) => (
        <span
          className="block truncate text-gray-600 dark:text-gray-400 max-w-[160px]"
          title={tr.reason ?? ""}
        >
          {tr.reason ? (tr.reason.length > 55 ? tr.reason.slice(0, 55) + "…" : tr.reason) : "—"}
        </span>
      ),
    },
    {
      key: "initiator",
      header: "Инициатор",
      skeletonWidth: "50%",
      render: (tr) => (
        <div>
          <div className="font-medium text-gray-900 dark:text-gray-100">{tr.initiated_by_name ?? "—"}</div>
          {tr.initiated_by_role && (
            <div className="text-xs text-gray-400">{tr.initiated_by_role}</div>
          )}
        </div>
      ),
    },
    {
      key: "created_at",
      header: "Когда",
      skeletonWidth: "40%",
      render: (tr) => (
        <span
          className="text-gray-600 dark:text-gray-400 text-xs"
          title={formatDateTime(tr.created_at)}
        >
          {formatRelative(tr.created_at)}
        </span>
      ),
    },
    {
      key: "items_count",
      header: "Записей",
      align: "right",
      skeletonWidth: "30%",
      render: (tr) => (
        <span className="font-semibold tabular-nums text-primary">{tr.items_count}</span>
      ),
    },
    {
      key: "status",
      header: "Статус",
      skeletonWidth: "40%",
      render: (tr) => <TransferStatusBadge tr={tr} />,
    },
  ];

  const tableRows = isLoading ? undefined : (data ?? []);

  return (
    <div>
      {/* Filters */}
      <div className="flex flex-wrap items-end gap-3 mb-5">
        <div className="w-48">
          <label className="label text-xs">От</label>
          <UserSelect value={fromUserId} onChange={setFromUserId} placeholder="Любой отправитель" />
        </div>
        <div className="w-48">
          <label className="label text-xs">Кому</label>
          <UserSelect value={toUserId} onChange={setToUserId} placeholder="Любой получатель" />
        </div>
        <div className="w-40">
          <DatePicker
            label="С"
            value={since}
            onChange={setSince}
            clearable
            placeholder="Дата от"
          />
        </div>
        <div className="w-40">
          <DatePicker
            label="По"
            value={until}
            onChange={setUntil}
            clearable
            placeholder="Дата до"
            minDate={since ?? undefined}
          />
        </div>
        <div className="w-44">
          <label className="label text-xs">Статус</label>
          <select className="input" value={statusFilter} onChange={(e) => setStatusFilter(e.target.value)}>
            <option value="all">Все</option>
            <option value="reversible">Можно откатить</option>
            <option value="reverted">Откатано</option>
          </select>
        </div>
      </div>

      <DataTable<RightsTransfer>
        columns={columns}
        rows={tableRows}
        getRowKey={(tr) => tr.id}
        rowActions={(tr) => (
          <div className="flex items-center gap-1">
            <button
              type="button"
              className="btn-ghost text-xs py-1 px-2"
              onClick={() => setViewTransfer(tr)}
              title="Просмотреть детали"
            >
              <i className="bi bi-eye mr-1" />
              Смотреть
            </button>
            {tr.is_reversible && !tr.reverted_at && (
              <button
                type="button"
                className="btn-ghost text-danger text-xs py-1 px-2"
                onClick={() => setRevertTransfer(tr)}
                title="Откатить передачу"
              >
                Откатить
              </button>
            )}
          </div>
        )}
        isError={!!error}
        errorText="Не удалось загрузить журнал передач"
        emptyIcon="bi-arrow-left-right"
        emptyTitle="Передач прав пока нет"
        emptyText="Появятся после первого увольнения или ручной передачи"
        ariaLabel="Журнал передачи прав"
        skeletonRows={5}
        maxHeight="68vh"
      />

      <RightsTransferViewModal
        open={!!viewTransfer}
        transfer={viewTransfer}
        onClose={() => setViewTransfer(null)}
      />
      <RightsTransferRevertModal
        open={!!revertTransfer}
        transfer={revertTransfer}
        onClose={() => setRevertTransfer(null)}
        onSuccess={handleRevertSuccess}
      />
    </div>
  );
}
