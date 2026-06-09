"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import type { DealContactOut } from "@/lib/types";

interface DealContactsCardProps {
  dealId: number;
  /** Есть ли у сделки компания — подсказка, что контакт попадёт в карточку компании. */
  hasCompany: boolean;
}

export function DealContactsCard({ dealId, hasCompany }: DealContactsCardProps) {
  const key = `/deals/${dealId}/contacts`;
  const { data: contacts, mutate } = useSWR<DealContactOut[]>(key, fetcher);

  const [adding, setAdding] = useState(false);
  const [fullName, setFullName] = useState("");
  const [phone, setPhone] = useState("");
  const [position, setPosition] = useState("");
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [deletingId, setDeletingId] = useState<number | null>(null);

  function resetForm() {
    setAdding(false);
    setFullName("");
    setPhone("");
    setPosition("");
    setError(null);
  }

  async function handleAdd() {
    if (submitting) return;
    if (!fullName.trim()) { setError("Укажите ФИО"); return; }
    setSubmitting(true);
    setError(null);
    try {
      await api(`/deals/${dealId}/contacts`, {
        method: "POST",
        body: {
          full_name: fullName.trim(),
          phone: phone.trim() || null,
          position: position.trim() || null,
        },
      });
      await mutate();
      resetForm();
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось добавить контакт"
      );
    } finally {
      setSubmitting(false);
    }
  }

  async function handleDelete(contactId: number) {
    setDeletingId(contactId);
    try {
      await api(`/deals/${dealId}/contacts/${contactId}`, { method: "DELETE" });
      await mutate();
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось отвязать контакт"
      );
    } finally {
      setDeletingId(null);
    }
  }

  return (
    <div className="p-5">
      <div className="flex items-center justify-between mb-3">
        <h3 className="text-sm font-semibold text-gray-900 dark:text-gray-100">
          <i className="bi bi-people mr-1.5 text-gray-400" />
          Контакты
        </h3>
        {!adding && (
          <button type="button" className="btn-ghost text-sm" onClick={() => setAdding(true)}>
            <i className="bi bi-plus mr-1" /> Добавить контакт
          </button>
        )}
      </div>

      {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded mb-3">{error}</div>}

      {contacts && contacts.length > 0 ? (
        <div className="space-y-1.5">
          {contacts.map((c) => (
            <div
              key={c.id}
              className="group flex items-start gap-2 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0"
            >
              <i className="bi bi-person-circle text-gray-300 dark:text-gray-600 text-lg shrink-0 mt-0.5" />
              <div className="flex-1 min-w-0">
                <Link href={`/contacts/${c.contact_id}`} className="text-sm font-medium text-gray-900 dark:text-gray-100 hover:text-primary hover:underline">
                  {c.full_name}
                </Link>
                <div className="text-xs text-gray-500 dark:text-gray-400 flex flex-wrap gap-x-2">
                  {c.position && <span>{c.position}</span>}
                  {c.phone && <span><i className="bi bi-telephone mr-0.5" />{c.phone}</span>}
                  {c.email && <span><i className="bi bi-envelope mr-0.5" />{c.email}</span>}
                </div>
              </div>
              <button
                type="button"
                className="btn-ghost text-xs py-1 px-1.5 text-danger opacity-0 group-hover:opacity-100 disabled:opacity-50 shrink-0"
                disabled={deletingId === c.contact_id}
                onClick={() => void handleDelete(c.contact_id)}
                title="Отвязать от сделки"
              >
                <i className="bi bi-x-lg" />
              </button>
            </div>
          ))}
        </div>
      ) : (
        !adding && (
          <div className="text-sm text-gray-400 dark:text-gray-500 text-center py-4">
            Контактов пока нет
          </div>
        )
      )}

      {adding && (
        <div className="mt-3 border border-gray-200 dark:border-gray-700 rounded-lg p-3 bg-gray-50 dark:bg-gray-900 space-y-2">
          <input
            className="input text-sm py-1.5"
            placeholder="ФИО *"
            autoFocus
            value={fullName}
            onChange={(e) => setFullName(e.target.value)}
            onKeyDown={(e) => { if (e.key === "Enter") void handleAdd(); }}
          />
          <div className="grid grid-cols-2 gap-2">
            <input
              className="input text-sm py-1.5"
              placeholder="Телефон"
              value={phone}
              onChange={(e) => setPhone(e.target.value)}
            />
            <input
              className="input text-sm py-1.5"
              placeholder="Должность"
              value={position}
              onChange={(e) => setPosition(e.target.value)}
            />
          </div>
          {hasCompany && (
            <p className="text-xs text-gray-400 dark:text-gray-500">
              <i className="bi bi-info-circle mr-1" />
              Контакт также появится в карточке компании.
            </p>
          )}
          <div className="flex gap-1.5">
            <button
              type="button"
              className="btn-primary text-xs py-1.5 px-3 disabled:opacity-50"
              disabled={submitting}
              onClick={() => void handleAdd()}
            >
              {submitting ? "Сохранение…" : "Добавить"}
            </button>
            <button type="button" className="btn-ghost text-xs py-1.5 px-3" onClick={resetForm}>
              Отмена
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
