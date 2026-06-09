"use client";

import { useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import type { LostReason } from "@/lib/types";

interface FormState {
  id?: number;
  name: string;
  sort_order: string;
  is_active: boolean;
}

export function LostReasonsAdmin() {
  const { data, mutate } = useSWR<LostReason[]>("/deals/lost-reasons", fetcher);
  const [form, setForm] = useState<FormState | null>(null);
  const [deleting, setDeleting] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  async function save() {
    if (!form || !form.name.trim()) {
      setError("Название обязательно");
      return;
    }
    setError(null);
    try {
      const body = {
        name: form.name.trim(),
        sort_order: Number(form.sort_order) || 0,
        is_active: form.is_active,
      };
      if (form.id) {
        await api(`/deals/lost-reasons/${form.id}`, { method: "PATCH", body });
      } else {
        await api("/deals/lost-reasons", { method: "POST", body });
      }
      await mutate();
      setForm(null);
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Ошибка сохранения"
      );
    }
  }

  async function remove(id: number) {
    if (!confirm("Удалить причину отказа?")) return;
    setDeleting(id);
    try {
      await api(`/deals/lost-reasons/${id}`, { method: "DELETE" });
      await mutate();
    } catch {
      // ignore
    } finally {
      setDeleting(null);
    }
  }

  const items = data ?? [];

  return (
    <div>
      <div className="flex items-center justify-between mb-4">
        <div>
          <h3 className="text-base font-semibold text-gray-800 dark:text-gray-100">
            Причины отказа
          </h3>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            Появляются при переводе сделки в «Проигрыш»
          </p>
        </div>
        <button
          className="btn-primary text-sm"
          onClick={() => setForm({ name: "", sort_order: String(items.length * 10), is_active: true })}
        >
          <i className="bi bi-plus mr-1" />Добавить
        </button>
      </div>

      {items.length === 0 && !data && (
        <div className="py-6 text-center text-gray-400 text-sm">Загрузка…</div>
      )}

      {items.length === 0 && data && (
        <div className="py-6 text-center text-gray-400 text-sm">
          <i className="bi bi-x-circle text-2xl block mb-2" />
          Нет причин отказа
        </div>
      )}

      <div className="space-y-1.5">
        {items.map((item) => (
          <div
            key={item.id}
            className="flex items-center gap-3 border border-gray-200 dark:border-gray-700 rounded-lg px-3 py-2.5"
          >
            <i className="bi bi-grip-vertical text-gray-300 cursor-grab" />
            <span className="text-xs text-gray-400 w-8 text-right shrink-0">
              {item.sort_order ?? 0}
            </span>
            <span className="flex-1 text-sm text-gray-800 dark:text-gray-200">
              {item.name}
            </span>
            {item.is_active === false && (
              <span className="text-xs text-gray-400 shrink-0">выкл</span>
            )}
            <button
              className="btn-ghost text-xs p-1"
              onClick={() =>
                setForm({
                  id: item.id,
                  name: item.name,
                  sort_order: String(item.sort_order ?? 0),
                  is_active: item.is_active ?? true,
                })
              }
            >
              <i className="bi bi-pencil" />
            </button>
            <button
              className="btn-ghost text-xs p-1 text-danger"
              disabled={deleting === item.id}
              onClick={() => void remove(item.id)}
            >
              <i className={deleting === item.id ? "bi bi-hourglass" : "bi bi-trash"} />
            </button>
          </div>
        ))}
      </div>

      {form && (
        <Modal
          open
          title={form.id ? "Редактировать причину" : "Новая причина отказа"}
          onClose={() => { setForm(null); setError(null); }}
          width="sm"
          footer={
            <>
              <button className="btn-ghost" onClick={() => { setForm(null); setError(null); }}>
                Отмена
              </button>
              <button className="btn-primary disabled:opacity-50" onClick={save} disabled={!form.name.trim()}>
                Сохранить
              </button>
            </>
          }
        >
          <div className="space-y-3">
            {error && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
            )}
            <div>
              <label className="label">Название <span className="text-danger">*</span></label>
              <input
                className="input"
                autoFocus
                value={form.name}
                onChange={(e) => setForm({ ...form, name: e.target.value })}
                placeholder="Напр.: «Высокая цена»"
              />
            </div>
            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Сортировка</label>
                <input
                  className="input"
                  type="number"
                  min={0}
                  value={form.sort_order}
                  onChange={(e) => setForm({ ...form, sort_order: e.target.value })}
                />
              </div>
              <div className="flex items-end pb-2">
                <label className="flex items-center gap-2 cursor-pointer text-sm">
                  <input
                    type="checkbox"
                    checked={form.is_active}
                    onChange={(e) => setForm({ ...form, is_active: e.target.checked })}
                  />
                  Активна
                </label>
              </div>
            </div>
          </div>
        </Modal>
      )}
    </div>
  );
}
