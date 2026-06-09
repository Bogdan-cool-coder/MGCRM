"use client";

import { useState } from "react";
import useSWR from "swr";
import { api, fetcher } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { EmptyState } from "@/components/EmptyState";
import { TaskCategoryForm } from "@/components/Tasks/TaskCategoryForm";
import type { TaskCategory } from "@/lib/types";

const SWR_KEY = "/task-categories";

export function TaskCategoriesPanel() {
  const { data: categories, isLoading, mutate } = useSWR<TaskCategory[]>(
    SWR_KEY,
    fetcher
  );

  const [search, setSearch] = useState("");
  const [createOpen, setCreateOpen] = useState(false);
  const [editCategory, setEditCategory] = useState<TaskCategory | null>(null);
  const [deleteCategory, setDeleteCategory] = useState<TaskCategory | null>(
    null
  );
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState("");

  const filtered = (categories ?? []).filter((c) =>
    c.name.toLowerCase().includes(search.toLowerCase())
  );

  async function handleCreate(data: Partial<TaskCategory>) {
    setSubmitting(true);
    setError("");
    try {
      await api("/task-categories", { method: "POST", body: data });
      await mutate();
      setCreateOpen(false);
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Не удалось создать категорию"
      );
    } finally {
      setSubmitting(false);
    }
  }

  async function handleEdit(data: Partial<TaskCategory>) {
    if (!editCategory) return;
    setSubmitting(true);
    setError("");
    try {
      await api(`/task-categories/${editCategory.id}`, {
        method: "PATCH",
        body: data,
      });
      await mutate();
      setEditCategory(null);
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Не удалось сохранить категорию"
      );
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete() {
    if (!deleteCategory) return;
    setSubmitting(true);
    try {
      await api(`/task-categories/${deleteCategory.id}`, { method: "DELETE" });
      await mutate();
      setDeleteCategory(null);
    } catch (e) {
      setError(
        e instanceof Error ? e.message : "Не удалось удалить категорию"
      );
    } finally {
      setSubmitting(false);
    }
  }

  return (
    <div>
      {/* Search + Add */}
      <div className="mb-5 flex gap-3 items-center justify-between">
        <input
          className="input max-w-sm"
          placeholder="Поиск по названию..."
          value={search}
          onChange={(e) => setSearch(e.target.value)}
        />
        <button className="btn-primary" onClick={() => setCreateOpen(true)}>
          <i className="bi bi-plus mr-1" />
          Создать категорию
        </button>
      </div>

      {error && (
        <div className="text-danger text-sm bg-danger/10 rounded px-3 py-2 mb-4">
          {error}
        </div>
      )}

      {isLoading && (
        <div className="space-y-3">
          {[1, 2, 3].map((i) => (
            <div
              key={i}
              className="h-16 bg-gray-100 dark:bg-gray-700 animate-pulse rounded-lg"
            />
          ))}
        </div>
      )}

      {!isLoading && filtered.length === 0 && (
        <EmptyState
          icon="bi-tags"
          title="Нет категорий задач"
          description="Создай первую категорию, чтобы стандартизировать задачи команды"
          cta={
            <button
              className="btn-primary"
              onClick={() => setCreateOpen(true)}
            >
              + Создать категорию
            </button>
          }
        />
      )}

      {!isLoading && filtered.length > 0 && (
        <div className="space-y-3">
          {filtered.map((cat) => (
            <div key={cat.id} className="card p-4 flex items-center gap-3">
              <i className="bi bi-grip-vertical text-gray-400 cursor-grab" />
              {cat.color && (
                <span
                  className="w-3 h-3 rounded-full shrink-0"
                  style={{ background: cat.color }}
                />
              )}
              <div className="flex-1 min-w-0">
                <div className="font-medium text-gray-800 dark:text-gray-200">
                  {cat.name}
                </div>
                {cat.description_template && (
                  <div className="text-xs text-gray-500 truncate">
                    {cat.description_template}
                  </div>
                )}
              </div>
              {cat.checklist_items_count > 0 && (
                <span className="text-xs text-gray-500 shrink-0">
                  {cat.checklist_items_count} чек-пунктов
                </span>
              )}
              <span
                className={
                  "badge shrink-0 " +
                  (cat.is_active
                    ? "bg-success/10 text-success"
                    : "bg-gray-200 text-gray-500")
                }
              >
                {cat.is_active ? "Активна" : "Неактивна"}
              </span>
              <button
                className="btn-ghost text-sm shrink-0"
                onClick={() => setEditCategory(cat)}
              >
                <i className="bi bi-pencil mr-1" /> Редактировать
              </button>
              <button
                className="btn-ghost text-sm text-danger shrink-0"
                onClick={() => setDeleteCategory(cat)}
              >
                <i className="bi bi-trash" />
              </button>
            </div>
          ))}
        </div>
      )}

      {/* Create modal */}
      <Modal
        open={createOpen}
        title="Создать категорию задач"
        onClose={() => setCreateOpen(false)}
        width="lg"
      >
        <TaskCategoryForm
          onSubmit={handleCreate}
          onCancel={() => setCreateOpen(false)}
          submitting={submitting}
        />
      </Modal>

      {/* Edit modal */}
      <Modal
        open={editCategory !== null}
        title={`Редактировать: ${editCategory?.name ?? ""}`}
        onClose={() => setEditCategory(null)}
        width="lg"
      >
        <TaskCategoryForm
          initial={editCategory}
          onSubmit={handleEdit}
          onCancel={() => setEditCategory(null)}
          submitting={submitting}
        />
      </Modal>

      {/* Delete confirm */}
      <Modal
        open={deleteCategory !== null}
        title={`Удалить категорию «${deleteCategory?.name ?? ""}»?`}
        onClose={() => setDeleteCategory(null)}
        footer={
          <>
            <button
              className="btn-ghost"
              onClick={() => setDeleteCategory(null)}
            >
              Отмена
            </button>
            <button
              className="btn-primary text-danger"
              disabled={submitting}
              onClick={handleDelete}
            >
              {submitting ? "Удаляем..." : "Удалить"}
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Все задачи этой категории сохранятся, но потеряют привязку к ней.
        </p>
      </Modal>
    </div>
  );
}
