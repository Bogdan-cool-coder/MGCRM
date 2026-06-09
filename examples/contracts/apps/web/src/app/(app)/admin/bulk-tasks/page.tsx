"use client";

/** Реестр Bulk-задач (Эпик 6 MVP) — статусы, прогресс, скачивание архивов.
 *
 *  Видят: admin, director, lawyer.
 *  Менеджеры в MVP не работают с bulk — фильтр backend (manager → только свои).
 *
 *  Auto-refresh — раз в 5 сек, пока есть pending/running задачи.
 *  Highlight ?highlight=42 — подсветка строки на 3 сек после редиректа из модалки.
 */
import { useEffect, useMemo, useState } from "react";
import { useSearchParams } from "next/navigation";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { BulkTaskRow } from "@/components/BulkTasks/BulkTaskRow";
import { EmptyState } from "@/components/EmptyState";
import { Modal } from "@/components/Modal";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import {
  BULK_STATUS_LABELS,
  type BulkTask,
  type BulkTaskStatus,
  type User,
} from "@/lib/types";

const STATUS_OPTIONS: { value: "" | BulkTaskStatus; label: string }[] = [
  { value: "", label: "Любой статус" },
  { value: "pending", label: BULK_STATUS_LABELS.pending },
  { value: "running", label: BULK_STATUS_LABELS.running },
  { value: "success", label: BULK_STATUS_LABELS.success },
  { value: "failed", label: BULK_STATUS_LABELS.failed },
  { value: "cancelled", label: BULK_STATUS_LABELS.cancelled },
];

interface ConfirmAction {
  type: "cancel" | "delete";
  task: BulkTask;
}

export default function BulkTasksPage() {
  const { user } = useMe();
  const searchParams = useSearchParams();
  const highlightIdRaw = searchParams.get("highlight");
  const highlightId = highlightIdRaw ? Number(highlightIdRaw) : null;
  const { toast } = useToast();

  const [statusFilter, setStatusFilter] = useState<"" | BulkTaskStatus>("");
  const [mine, setMine] = useState(false);
  const [confirmAction, setConfirmAction] = useState<ConfirmAction | null>(null);
  const [acting, setActing] = useState(false);

  const query = useMemo(() => {
    const qs = new URLSearchParams();
    if (statusFilter) qs.set("status", statusFilter);
    if (mine) qs.set("mine", "true");
    qs.set("limit", "200");
    const s = qs.toString();
    return s ? `?${s}` : "";
  }, [statusFilter, mine]);

  const { data: tasks, mutate, isLoading } = useSWR<BulkTask[]>(
    `/bulk-tasks${query}`,
    fetcher,
    {
      refreshInterval: (latest) => {
        if (!latest) return 5000;
        const hasActive = latest.some(
          (t) => t.status === "pending" || t.status === "running",
        );
        return hasActive ? 5000 : 0;
      },
    },
  );

  const { data: users } = useSWR<User[]>("/users", fetcher);
  const usersById = useMemo(() => {
    const m = new Map<number, string>();
    (users ?? []).forEach((u) => m.set(u.id, u.full_name));
    return m;
  }, [users]);

  const [highlighted, setHighlighted] = useState<number | null>(highlightId);
  useEffect(() => {
    if (!highlightId) return;
    setHighlighted(highlightId);
    const t = setTimeout(() => setHighlighted(null), 3000);
    const sc = setTimeout(() => {
      const el = document.querySelector(`tr[data-task-id="${highlightId}"]`);
      if (el && "scrollIntoView" in el) {
        (el as HTMLElement).scrollIntoView({ behavior: "smooth", block: "center" });
      }
    }, 100);
    return () => { clearTimeout(t); clearTimeout(sc); };
  }, [highlightId]);

  function download(task: BulkTask) {
    window.location.href = `/api/bulk-tasks/${task.id}/download`;
  }

  function openCancel(task: BulkTask) { setConfirmAction({ type: "cancel", task }); }
  function openDelete(task: BulkTask) { setConfirmAction({ type: "delete", task }); }

  async function handleConfirmAction() {
    if (!confirmAction) return;
    setActing(true);
    const { type, task } = confirmAction;
    try {
      await api(`/bulk-tasks/${task.id}`, { method: "DELETE" });
      await mutate();
      setConfirmAction(null);
      toast.success(type === "cancel" ? "Задача отменена" : "Запись удалена");
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : (type === "cancel" ? "Не удалось отменить" : "Не удалось удалить");
      toast.error(msg);
    } finally {
      setActing(false);
    }
  }

  const canSeeMineFilter = user?.role && user.role !== "manager";
  const activeCount = (tasks ?? []).filter(
    (t) => t.status === "pending" || t.status === "running",
  ).length;

  return (
    <>
      <PageHeader
        title="Bulk-задачи"
        description={
          activeCount > 0
            ? `Массовая генерация документов · в работе: ${activeCount}`
            : "Массовая генерация документов"
        }
      />

      <div className="px-8 pt-4 flex flex-wrap items-center gap-2">
        <select
          className="input w-44"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value as "" | BulkTaskStatus)}
        >
          {STATUS_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
        {canSeeMineFilter && (
          <label className="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300 px-2 cursor-pointer">
            <input
              type="checkbox"
              checked={mine}
              onChange={(e) => setMine(e.target.checked)}
              className="cursor-pointer"
            />
            Только мои
          </label>
        )}
      </div>

      <div className="p-8 pt-4">
        {/* Skeleton */}
        {isLoading && !tasks && (
          <div className="card rounded-2xl overflow-hidden animate-pulse">
            <div className="h-10 bg-gray-50 dark:bg-gray-800 border-b border-gray-200 dark:border-gray-700" />
            {[1, 2, 3].map((i) => (
              <div key={i} className="flex items-center gap-4 px-4 py-3 border-b border-gray-100 dark:border-gray-800">
                <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-8" />
                <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-1/3" />
                <div className="h-4 bg-gray-200 dark:bg-gray-700 rounded w-20" />
                <div className="h-2 bg-gray-200 dark:bg-gray-700 rounded flex-1" />
              </div>
            ))}
          </div>
        )}

        {/* Empty */}
        {!isLoading && (!tasks || tasks.length === 0) && (
          <div className="card rounded-2xl">
            <EmptyState
              icon="bi-archive"
              title="Задач пока нет"
              description="Запустите массовую генерацию из разделов «Контрагенты» или «Реестр клиентов»."
            />
          </div>
        )}

        {/* Table */}
        {tasks && tasks.length > 0 && (
          <div className="card rounded-2xl overflow-hidden shadow-elev-1">
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead className="bg-white dark:bg-gray-900 border-b border-gray-200 dark:border-gray-700 sticky top-0 z-10">
                  <tr>
                    <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-14">№</th>
                    <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Тип / шаблон</th>
                    <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-32">Статус</th>
                    <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">Прогресс</th>
                    <th className="text-left px-3 py-2.5 text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400 w-40">Создана</th>
                    <th className="text-right px-3 py-2.5 w-28" />
                  </tr>
                </thead>
                <tbody className="divide-y divide-gray-100 dark:divide-gray-800">
                  {tasks.map((t) => (
                    <BulkTaskRow
                      key={t.id}
                      task={t}
                      authorName={t.created_by_user_id ? usersById.get(t.created_by_user_id) : undefined}
                      isHighlighted={highlighted === t.id}
                      onDownload={download}
                      onCancel={openCancel}
                      onDelete={openDelete}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          </div>
        )}
      </div>

      {/* Confirm modal — cancel or delete */}
      <Modal
        open={confirmAction !== null}
        onClose={() => setConfirmAction(null)}
        title={confirmAction?.type === "cancel" ? "Отменить задачу?" : "Удалить запись?"}
        width="sm"
        footer={
          <>
            <button className="btn-ghost" onClick={() => setConfirmAction(null)} disabled={acting}>
              Нет
            </button>
            <button
              className={confirmAction?.type === "cancel" ? "btn-secondary text-danger" : "btn-secondary text-danger"}
              onClick={handleConfirmAction}
              disabled={acting}
            >
              <i className={`bi ${confirmAction?.type === "cancel" ? "bi-x-circle" : "bi-trash"} mr-1`} />
              {acting ? "Выполняем…" : confirmAction?.type === "cancel" ? "Отменить" : "Удалить"}
            </button>
          </>
        }
      >
        {confirmAction?.type === "cancel" ? (
          <p className="text-sm text-gray-700 dark:text-gray-300">
            Задача #{confirmAction.task.id} будет отменена. Уже начатые рендеры завершатся, но архив не будет сформирован.
          </p>
        ) : (
          <p className="text-sm text-gray-700 dark:text-gray-300">
            Запись о задаче #{confirmAction?.task.id} будет удалена. Архив на диске удалится по расписанию.
          </p>
        )}
      </Modal>
    </>
  );
}
