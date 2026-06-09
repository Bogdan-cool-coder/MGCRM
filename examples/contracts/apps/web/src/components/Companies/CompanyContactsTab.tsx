"use client";

import { useCallback, useState } from "react";
import useSWR from "swr";
import { ContactFormModal } from "@/components/Contacts/ContactFormModal";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import type { Company, Contact, User } from "@/lib/types";

interface CompanyContactsTabProps {
  companyId: number;
  /** Все компании — для селекта переноса контакта в другую компанию из модалки. */
  companies: Company[] | undefined;
  users: User[] | undefined;
}

export function CompanyContactsTab({ companyId, companies, users }: CompanyContactsTabProps) {
  const { user } = useMe();
  const canDelete = user?.role === "admin" || user?.role === "director";
  const { data: contacts, mutate, error: contactsError, isLoading } =
    useSWR<Contact[]>(`/companies/${companyId}/contacts`, fetcher);

  const [editing, setEditing] = useState<Contact | null>(null);
  const [formOpen, setFormOpen] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function openCreate() {
    setEditing(null);
    setFormOpen(true);
  }
  function openEdit(c: Contact) {
    setEditing(c);
    setFormOpen(true);
  }

  const userName = useCallback(
    (uid: number | null) => users?.find((u) => u.id === uid)?.full_name ?? "",
    [users],
  );

  async function del(c: Contact) {
    if (!confirm(`Удалить контакт «${c.full_name}»? Действие необратимо.`)) return;
    setError(null);
    try {
      await api(`/contacts/${c.id}`, { method: "DELETE" });
      await mutate();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить");
    }
  }

  return (
    <div className="max-w-3xl space-y-3">
      <div className="flex items-center justify-between">
        <p className="text-sm text-gray-600">
          Контактные лица компании. Основной контакт помечен бейджем.
        </p>
        <button onClick={openCreate} className="btn-primary text-sm">
          <i className="bi bi-plus-lg" /> Контакт
        </button>
      </div>

      {error && (
        <div className="text-sm text-danger bg-danger/10 border border-danger/30 px-3 py-2 rounded flex items-start justify-between gap-3">
          <span><i className="bi bi-exclamation-triangle mr-1" />{error}</span>
          <button onClick={() => setError(null)} className="text-gray-700"><i className="bi bi-x-lg" /></button>
        </div>
      )}

      {contactsError && (
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
          Не удалось загрузить контакты.
        </div>
      )}

      {isLoading && <div className="text-sm text-gray-500">Загрузка…</div>}

      {contacts && contacts.length === 0 && !isLoading && (
        <div className="card p-6 text-center text-gray-500">
          <i className="bi bi-person-rolodex text-2xl block mb-1 text-gray-400" />
          У компании пока нет контактов.
          <div className="mt-2">
            <button className="btn-primary text-sm" onClick={openCreate}>
              <i className="bi bi-plus-lg" /> Добавить контакт
            </button>
          </div>
        </div>
      )}

      {contacts && contacts.length > 0 && (
        <div className="space-y-2">
          {contacts.map((c) => (
            <div
              key={c.id}
              className="border border-gray-200 rounded-lg p-3 hover:border-primary/40 transition-colors"
            >
              <div className="flex items-start justify-between gap-3">
                <div className="min-w-0 flex-1">
                  <div className="flex items-center gap-2 flex-wrap">
                    <span className="font-medium text-primary">{c.full_name}</span>
                    {c.is_primary && (
                      <span className="text-[10px] uppercase tracking-wide bg-primary/10 text-primary px-1.5 py-0.5 rounded">
                        основной
                      </span>
                    )}
                  </div>
                  {c.position && (
                    <div className="text-xs text-gray-500 mt-0.5">{c.position}</div>
                  )}
                  <div className="text-sm text-gray-700 mt-1 flex flex-wrap gap-x-4 gap-y-1">
                    {c.email && (
                      <span className="inline-flex items-center gap-1">
                        <i className="bi bi-envelope text-gray-400" /> {c.email}
                      </span>
                    )}
                    {c.phone && (
                      <span className="inline-flex items-center gap-1">
                        <i className="bi bi-telephone text-gray-400" /> {c.phone}
                      </span>
                    )}
                    {c.tg_username && (
                      <span className="inline-flex items-center gap-1">
                        <i className="bi bi-telegram text-gray-400" /> {c.tg_username}
                      </span>
                    )}
                  </div>
                  {c.notes && (
                    <div className="text-xs text-gray-500 mt-2 whitespace-pre-wrap">
                      {c.notes}
                    </div>
                  )}
                  {c.owner_id && (
                    <div className="text-xs text-gray-400 mt-2">
                      Владелец: {userName(c.owner_id) || "—"}
                    </div>
                  )}
                </div>
                <div className="flex items-center gap-2 text-primary">
                  <button onClick={() => openEdit(c)} title="Редактировать">
                    <i className="bi bi-pencil" />
                  </button>
                  {canDelete && (
                    <button onClick={() => del(c)} title="Удалить" className="text-danger">
                      <i className="bi bi-trash" />
                    </button>
                  )}
                </div>
              </div>
            </div>
          ))}
        </div>
      )}

      <ContactFormModal
        open={formOpen}
        contact={editing}
        companies={companies}
        users={users}
        lockedCompanyId={editing ? null : companyId}
        onClose={() => setFormOpen(false)}
        onSaved={() => mutate()}
      />
    </div>
  );
}
