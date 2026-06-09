"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { DepartmentTree } from "@/components/Departments/DepartmentTree";
import { DepartmentFormModal } from "@/components/Departments/DepartmentFormModal";
import { api, ApiError, fetcher } from "@/lib/api";
import { useToast } from "@/components/ui/Toast";
import type { Department, User } from "@/lib/types";

export default function DepartmentsPage() {
  const { data: deps, mutate: mDeps, error: depsError, isLoading } = useSWR<Department[]>(
    "/departments",
    fetcher,
  );
  const { data: users } = useSWR<User[]>("/users", fetcher);

  // Форма создания/редактирования
  const [formOpen, setFormOpen] = useState(false);
  const [editTarget, setEditTarget] = useState<Department | undefined>(undefined);

  // Подтверждение удаления
  const [deleteTarget, setDeleteTarget] = useState<Department | null>(null);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);
  const { toast } = useToast();

  function openCreate() {
    setEditTarget(undefined);
    setFormOpen(true);
  }

  function openEdit(d: Department) {
    setEditTarget(d);
    setFormOpen(true);
  }

  function openDelete(d: Department) {
    setDeleteTarget(d);
    setDeleteError(null);
  }

  async function handleSaved() {
    setFormOpen(false);
    await mDeps();
    toast.success(editTarget ? "Отдел обновлён" : "Отдел создан");
  }

  async function handleDelete() {
    if (!deleteTarget) return;
    setDeleting(true);
    setDeleteError(null);
    try {
      await api(`/departments/${deleteTarget.id}`, { method: "DELETE" });
      setDeleteTarget(null);
      await mDeps();
      toast.success("Отдел удалён");
    } catch (err) {
      if (err instanceof ApiError) {
        const detail = (err.detail as { detail?: string })?.detail ?? err.message;
        setDeleteError(String(detail));
      } else {
        setDeleteError("Не удалось удалить. Попробуй ещё раз.");
      }
    } finally {
      setDeleting(false);
    }
  }

  const canDelete =
    deleteTarget != null && (deleteTarget.members_count ?? 0) === 0;

  const allDeps = deps ?? [];
  const allUsers = users ?? [];

  return (
    <>
      <PageHeader
        title="Отделы и команды"
        description="Организуй команду по отделам для управления видимостью и аналитики"
        actions={
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg mr-1" />
            Добавить отдел
          </button>
        }
      />

      <div className="p-8 max-w-3xl">
        {/* Ошибка загрузки */}
        {depsError && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mb-4">
            Не удалось загрузить отделы. Попробуй обновить страницу.
          </div>
        )}

        {/* Skeleton при загрузке */}
        {isLoading && (
          <div className="space-y-3">
            {[1, 2, 3].map((i) => (
              <div key={i} className="card p-4 mb-3 animate-pulse">
                <div className="h-5 w-48 bg-gray-200 rounded mb-2" />
                <div className="h-4 w-32 bg-gray-100 rounded" />
              </div>
            ))}
          </div>
        )}

        {/* Empty state */}
        {!isLoading && !depsError && allDeps.length === 0 && (
          <div className="card p-12 flex flex-col items-center text-center">
            <i className="bi bi-diagram-3 text-5xl text-gray-300 mb-4" />
            <h3 className="text-lg font-semibold text-gray-700 mb-2">
              Пока нет ни одного отдела
            </h3>
            <p className="text-gray-500 text-sm mb-4">
              Создай первый, чтобы начать организовывать команду
            </p>
            <button className="btn-primary" onClick={openCreate}>
              <i className="bi bi-plus-lg mr-1" />
              Добавить отдел
            </button>
          </div>
        )}

        {/* Дерево отделов */}
        {!isLoading && !depsError && allDeps.length > 0 && (
          <DepartmentTree
            departments={allDeps}
            allUsers={allUsers}
            onEdit={openEdit}
            onDelete={openDelete}
            onReorder={() => mDeps()}
          />
        )}
      </div>

      {/* Форма создания/редактирования */}
      <DepartmentFormModal
        open={formOpen}
        onClose={() => setFormOpen(false)}
        onSaved={handleSaved}
        department={editTarget}
        allDepartments={allDeps}
        allUsers={allUsers}
      />

      {/* Диалог подтверждения удаления */}
      <Modal
        open={deleteTarget != null}
        onClose={() => {
          setDeleteTarget(null);
          setDeleteError(null);
        }}
        title="Удалить отдел?"
        width="sm"
        footer={
          <>
            <button
              className="btn-ghost"
              onClick={() => {
                setDeleteTarget(null);
                setDeleteError(null);
              }}
              disabled={deleting}
            >
              Отмена
            </button>
            <button
              className="btn-secondary text-danger"
              onClick={handleDelete}
              disabled={!canDelete || deleting}
              title={!canDelete ? "Сначала переведи сотрудников" : undefined}
            >
              <i className="bi bi-trash mr-1" />
              {deleting ? "Удаление…" : "Удалить"}
            </button>
          </>
        }
      >
        {deleteTarget && (
          <div className="space-y-3">
            <p className="text-sm text-gray-700">
              Удалить отдел «{deleteTarget.name}»? Это действие нельзя отменить.
            </p>
            {(deleteTarget.members_count ?? 0) > 0 && (
              <div className="flex items-start gap-2 text-sm text-warning bg-warning/10 px-3 py-2 rounded">
                <i className="bi bi-exclamation-triangle shrink-0 mt-0.5" />
                <span>
                  В отделе {deleteTarget.members_count} сотрудников. Сначала переведи их в другой
                  отдел, затем удали отдел.
                </span>
              </div>
            )}
            {deleteError && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
                {deleteError}
              </div>
            )}
          </div>
        )}
      </Modal>
    </>
  );
}
