"use client";

import { useState } from "react";
import { api, errorMessage } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { UserSelect } from "@/components/UserSelect";
import { DateTimePicker } from "@/components/ui/DateTimePicker";

interface Props {
  selectedIds: number[];
  onClear: () => void;
  onMutate: () => void;
}

export function BulkActionsBar({ selectedIds, onClear, onMutate }: Props) {
  const [deadlineOpen, setDeadlineOpen] = useState(false);
  const [assignOpen, setAssignOpen] = useState(false);
  const [closeOpen, setCloseOpen] = useState(false);
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deadlineValue, setDeadlineValue] = useState("");
  const [responsibleId, setResponsibleId] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const n = selectedIds.length;

  async function runBulk(action: string, params?: Record<string, unknown>) {
    setSubmitting(true);
    setError(null);
    try {
      // Канонический shape: {action, entity_ids, params} (см. BulkActionIn на бэке).
      await api("/activities/bulk", {
        method: "POST",
        body: { action, entity_ids: selectedIds, params: params ?? {} },
      });
      onMutate();
      onClear();
    } catch (err: unknown) {
      setError(errorMessage(err, "Не удалось выполнить массовое действие"));
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <>
      <div
        className={
          "fixed bottom-0 left-0 right-0 z-30 bg-white dark:bg-gray-800 " +
          "border-t border-gray-200 dark:border-gray-700 px-6 py-3 " +
          "flex items-center gap-3 shadow-lg transition-transform duration-200 " +
          (n > 0 ? "translate-y-0" : "translate-y-full")
        }
      >
        <span className="text-sm font-medium text-gray-700 dark:text-gray-300">
          Выбрано: {n}
        </span>
        {error && (
          <span className="text-xs text-danger bg-danger/10 rounded px-2 py-1">
            {error}
          </span>
        )}
        <div className="h-4 w-px bg-gray-300 dark:bg-gray-600" />

        <button
          className="btn-secondary text-sm"
          onClick={() => setDeadlineOpen(true)}
        >
          <i className="bi bi-calendar2-check mr-1.5" />
          Изменить дедлайн
        </button>

        <button
          className="btn-secondary text-sm"
          onClick={() => setAssignOpen(true)}
        >
          <i className="bi bi-person-check mr-1.5" />
          Переназначить
        </button>

        <button
          className="btn-secondary text-sm"
          onClick={() => setCloseOpen(true)}
        >
          <i className="bi bi-check2-all mr-1.5" />
          Закрыть все
        </button>

        <button
          className="btn-secondary text-sm text-danger"
          onClick={() => setDeleteOpen(true)}
        >
          <i className="bi bi-trash mr-1.5" />
          Удалить все
        </button>

        <button className="btn-ghost text-sm ml-auto" onClick={onClear}>
          Отмена выбора
        </button>
      </div>

      {/* Deadline modal */}
      <Modal
        open={deadlineOpen}
        title={`Изменить дедлайн для ${n} задач`}
        onClose={() => setDeadlineOpen(false)}
        footer={
          <>
            <button className="btn-ghost" onClick={() => setDeadlineOpen(false)}>Отмена</button>
            <button
              className="btn-primary"
              disabled={!deadlineValue || submitting}
              onClick={() => runBulk("change_deadline", { due_at: deadlineValue })}
            >
              {submitting ? "Сохранение..." : "Сохранить"}
            </button>
          </>
        }
      >
        <div>
          <DateTimePicker
            value={deadlineValue}
            onChange={setDeadlineValue}
          />
        </div>
      </Modal>

      {/* Assign modal */}
      <Modal
        open={assignOpen}
        title={`Переназначить ${n} задач`}
        onClose={() => setAssignOpen(false)}
        footer={
          <>
            <button className="btn-ghost" onClick={() => setAssignOpen(false)}>Отмена</button>
            <button
              className="btn-primary"
              disabled={!responsibleId || submitting}
              onClick={() => runBulk("reassign", { responsible_id: Number(responsibleId) })}
            >
              {submitting ? "Сохранение..." : "Сохранить"}
            </button>
          </>
        }
      >
        <div>
          <label className="label">Новый ответственный *</label>
          <UserSelect value={responsibleId} onChange={setResponsibleId} placeholder="Выбрать..." />
        </div>
      </Modal>

      {/* Close confirm */}
      <Modal
        open={closeOpen}
        title={`Закрыть ${n} задач?`}
        onClose={() => setCloseOpen(false)}
        footer={
          <>
            <button className="btn-ghost" onClick={() => setCloseOpen(false)}>Отмена</button>
            <button
              className="btn-primary"
              disabled={submitting}
              onClick={() => runBulk("close")}
            >
              {submitting ? "Закрываем..." : "Закрыть задачи"}
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Внимание: если среди задач есть с обязательным результатом, они будут пропущены.
        </p>
      </Modal>

      {/* Delete confirm */}
      <Modal
        open={deleteOpen}
        title={`Удалить ${n} задач?`}
        onClose={() => setDeleteOpen(false)}
        footer={
          <>
            <button className="btn-ghost" onClick={() => setDeleteOpen(false)}>Отмена</button>
            <button
              className="btn-primary text-danger"
              disabled={submitting}
              onClick={() => runBulk("delete")}
            >
              {submitting ? "Удаляем..." : "Удалить всё"}
            </button>
          </>
        }
      >
        <p className="text-sm text-danger">
          Это действие нельзя отменить. Задачи и их подзадачи будут удалены.
        </p>
      </Modal>
    </>
  );
}
