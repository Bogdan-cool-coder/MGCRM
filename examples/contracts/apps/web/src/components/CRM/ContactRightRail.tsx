"use client";

import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { UserSelect } from "@/components/UserSelect";
import type { Contact, User } from "@/lib/types";
import { CONTACT_SOURCE_LABELS } from "@/lib/types";
import { formatDate } from "@/lib/dates";

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  contact: Contact;
  editMode: boolean;
  onSaved: () => void;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ContactRightRail({ contact, editMode, onSaved }: Props) {
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const ownerName =
    contact.owner_id && users
      ? (users.find((u) => u.id === contact.owner_id)?.full_name ??
        `#${contact.owner_id}`)
      : null;

  async function patchOwner(userId: string) {
    try {
      await api(`/contacts/${contact.id}`, {
        method: "PATCH",
        body: { owner_id: userId ? Number(userId) : null },
      });
      onSaved();
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? String(
              (err.detail as { detail?: string })?.detail ?? err.message,
            )
          : "Не удалось сохранить";
      alert(msg);
    }
  }

  return (
    <aside className="hidden lg:flex w-64 shrink-0 self-start sticky top-20">
      <div className="card rounded-2xl shadow-elev-1 p-5 space-y-5 mr-8 mt-6 w-full bg-white dark:bg-gray-800">
        {/* Ответственный */}
        <div className="space-y-1">
          <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium mb-1">
            Ответственный
          </div>
          {editMode ? (
            <UserSelect
              className="input text-sm"
              value={contact.owner_id ? String(contact.owner_id) : ""}
              onChange={(v) => void patchOwner(v)}
              users={users}
            />
          ) : (
            <div className="text-sm text-gray-700 dark:text-gray-300">
              {ownerName ?? (
                <span className="text-gray-400 dark:text-gray-500">—</span>
              )}
            </div>
          )}
        </div>

        {/* Источник */}
        {contact.source && (
          <div className="space-y-1">
            <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium mb-1">
              Источник
            </div>
            <div className="text-sm text-gray-700 dark:text-gray-300">
              {CONTACT_SOURCE_LABELS[contact.source] ?? contact.source}
            </div>
          </div>
        )}

        {/* Теги */}
        {contact.tags && contact.tags.length > 0 && (
          <div className="space-y-1.5">
            <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium">
              Теги
            </div>
            <div className="flex flex-wrap gap-1">
              {contact.tags.map((tag) => (
                <span key={tag} className="badge badge-info text-xs">
                  {tag}
                </span>
              ))}
            </div>
          </div>
        )}

        {/* Даты */}
        <div className="space-y-2 pt-1 border-t border-gray-100 dark:border-gray-700">
          <div>
            <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium mb-0.5">
              Создан
            </div>
            <div className="text-sm text-gray-700 dark:text-gray-300">
              {formatDate(contact.created_at)}
            </div>
          </div>
          <div>
            <div className="text-[10px] uppercase tracking-widest text-gray-400 dark:text-gray-500 font-medium mb-0.5">
              Обновлён
            </div>
            <div className="text-sm text-gray-700 dark:text-gray-300">
              {formatDate(contact.updated_at)}
            </div>
          </div>
        </div>
      </div>
    </aside>
  );
}
