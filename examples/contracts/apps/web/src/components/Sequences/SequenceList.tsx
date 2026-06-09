"use client";

import { useState } from "react";
import useSWR, { mutate } from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Sequence } from "@/lib/types";
import { Modal } from "@/components/Modal";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";
import { SequenceFormModal } from "./SequenceFormModal";

interface StartRunState {
  sequenceId: number;
  targetType: "lead" | "deal" | "subscription";
  targetId: string;
  submitting: boolean;
  error: string | null;
  success: boolean;
}

const EMPTY_START: StartRunState = {
  sequenceId: 0,
  targetType: "lead",
  targetId: "",
  submitting: false,
  error: null,
  success: false,
};

function StatusBadge({ isActive }: { isActive: boolean }) {
  return (
    <span
      className={
        "inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium " +
        (isActive
          ? "bg-success-50 text-success-700 dark:bg-success-500/10 dark:text-success-400"
          : "bg-gray-100 text-gray-600 dark:bg-gray-700 dark:text-gray-400")
      }
    >
      <span
        className={
          "w-1.5 h-1.5 rounded-full " + (isActive ? "bg-success-500" : "bg-gray-400")
        }
      />
      {isActive ? "Активна" : "Выключена"}
    </span>
  );
}

/** Список последовательностей с фильтрами, skeleton-loading, empty-state */
export function SequenceList() {
  const { toast } = useToast();
  const [search, setSearch] = useState("");
  const [statusFilter, setStatusFilter] = useState<"" | "active" | "inactive">("");
  const [editSequence, setEditSequence] = useState<Sequence | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [deleteId, setDeleteId] = useState<number | null>(null);
  const [deleteSeqName, setDeleteSeqName] = useState<string>("");
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);
  const [startRun, setStartRun] = useState<StartRunState | null>(null);

  // Build query
  const params = new URLSearchParams();
  if (search.trim()) params.set("q", search.trim());
  if (statusFilter === "active") params.set("is_active", "true");
  if (statusFilter === "inactive") params.set("is_active", "false");
  const swrKey = `/sequences?${params.toString()}`;

  const { data: sequences, isLoading, error } = useSWR<Sequence[]>(swrKey, fetcher);
  const list = sequences ?? [];

  function openCreate() {
    setEditSequence(null);
    setFormOpen(true);
  }

  function openEdit(seq: Sequence) {
    setEditSequence(seq);
    setFormOpen(true);
  }

  function closeForm() {
    setFormOpen(false);
    setEditSequence(null);
  }

  function resetFilters() {
    setSearch("");
    setStatusFilter("");
  }

  async function confirmDelete(id: number) {
    setDeletingId(id);
    setDeleteError(null);
    try {
      await api(`/sequences/${id}`, { method: "DELETE" });
      await mutate((key: unknown) => typeof key === "string" && key.startsWith("/sequences"));
      setDeleteId(null);
      toast.success("Последовательность удалена");
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить";
      setDeleteError(msg);
      toast.error(msg);
    } finally {
      setDeletingId(null);
    }
  }

  async function submitStartRun(e: React.FormEvent) {
    e.preventDefault();
    if (!startRun) return;
    setStartRun((s) => s ? { ...s, submitting: true, error: null } : s);
    try {
      await api(`/sequences/${startRun.sequenceId}/start`, {
        method: "POST",
        body: {
          target_type: startRun.targetType,
          target_id: Number(startRun.targetId),
        },
      });
      setStartRun((s) => s ? { ...s, success: true, submitting: false } : s);
      toast.success("Последовательность запущена");
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось запустить";
      setStartRun((s) => s ? { ...s, error: msg, submitting: false } : s);
    }
  }

  const columns: DataTableColumn<Sequence>[] = [
    {
      key: "name",
      header: "Название",
      skeletonWidth: "55%",
      render: (seq) => {
        const stepsCount =
          seq.steps_count !== undefined
            ? seq.steps_count
            : Array.isArray(seq.steps_json)
            ? seq.steps_json.length
            : 0;
        return (
          <>
            <div className="font-medium text-primary dark:text-primary-light">{seq.name}</div>
            {seq.description && (
              <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5 line-clamp-1">
                {seq.description}
              </div>
            )}
            <div className="text-xs text-gray-400 dark:text-gray-500 mt-0.5">
              {stepsCount} {stepsCount === 1 ? "шаг" : stepsCount > 4 ? "шагов" : "шага"}
            </div>
          </>
        );
      },
    },
    {
      key: "is_active",
      header: "Статус",
      width: "8rem",
      skeletonWidth: "5rem",
      render: (seq) => <StatusBadge isActive={seq.is_active} />,
    },
  ];

  const hasFilters = Boolean(search || statusFilter);

  return (
    <>
      {/* Filters */}
      <div className="card rounded-2xl shadow-elev-1 lift p-4 mb-4 flex flex-wrap items-end gap-3">
        <div className="flex-1 min-w-[200px]">
          <label className="label">Поиск</label>
          <div className="relative">
            <i className="bi bi-search absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm" />
            <input
              className="input pl-8"
              type="text"
              placeholder="Поиск по названию"
              value={search}
              onChange={(e) => setSearch(e.target.value)}
            />
          </div>
        </div>
        <div className="min-w-[160px]">
          <label className="label">Состояние</label>
          <select
            className="input"
            value={statusFilter}
            onChange={(e) => setStatusFilter(e.target.value as "" | "active" | "inactive")}
          >
            <option value="">Любое</option>
            <option value="active">Активные</option>
            <option value="inactive">Выключенные</option>
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
        rows={error ? [] : sequences}
        getRowKey={(seq) => seq.id}
        rowActions={(seq) => (
          <>
            <button
              className="btn-ghost p-1.5"
              title="Запустить вручную"
              onClick={(e) => {
                e.stopPropagation();
                setStartRun({ ...EMPTY_START, sequenceId: seq.id });
              }}
            >
              <i className="bi bi-play-fill" />
            </button>
            <button
              className="btn-ghost p-1.5"
              title="Редактировать"
              onClick={(e) => { e.stopPropagation(); openEdit(seq); }}
            >
              <i className="bi bi-pencil" />
            </button>
            <button
              className="btn-ghost p-1.5 text-danger"
              title="Удалить"
              onClick={(e) => {
                e.stopPropagation();
                setDeleteId(seq.id);
                setDeleteSeqName(seq.name);
                setDeleteError(null);
              }}
            >
              <i className="bi bi-trash" />
            </button>
          </>
        )}
        ariaLabel="Список последовательностей"
        emptyIcon="bi-collection-play"
        emptyTitle="Последовательностей пока нет"
        emptyText="Создайте цепочку шагов с задержками — запускайте вручную или через автоматизации"
        emptyCta={
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg mr-1" /> Создать первую
          </button>
        }
        isError={!!error}
        errorText="Не удалось загрузить последовательности"
        skeletonRows={4}
      />

      {/* Form modal */}
      <SequenceFormModal
        open={formOpen}
        onClose={closeForm}
        sequence={editSequence}
      />

      {/* Delete confirm */}
      <Modal
        open={deleteId !== null}
        title="Удалить последовательность?"
        onClose={() => { setDeleteId(null); setDeleteError(null); }}
        width="sm"
        footer={
          <>
            <button
              className="btn-ghost"
              onClick={() => { setDeleteId(null); setDeleteError(null); }}
              disabled={deletingId !== null}
            >
              Отмена
            </button>
            <button
              className="btn-primary bg-danger border-danger hover:bg-danger/90 disabled:opacity-50"
              onClick={() => { if (deleteId !== null) void confirmDelete(deleteId); }}
              disabled={deletingId !== null}
            >
              <i className="bi bi-trash mr-1" />
              {deletingId !== null ? "Удаление…" : "Удалить"}
            </button>
          </>
        }
      >
        <div className="space-y-2">
          <p className="text-sm text-gray-700 dark:text-gray-300">
            Последовательность{" "}
            <span className="font-semibold">«{deleteSeqName}»</span> будет удалена
            безвозвратно. Запущенные SequenceRun будут прерваны.
          </p>
          {deleteError && (
            <div className="text-sm text-danger bg-danger/10 border border-danger/30 px-3 py-2 rounded">
              {deleteError}
            </div>
          )}
        </div>
      </Modal>

      {/* Start Run modal */}
      <Modal
        open={startRun !== null}
        title="Запустить последовательность"
        onClose={() => setStartRun(null)}
        width="sm"
      >
        {startRun?.success ? (
          <div className="text-center py-4">
            <i className="bi bi-check-circle-fill text-4xl text-success block mb-3" />
            <div className="text-gray-700 dark:text-gray-300 mb-4">Последовательность запущена.</div>
            <button className="btn-secondary" onClick={() => setStartRun(null)}>
              Закрыть
            </button>
          </div>
        ) : (
          <form onSubmit={(e) => { void submitStartRun(e); }} className="space-y-3">
            {startRun?.error && (
              <div className="text-sm text-danger bg-danger/10 border border-danger/30 px-3 py-2 rounded-md">
                {startRun.error}
              </div>
            )}
            <div>
              <label className="label">Тип цели</label>
              <select
                className="input"
                value={startRun?.targetType ?? "lead"}
                onChange={(e) =>
                  setStartRun((s) =>
                    s ? { ...s, targetType: e.target.value as StartRunState["targetType"] } : s,
                  )
                }
              >
                <option value="lead">Лид</option>
                <option value="deal">Сделка</option>
                <option value="subscription">Подписка</option>
              </select>
            </div>
            <div>
              <label className="label">ID цели <span className="text-danger">*</span></label>
              <input
                className="input"
                type="number"
                min={1}
                value={startRun?.targetId ?? ""}
                onChange={(e) =>
                  setStartRun((s) => s ? { ...s, targetId: e.target.value } : s)
                }
                placeholder="Введите числовой ID"
                required
              />
            </div>
            <div className="flex justify-end gap-2 pt-1">
              <button
                type="button"
                className="btn-secondary"
                onClick={() => setStartRun(null)}
                disabled={startRun?.submitting}
              >
                Отмена
              </button>
              <button
                type="submit"
                className="btn-primary disabled:opacity-50"
                disabled={startRun?.submitting || !startRun?.targetId}
              >
                <i className="bi bi-play-fill mr-1" />
                {startRun?.submitting ? "Запуск…" : "Запустить"}
              </button>
            </div>
          </form>
        )}
      </Modal>
    </>
  );
}
