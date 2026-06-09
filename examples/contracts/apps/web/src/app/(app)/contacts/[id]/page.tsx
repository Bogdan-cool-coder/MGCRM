"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import useSWR from "swr";
import { ContactHero } from "@/components/CRM/ContactHero";
import { BlurFade } from "@/components/magicui/BlurFade";
import { Timeline } from "@/components/Timeline";
import { CustomFieldsBlock } from "@/components/CustomFields/CustomFieldsBlock";
import { AuditLogTimeline } from "@/components/AuditLog/AuditLogTimeline";
import { InlineEditableField } from "@/components/CRM/InlineEditableField";
import { FilesTab } from "@/components/CRM/FilesTab";
import { ContactCompaniesTab } from "@/components/CRM/ContactCompaniesTab";
import { RelatedDealsTab } from "@/components/CRM/RelatedDealsTab";
import { ContactDocumentsTab } from "@/components/CRM/ContactDocumentsTab";
import { ContactRightRail } from "@/components/CRM/ContactRightRail";
import { SourceSelect } from "@/components/CRM/SourceSelect";
import { PositionSearch } from "@/components/CRM/PositionSearch";
import { PrimaryContactWarningModal } from "@/components/CRM/PrimaryContactWarningModal";
import { UserSelect } from "@/components/UserSelect";
import { api, fetcher } from "@/lib/api";
import type { Contact, ContactSource, User } from "@/lib/types";
import { CONTACT_SOURCE_LABELS } from "@/lib/types";
import { formatDate } from "@/lib/dates";

// ── Tabs ─────────────────────────────────────────────────────────────────────

type ContactTab =
  | "overview"
  | "timeline"
  | "files"
  | "audit"
  | "deals"
  | "companies"
  | "documents";

const TABS: { key: ContactTab; label: string; icon: string }[] = [
  { key: "overview",   label: "Обзор",        icon: "bi-info-circle" },
  { key: "timeline",  label: "Активности",    icon: "bi-clock-history" },
  { key: "files",     label: "Файлы",         icon: "bi-folder" },
  { key: "audit",     label: "История изм.",  icon: "bi-journal-text" },
  { key: "deals",     label: "Сделки",        icon: "bi-kanban" },
  { key: "companies", label: "Компании",      icon: "bi-building" },
  { key: "documents", label: "Документы",     icon: "bi-file-earmark-text" },
];

// ── Page ──────────────────────────────────────────────────────────────────────

export default function ContactCardPage() {
  const id = Number(useParams().id);
  const router = useRouter();

  const [tab, setTab] = useState<ContactTab>("overview");
  const [editMode, setEditMode] = useState(false);
  const [primaryWarningOpen, setPrimaryWarningOpen] = useState(false);

  const { data: contact, mutate: mContact, error: contactError } =
    useSWR<Contact>(`/contacts/${id}`, fetcher);

  const { data: users } = useSWR<User[]>("/users", fetcher);
  const ownerName =
    contact?.owner_id && users
      ? (users.find((u) => u.id === contact.owner_id)?.full_name ??
        `#${contact.owner_id}`)
      : null;

  // ── Patch helper ──────────────────────────────────────────────────────────

  async function patch(body: Record<string, unknown>) {
    await api<Contact>(`/contacts/${id}`, { method: "PATCH", body });
    await mContact();
  }

  // ── Loading / Error states ────────────────────────────────────────────────

  if (contactError) {
    return (
      <div className="p-8">
        <div className="mx-8 mt-6 text-sm text-danger bg-danger/10 px-3 py-2 rounded-lg">
          Не удалось загрузить контакт (id={id}).
        </div>
        <Link
          href="/contacts"
          className="btn-ghost text-sm mt-3 ml-8 inline-flex items-center gap-1"
        >
          <i className="bi bi-arrow-left" /> К списку
        </Link>
      </div>
    );
  }

  if (!contact) {
    return (
      <div className="flex flex-col bg-gray-50 dark:bg-gray-900">
        {/* Hero skeleton */}
        <div className="mx-8 mt-6 rounded-2xl bg-white dark:bg-gray-800 shadow-elev-2 p-6">
          <div className="flex items-start gap-5">
            <div className="w-14 h-14 rounded-full bg-gray-100 dark:bg-gray-700 animate-pulse shrink-0" />
            <div className="flex-1 space-y-2">
              <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-16" />
              <div className="h-5 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-48" />
              <div className="h-3 bg-gray-100 dark:bg-gray-700 rounded animate-pulse w-32" />
            </div>
          </div>
        </div>
        {/* Tabs skeleton */}
        <div className="px-8 mt-4 flex gap-4">
          {[80, 96, 64, 96, 72, 88, 96].map((w, i) => (
            <div
              key={i}
              className="h-3 bg-gray-100 dark:bg-gray-700 rounded animate-pulse"
              style={{ width: w }}
            />
          ))}
        </div>
      </div>
    );
  }

  // ── Derived ───────────────────────────────────────────────────────────────

  const metaParts: string[] = [];
  if (contact.position) metaParts.push(contact.position);

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className="flex flex-col bg-gray-50 dark:bg-gray-900">
      {/* Hero (replaces PageHeader) */}
      <ContactHero
        contact={contact}
        editMode={editMode}
        onEdit={() => setEditMode((v) => !v)}
        onBack={() => router.push("/contacts")}
      />

      {/* Tabs — border-b-2 underline style */}
      <div className="px-8 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 flex gap-0 flex-wrap mt-4">
        {TABS.map((t) => (
          <button
            key={t.key}
            type="button"
            onClick={() => setTab(t.key)}
            className={`px-4 py-2.5 text-sm transition-colors duration-150 border-b-2 ${
              tab === t.key
                ? "border-primary text-primary font-medium"
                : "border-transparent text-gray-500 dark:text-gray-400 hover:text-primary dark:hover:text-primary hover:border-primary/30"
            }`}
          >
            <i className={`bi ${t.icon} mr-1`} aria-hidden="true" />
            {t.label}
          </button>
        ))}
      </div>

      {/* Content area — BlurFade remounts on tab switch via key */}
      <div className="flex flex-1" key={tab}>
        <BlurFade duration={0.15} className="flex-1 p-8 min-w-0">

          {/* ── Обзор ── */}
          {tab === "overview" && (
            <div className="space-y-6 max-w-2xl">
              {/* Notes */}
              {(contact.notes || editMode) && (
                <div className="card rounded-xl shadow-elev-1 p-4 bg-white dark:bg-gray-800">
                  <div className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">
                    Заметки
                  </div>
                  {editMode ? (
                    <InlineEditableField
                      value={contact.notes}
                      label=""
                      editing
                      kind="textarea"
                      onSave={(v) => patch({ notes: v || null })}
                    />
                  ) : (
                    <div className="text-sm whitespace-pre-wrap text-gray-700 dark:text-gray-300">
                      {contact.notes || "—"}
                    </div>
                  )}
                </div>
              )}

              {/* Fields grid */}
              <div className="card rounded-xl shadow-elev-1 p-4 bg-white dark:bg-gray-800">
                <div className="grid md:grid-cols-2 gap-x-6">
                  {/* ФИО */}
                  {editMode ? (
                    <InlineEditableField
                      value={contact.full_name}
                      label="ФИО"
                      editing
                      kind="text"
                      onSave={(v) => patch({ full_name: v })}
                    />
                  ) : (
                    <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                      <span className="text-gray-500 dark:text-gray-400 text-sm">ФИО</span>
                      <span className="text-sm text-right text-gray-900 dark:text-gray-100">
                        {contact.full_name}
                      </span>
                    </div>
                  )}

                  {/* Должность */}
                  {editMode ? (
                    <div className="border-b border-gray-100 dark:border-gray-700 py-2">
                      <label className="text-xs text-gray-500 dark:text-gray-400 mb-1 block">
                        Должность
                      </label>
                      <PositionSearch
                        className="input text-sm"
                        value={contact.position ?? ""}
                        onChange={(v) => void patch({ position: v || null })}
                        placeholder="Должность"
                      />
                    </div>
                  ) : (
                    <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                      <span className="text-gray-500 dark:text-gray-400 text-sm">Должность</span>
                      <span className="text-sm text-right text-gray-900 dark:text-gray-100">
                        {contact.position ?? "—"}
                      </span>
                    </div>
                  )}

                  {/* Источник */}
                  {editMode ? (
                    <div className="border-b border-gray-100 dark:border-gray-700 py-2">
                      <label className="text-xs text-gray-500 dark:text-gray-400 mb-1 block">
                        Источник
                      </label>
                      <SourceSelect
                        className="input text-sm"
                        value={contact.source ?? ""}
                        onChange={(v) =>
                          void patch({ source: (v as ContactSource) || null })
                        }
                      />
                    </div>
                  ) : (
                    <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                      <span className="text-gray-500 dark:text-gray-400 text-sm">Источник</span>
                      <span className="text-sm text-right text-gray-900 dark:text-gray-100">
                        {contact.source ? CONTACT_SOURCE_LABELS[contact.source] : "—"}
                      </span>
                    </div>
                  )}

                  {/* Email */}
                  {editMode ? (
                    <InlineEditableField
                      value={contact.email}
                      label="Email"
                      editing
                      kind="text"
                      placeholder="email@example.com"
                      onSave={(v) => patch({ email: v || null })}
                    />
                  ) : (
                    <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                      <span className="text-gray-500 dark:text-gray-400 text-sm">Email</span>
                      <span className="text-sm text-right">
                        {contact.email ? (
                          <a
                            href={`mailto:${contact.email}`}
                            className="text-primary hover:underline"
                          >
                            {contact.email}
                          </a>
                        ) : (
                          "—"
                        )}
                      </span>
                    </div>
                  )}

                  {/* Телефон */}
                  {editMode ? (
                    <InlineEditableField
                      value={contact.phone}
                      label="Телефон"
                      editing
                      kind="text"
                      placeholder="+7 999 000 00 00"
                      onSave={(v) => patch({ phone: v || null })}
                    />
                  ) : (
                    <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                      <span className="text-gray-500 dark:text-gray-400 text-sm">Телефон</span>
                      <span className="text-sm text-right">
                        {contact.phone ? (
                          <a
                            href={`tel:${contact.phone}`}
                            className="text-primary hover:underline"
                          >
                            {contact.phone}
                          </a>
                        ) : (
                          "—"
                        )}
                      </span>
                    </div>
                  )}

                  {/* Telegram */}
                  {editMode ? (
                    <InlineEditableField
                      value={contact.tg_username}
                      label="Telegram"
                      editing
                      kind="text"
                      placeholder="@username"
                      onSave={(v) => patch({ tg_username: v || null })}
                    />
                  ) : (
                    <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                      <span className="text-gray-500 dark:text-gray-400 text-sm">Telegram</span>
                      <span className="text-sm text-right text-gray-900 dark:text-gray-100">
                        {contact.tg_username ?? "—"}
                      </span>
                    </div>
                  )}

                  {/* Ответственный */}
                  {editMode ? (
                    <div className="border-b border-gray-100 dark:border-gray-700 py-2">
                      <label className="text-xs text-gray-500 dark:text-gray-400 mb-1 block">
                        Ответственный
                      </label>
                      <UserSelect
                        className="input text-sm"
                        value={
                          contact.owner_id ? String(contact.owner_id) : ""
                        }
                        onChange={(v) =>
                          void patch({ owner_id: v ? Number(v) : null })
                        }
                        users={users}
                      />
                    </div>
                  ) : (
                    <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                      <span className="text-gray-500 dark:text-gray-400 text-sm">
                        Ответственный
                      </span>
                      <span className="text-sm text-right text-gray-900 dark:text-gray-100">
                        {ownerName ?? "—"}
                      </span>
                    </div>
                  )}

                  {/* Основной */}
                  <div className="border-b border-gray-100 dark:border-gray-700 py-2 flex justify-between gap-4 items-center">
                    <span className="text-gray-500 dark:text-gray-400 text-sm">
                      Основной контакт
                    </span>
                    {editMode ? (
                      <input
                        type="checkbox"
                        className="accent-primary"
                        checked={contact.is_primary}
                        onChange={(e) => {
                          if (e.target.checked) {
                            setPrimaryWarningOpen(true);
                          } else {
                            void patch({ is_primary: false });
                          }
                        }}
                      />
                    ) : (
                      <span className="text-sm text-right">
                        {contact.is_primary ? (
                          <span className="text-primary">
                            <i className="bi bi-check2" /> Да
                          </span>
                        ) : (
                          "—"
                        )}
                      </span>
                    )}
                  </div>

                  {/* Создан / Обновлён */}
                  <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                    <span className="text-gray-500 dark:text-gray-400 text-sm">Создан</span>
                    <span className="text-sm text-right text-gray-900 dark:text-gray-100">
                      {formatDate(contact.created_at)}
                    </span>
                  </div>
                  <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                    <span className="text-gray-500 dark:text-gray-400 text-sm">Обновлён</span>
                    <span className="text-sm text-right text-gray-900 dark:text-gray-100">
                      {formatDate(contact.updated_at)}
                    </span>
                  </div>
                </div>
              </div>

              {/* Custom fields */}
              <CustomFieldsBlock
                entityScope="contact"
                entityId={id}
                extraFields={contact.extra_fields ?? {}}
                onSaved={() => mContact()}
              />
            </div>
          )}

          {/* ── Активности ── */}
          {tab === "timeline" && (
            <div className="max-w-3xl">
              <Timeline targetType="contact" targetId={id} />
            </div>
          )}

          {/* ── Файлы ── */}
          {tab === "files" && (
            <FilesTab entityType="contact" entityId={id} editMode={editMode} />
          )}

          {/* ── История изменений ── */}
          {tab === "audit" && (
            <div className="max-w-3xl">
              <AuditLogTimeline entityType="contact" entityId={id} />
            </div>
          )}

          {/* ── Сделки ── */}
          {tab === "deals" && (
            <RelatedDealsTab entityType="contact" entityId={id} />
          )}

          {/* ── Компании ── */}
          {tab === "companies" && (
            <ContactCompaniesTab contactId={id} editMode={editMode} />
          )}

          {/* ── Документы ── */}
          {tab === "documents" && (
            <ContactDocumentsTab entityType="contact" entityId={id} />
          )}
        </BlurFade>

        {/* Right rail */}
        <ContactRightRail
          contact={contact}
          editMode={editMode}
          onSaved={() => mContact()}
        />
      </div>

      {/* Primary warning modal */}
      <PrimaryContactWarningModal
        open={primaryWarningOpen}
        existing={null}
        onCancel={() => setPrimaryWarningOpen(false)}
        onConfirm={() => {
          setPrimaryWarningOpen(false);
          void patch({ is_primary: true });
        }}
      />
    </div>
  );
}
