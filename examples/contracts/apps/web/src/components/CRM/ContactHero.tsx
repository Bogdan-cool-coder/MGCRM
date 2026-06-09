"use client";

import type { Contact } from "@/lib/types";
import { CONTACT_SOURCE_LABELS } from "@/lib/types";

// ── ContactAvatar ──────────────────────────────────────────────────────────────
// Лёгкая версия без userId/hasAvatar — для контакта нет загрузки аватара.
// Генерирует инициалы из full_name, тот же алгоритм что в Avatar.tsx.

function contactInitials(name: string): string {
  const parts = name.trim().split(/\s+/);
  if (parts.length === 0) return "?";
  if (parts.length === 1) return parts[0].slice(0, 2).toUpperCase();
  return (parts[0][0] + parts[parts.length - 1][0]).toUpperCase();
}

interface ContactAvatarProps {
  name: string;
  size: number;
  className?: string;
}

function ContactAvatar({ name, size, className }: ContactAvatarProps) {
  return (
    <div
      className={[
        "shrink-0 rounded-full overflow-hidden bg-primary text-white",
        "inline-flex items-center justify-center font-semibold select-none",
        className,
      ]
        .filter(Boolean)
        .join(" ")}
      style={{ width: size, height: size, fontSize: Math.round(size * 0.4) }}
      aria-hidden="true"
    >
      <span>{contactInitials(name)}</span>
    </div>
  );
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface ContactHeroProps {
  contact: Contact;
  editMode: boolean;
  onEdit: () => void;
  onBack: () => void;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ContactHero({ contact, editMode, onEdit, onBack }: ContactHeroProps) {
  return (
    <div className="mx-8 mt-6 card rounded-2xl shadow-elev-2 p-6 bg-white dark:bg-gray-800">
      <div className="flex items-start gap-5">
        {/* Avatar 56px */}
        <ContactAvatar name={contact.full_name} size={56} className="mt-0.5" />

        {/* Info block */}
        <div className="flex-1 min-w-0">
          {/* Eyebrow */}
          <div className="text-[10px] text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-0.5 font-medium">
            Контакт
          </div>

          {/* Имя */}
          <h1 className="text-xl font-semibold text-gray-900 dark:text-white truncate leading-tight">
            {contact.full_name}
          </h1>

          {/* Должность */}
          {contact.position && (
            <div className="text-sm text-gray-500 dark:text-gray-400 mt-0.5">
              {contact.position}
            </div>
          )}

          {/* Soft-бейджи: is_primary + source */}
          {(contact.is_primary || contact.source) && (
            <div className="flex flex-wrap gap-1.5 mt-2">
              {contact.is_primary && (
                <span className="badge badge-info text-xs">основной</span>
              )}
              {contact.source && (
                <span className="badge badge-info text-xs">
                  {CONTACT_SOURCE_LABELS[contact.source]}
                </span>
              )}
            </div>
          )}

          {/* Быстрые контакты */}
          {(contact.phone || contact.email || contact.tg_username) && (
            <div className="flex flex-wrap items-center gap-x-4 gap-y-1 mt-3 text-sm">
              {contact.phone && (
                <a
                  href={`tel:${contact.phone}`}
                  className="flex items-center gap-1.5 text-gray-600 dark:text-gray-300 hover:text-primary dark:hover:text-primary transition-colors"
                >
                  <i className="bi bi-telephone text-gray-400 text-xs" aria-hidden="true" />
                  {contact.phone}
                </a>
              )}
              {contact.email && (
                <a
                  href={`mailto:${contact.email}`}
                  className="flex items-center gap-1.5 text-gray-600 dark:text-gray-300 hover:text-primary dark:hover:text-primary transition-colors truncate max-w-[220px]"
                >
                  <i className="bi bi-envelope text-gray-400 text-xs" aria-hidden="true" />
                  {contact.email}
                </a>
              )}
              {contact.tg_username && (
                <span className="flex items-center gap-1.5 text-gray-600 dark:text-gray-300">
                  <i className="bi bi-telegram text-gray-400 text-xs" aria-hidden="true" />
                  {contact.tg_username}
                </span>
              )}
            </div>
          )}
        </div>

        {/* Action-кнопки */}
        <div className="flex items-center gap-2 shrink-0 ml-2">
          <button
            type="button"
            className="btn-ghost text-sm"
            onClick={onBack}
          >
            <i className="bi bi-arrow-left mr-1" aria-hidden="true" />
            К списку
          </button>
          <button
            type="button"
            className="btn-secondary text-sm"
            onClick={onEdit}
          >
            <i
              className={`bi ${editMode ? "bi-check2" : "bi-pencil"} mr-1`}
              aria-hidden="true"
            />
            {editMode ? "Готово" : "Редактировать"}
          </button>
        </div>
      </div>
    </div>
  );
}
