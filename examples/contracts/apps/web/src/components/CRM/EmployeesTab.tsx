"use client";

import { useRef, useState, useCallback, useEffect } from "react";
import Link from "next/link";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { PositionSearch } from "./PositionSearch";
import { ContactQuickForm } from "./ContactQuickForm";
import type { Contact } from "@/lib/types";

// ── Types ────────────────────────────────────────────────────────────────────

interface EmployeeRow {
  id: number;
  contact_id: number;
  company_id: number;
  position: string | null;
  position_id: number | null;
  employment_status: "works" | "left";
  is_primary: boolean;
  created_at: string | null;
  updated_at: string | null;
  contact_full_name: string;
  contact_phone: string | null;
  contact_email: string | null;
}

function isEmployeeArray(v: unknown): v is EmployeeRow[] {
  return Array.isArray(v) && (
    v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "contact_id" in v[0])
  );
}

function isContactArray(v: unknown): v is Contact[] {
  return Array.isArray(v) && (
    v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "full_name" in v[0])
  );
}

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  companyId: number;
  editMode: boolean;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function EmployeesTab({ companyId, editMode }: Props) {
  const swrKey = `/companies/${companyId}/employees`;
  const { data: rawEmployees, mutate, error: empError } = useSWR<unknown>(swrKey, fetcher);
  const employees = isEmployeeArray(rawEmployees) ? rawEmployees : [];

  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<EmployeeRow | null>(null);
  const [editingId, setEditingId] = useState<number | null>(null); // contact_id
  const [editPos, setEditPos] = useState("");
  const [editStatus, setEditStatus] = useState<"works" | "left">("works");
  const [saving, setSaving] = useState(false);

  // Add state
  const [adding, setAdding] = useState(false);
  const [contactQuery, setContactQuery] = useState("");
  const [contactSuggestions, setContactSuggestions] = useState<Contact[]>([]);
  const [contactDropOpen, setContactDropOpen] = useState(false);
  const [selectedContact, setSelectedContact] = useState<Contact | null>(null);
  const [addPos, setAddPos] = useState("");
  const [addStatus, setAddStatus] = useState<"works" | "left">("works");
  const contactTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const contactDropRef = useRef<HTMLDivElement>(null);

  // Quick form for creating new contact
  const [quickFormOpen, setQuickFormOpen] = useState(false);

  // ── Contact search ────────────────────────────────────────────────────────

  const fetchContacts = useCallback(async (q: string) => {
    if (q.trim().length < 1) { setContactSuggestions([]); return; }
    try {
      const res = await api<unknown>(`/contacts?q=${encodeURIComponent(q)}&limit=10`);
      if (isContactArray(res)) setContactSuggestions(res);
    } catch { /* silent */ }
  }, []);

  function handleContactQueryChange(q: string) {
    setContactQuery(q);
    if (contactTimerRef.current) clearTimeout(contactTimerRef.current);
    contactTimerRef.current = setTimeout(() => fetchContacts(q), 300);
    setContactDropOpen(true);
  }

  function selectContact(c: Contact) {
    setSelectedContact(c);
    setContactQuery(c.full_name);
    setContactDropOpen(false);
    setContactSuggestions([]);
  }

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (contactDropRef.current && !contactDropRef.current.contains(e.target as Node)) {
        setContactDropOpen(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // ── Inline edit ───────────────────────────────────────────────────────────

  function startEdit(row: EmployeeRow) {
    setEditingId(row.contact_id);
    setEditPos(row.position ?? "");
    setEditStatus(row.employment_status);
  }

  async function saveEdit(contactId: number) {
    setSaving(true);
    setError(null);
    try {
      await api(`/companies/${companyId}/contacts/${contactId}`, {
        method: "PATCH",
        body: { position: editPos.trim() || null, employment_status: editStatus },
      });
      await mutate();
      setEditingId(null);
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить");
    } finally {
      setSaving(false);
    }
  }

  // ── Delete ────────────────────────────────────────────────────────────────

  async function handleDelete(row: EmployeeRow) {
    setConfirmDelete(null);
    setError(null);
    try {
      await api(`/companies/${companyId}/contacts/${row.contact_id}`, { method: "DELETE" });
      await mutate();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить");
    }
  }

  // ── Add employee ──────────────────────────────────────────────────────────

  async function handleAdd() {
    if (!selectedContact) { setError("Выберите контакт"); return; }
    setSaving(true);
    setError(null);
    try {
      await api(`/companies/${companyId}/contacts`, {
        method: "POST",
        body: {
          contact_id: selectedContact.id,
          position: addPos.trim() || null,
          employment_status: addStatus,
        },
      });
      await mutate();
      setAdding(false);
      setSelectedContact(null);
      setContactQuery("");
      setAddPos("");
      setAddStatus("works");
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось добавить");
    } finally {
      setSaving(false);
    }
  }

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className="space-y-3 max-w-4xl">
      {error && (
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-center justify-between gap-2">
          <span>{error}</span>
          <button type="button" onClick={() => setError(null)}><i className="bi bi-x" /></button>
        </div>
      )}

      {empError && (
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
          Не удалось загрузить сотрудников.
        </div>
      )}

      {employees.length === 0 && !empError ? (
        <div className="flex flex-col items-center gap-2 py-10 text-gray-400 dark:text-gray-500">
          <i className="bi bi-people text-3xl" />
          <span className="text-sm">У компании пока нет сотрудников</span>
          {editMode && (
            <button
              type="button"
              className="btn-secondary text-sm mt-1"
              onClick={() => setAdding(true)}
            >
              <i className="bi bi-plus-lg mr-1" /> Добавить сотрудника
            </button>
          )}
        </div>
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                <th className="py-2 pr-4 font-medium">ФИО</th>
                <th className="py-2 pr-4 font-medium">Должность</th>
                <th className="py-2 pr-4 font-medium">Статус</th>
                <th className="py-2 pr-4 font-medium">Телефон</th>
                <th className="py-2 pr-4 font-medium">Email</th>
                {editMode && <th className="py-2 font-medium"></th>}
              </tr>
            </thead>
            <tbody>
              {employees.map((emp) => (
                <tr key={emp.contact_id} className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                  <td className="py-2 pr-4">
                    <Link href={`/contacts/${emp.contact_id}`} className="text-primary hover:underline font-medium">
                      {emp.contact_full_name}
                    </Link>
                    {emp.is_primary && (
                      <span className="ml-2 text-[10px] uppercase tracking-wide bg-primary/10 text-primary px-1.5 py-0.5 rounded">
                        основной
                      </span>
                    )}
                  </td>
                  <td className="py-2 pr-4">
                    {editMode && editingId === emp.contact_id ? (
                      <PositionSearch
                        className="input text-sm py-1"
                        value={editPos}
                        onChange={setEditPos}
                        placeholder="Должность"
                      />
                    ) : (
                      <span className="text-gray-900 dark:text-gray-100">{emp.position ?? "—"}</span>
                    )}
                  </td>
                  <td className="py-2 pr-4">
                    {editMode && editingId === emp.contact_id ? (
                      <select
                        className="input text-sm py-1"
                        value={editStatus}
                        onChange={(e) => setEditStatus(e.target.value as "works" | "left")}
                      >
                        <option value="works">Работает</option>
                        <option value="left">Больше не работает</option>
                      </select>
                    ) : (
                      <span className={emp.employment_status === "left" ? "text-gray-400" : "text-gray-900 dark:text-gray-100"}>
                        {emp.employment_status === "left" ? "Больше не работает" : "Работает"}
                      </span>
                    )}
                  </td>
                  <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{emp.contact_phone ?? "—"}</td>
                  <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">{emp.contact_email ?? "—"}</td>
                  {editMode && (
                    <td className="py-2">
                      {editingId === emp.contact_id ? (
                        <div className="flex items-center gap-1">
                          <button
                            type="button"
                            className="btn-primary text-xs py-1 px-2"
                            disabled={saving}
                            onClick={() => void saveEdit(emp.contact_id)}
                          >
                            {saving ? "…" : "OK"}
                          </button>
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2"
                            onClick={() => setEditingId(null)}
                          >
                            Отмена
                          </button>
                        </div>
                      ) : (
                        <div className="flex items-center gap-1">
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2"
                            onClick={() => startEdit(emp)}
                            title="Редактировать"
                          >
                            <i className="bi bi-pencil" />
                          </button>
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2 text-danger"
                            onClick={() => setConfirmDelete(emp)}
                            title="Удалить"
                          >
                            <i className="bi bi-trash" />
                          </button>
                        </div>
                      )}
                    </td>
                  )}
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Add employee controls */}
      {editMode && (
        adding ? (
          <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-3 space-y-2 bg-gray-50 dark:bg-gray-800/50">
            <div className="grid grid-cols-3 gap-2">
              {/* Contact search */}
              <div ref={contactDropRef} className="relative">
                <label className="label text-xs">Контакт <span className="text-danger">*</span></label>
                {selectedContact ? (
                  <div className="input text-sm flex items-center justify-between bg-white dark:bg-gray-800">
                    <span>{selectedContact.full_name}</span>
                    <button type="button" onClick={() => { setSelectedContact(null); setContactQuery(""); }}>
                      <i className="bi bi-x text-gray-400" />
                    </button>
                  </div>
                ) : (
                  <input
                    className="input text-sm"
                    value={contactQuery}
                    onChange={(e) => handleContactQueryChange(e.target.value)}
                    placeholder="Поиск контакта…"
                    autoComplete="off"
                  />
                )}
                {contactDropOpen && !selectedContact && contactSuggestions.length > 0 && (
                  <div className="absolute z-20 top-full mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-40 overflow-y-auto">
                    {contactSuggestions.map((c) => (
                      <button
                        key={c.id}
                        type="button"
                        onClick={() => selectContact(c)}
                        className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100"
                      >
                        <span className="font-medium">{c.full_name}</span>
                        {c.position && <span className="text-xs text-gray-400 ml-2">{c.position}</span>}
                      </button>
                    ))}
                  </div>
                )}
              </div>

              {/* Position */}
              <div>
                <label className="label text-xs">Должность</label>
                <PositionSearch
                  className="input text-sm"
                  value={addPos}
                  onChange={setAddPos}
                  placeholder="Директор…"
                />
              </div>

              {/* Status */}
              <div>
                <label className="label text-xs">Статус</label>
                <select
                  className="input text-sm"
                  value={addStatus}
                  onChange={(e) => setAddStatus(e.target.value as "works" | "left")}
                >
                  <option value="works">Работает</option>
                  <option value="left">Больше не работает</option>
                </select>
              </div>
            </div>

            <div className="flex items-center gap-2 flex-wrap">
              <button
                type="button"
                className="btn-primary text-sm"
                disabled={saving || !selectedContact}
                onClick={() => void handleAdd()}
              >
                {saving ? "Сохранение…" : "Сохранить"}
              </button>
              <button
                type="button"
                className="btn-ghost text-sm"
                onClick={() => {
                  setAdding(false);
                  setSelectedContact(null);
                  setContactQuery("");
                  setAddPos("");
                  setAddStatus("works");
                  setError(null);
                }}
              >
                Отмена
              </button>
              <button
                type="button"
                className="btn-secondary text-sm"
                onClick={() => setQuickFormOpen(true)}
              >
                <i className="bi bi-person-plus-fill mr-1" /> Создать нового
              </button>
            </div>
          </div>
        ) : (
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="btn-secondary text-sm"
              onClick={() => setAdding(true)}
            >
              <i className="bi bi-plus-lg mr-1" /> Добавить сотрудника
            </button>
            <button
              type="button"
              className="btn-ghost text-sm"
              onClick={() => setQuickFormOpen(true)}
            >
              <i className="bi bi-person-plus-fill mr-1" /> Создать нового
            </button>
          </div>
        )
      )}

      {/* Confirm delete */}
      <Modal
        open={confirmDelete != null}
        title="Удалить сотрудника из компании?"
        onClose={() => setConfirmDelete(null)}
        width="sm"
        footer={
          <>
            <button type="button" className="btn-secondary" onClick={() => setConfirmDelete(null)}>Отмена</button>
            <button
              type="button"
              className="btn-primary"
              onClick={() => confirmDelete && void handleDelete(confirmDelete)}
            >
              Удалить
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          «{confirmDelete?.contact_full_name}» будет отвязан от компании.
        </p>
      </Modal>

      {/* Quick create contact */}
      <ContactQuickForm
        open={quickFormOpen}
        onClose={() => setQuickFormOpen(false)}
        onCreated={() => { void mutate(); }}
      />
    </div>
  );
}
