"use client";

import { useRef, useState, useCallback, useEffect } from "react";
import Link from "next/link";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { EmptyState } from "@/components/EmptyState";
import { PositionSearch } from "./PositionSearch";
import type { Company } from "@/lib/types";

// ── Types ────────────────────────────────────────────────────────────────────

interface ContactCompanyRow {
  company_id: number;
  company_name: string;
  company_phone: string | null;
  company_email: string | null;
  position: string | null;
  position_id: number | null;
  employment_status: "works" | "left";
  is_primary: boolean;
}

function isContactCompanyArray(v: unknown): v is ContactCompanyRow[] {
  return Array.isArray(v) && (
    v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "company_id" in v[0])
  );
}

function isCompanyArray(v: unknown): v is Company[] {
  return Array.isArray(v) && (
    v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "legal_name" in v[0])
  );
}

const EMPLOYMENT_STATUS_LABELS: Record<"works" | "left", string> = {
  works: "Работает",
  left: "Больше не работает",
};

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  contactId: number;
  editMode: boolean;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function ContactCompaniesTab({ contactId, editMode }: Props) {
  const swrKey = `/contacts/${contactId}/companies`;
  const { data: rawLinks, mutate, error: linksError } = useSWR<unknown>(swrKey, fetcher);
  const links = isContactCompanyArray(rawLinks) ? rawLinks : [];

  const [error, setError] = useState<string | null>(null);
  const [confirmDelete, setConfirmDelete] = useState<ContactCompanyRow | null>(null);
  const [editingRow, setEditingRow] = useState<number | null>(null); // company_id
  const [editPos, setEditPos] = useState("");
  const [editStatus, setEditStatus] = useState<"works" | "left">("works");
  const [saving, setSaving] = useState(false);

  // Add row state
  const [adding, setAdding] = useState(false);
  const [companyQuery, setCompanyQuery] = useState("");
  const [companySuggestions, setCompanySuggestions] = useState<Company[]>([]);
  const [companyDropOpen, setCompanyDropOpen] = useState(false);
  const [selectedCompany, setSelectedCompany] = useState<Company | null>(null);
  const [addPos, setAddPos] = useState("");
  const [addStatus, setAddStatus] = useState<"works" | "left">("works");
  const companyTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const companyDropRef = useRef<HTMLDivElement>(null);

  // ── Company search ────────────────────────────────────────────────────────

  const fetchCompanies = useCallback(async (q: string) => {
    if (q.trim().length < 1) { setCompanySuggestions([]); return; }
    try {
      const res = await api<unknown>(`/companies?q=${encodeURIComponent(q)}&limit=10`);
      if (isCompanyArray(res)) setCompanySuggestions(res);
    } catch { /* silent */ }
  }, []);

  function handleCompanyQueryChange(q: string) {
    setCompanyQuery(q);
    if (companyTimerRef.current) clearTimeout(companyTimerRef.current);
    companyTimerRef.current = setTimeout(() => fetchCompanies(q), 300);
    setCompanyDropOpen(true);
  }

  function selectCompany(c: Company) {
    setSelectedCompany(c);
    setCompanyQuery(c.legal_name);
    setCompanyDropOpen(false);
    setCompanySuggestions([]);
  }

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (companyDropRef.current && !companyDropRef.current.contains(e.target as Node)) {
        setCompanyDropOpen(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // ── Inline edit ───────────────────────────────────────────────────────────

  function startEdit(row: ContactCompanyRow) {
    setEditingRow(row.company_id);
    setEditPos(row.position ?? "");
    setEditStatus(row.employment_status);
  }

  async function saveEdit(companyId: number) {
    setSaving(true);
    setError(null);
    try {
      await api(`/contacts/${contactId}/companies/${companyId}`, {
        method: "PATCH",
        body: { position: editPos.trim() || null, employment_status: editStatus },
      });
      await mutate();
      setEditingRow(null);
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить");
    } finally {
      setSaving(false);
    }
  }

  // ── Delete ────────────────────────────────────────────────────────────────

  async function handleDelete(row: ContactCompanyRow) {
    setConfirmDelete(null);
    setError(null);
    try {
      await api(`/contacts/${contactId}/companies/${row.company_id}`, { method: "DELETE" });
      await mutate();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось удалить связь");
    }
  }

  // ── Add link ──────────────────────────────────────────────────────────────

  async function handleAdd() {
    if (!selectedCompany) { setError("Выберите компанию"); return; }
    setSaving(true);
    setError(null);
    try {
      await api(`/contacts/${contactId}/companies`, {
        method: "POST",
        body: {
          company_id: selectedCompany.id,
          position: addPos.trim() || null,
          employment_status: addStatus,
        },
      });
      await mutate();
      setAdding(false);
      setSelectedCompany(null);
      setCompanyQuery("");
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

      {linksError && (
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
          Не удалось загрузить связи с компаниями.
        </div>
      )}

      {links.length === 0 && !linksError ? (
        <EmptyState
          icon="bi-building"
          title="Не привязан к компаниям"
          description="Добавь связь с компанией"
        />
      ) : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                <th className="py-2 pr-4 font-medium">Компания</th>
                <th className="py-2 pr-4 font-medium">Должность</th>
                <th className="py-2 pr-4 font-medium">Статус</th>
                <th className="py-2 pr-4 font-medium">Телефон</th>
                <th className="py-2 pr-4 font-medium">Email</th>
                {editMode && <th className="py-2 font-medium"></th>}
              </tr>
            </thead>
            <tbody>
              {links.map((row) => (
                <tr key={row.company_id} className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                  <td className="py-2 pr-4">
                    <Link href={`/companies/${row.company_id}`} className="text-primary hover:underline font-medium">
                      {row.company_name}
                    </Link>
                    {row.is_primary && (
                      <span className="ml-2 text-[10px] uppercase tracking-wide bg-primary/10 text-primary px-1.5 py-0.5 rounded">
                        основной
                      </span>
                    )}
                  </td>
                  <td className="py-2 pr-4">
                    {editMode && editingRow === row.company_id ? (
                      <PositionSearch
                        className="input text-sm py-1"
                        value={editPos}
                        onChange={setEditPos}
                        placeholder="Должность"
                      />
                    ) : (
                      <span className="text-gray-900 dark:text-gray-100">{row.position ?? "—"}</span>
                    )}
                  </td>
                  <td className="py-2 pr-4">
                    {editMode && editingRow === row.company_id ? (
                      <select
                        className="input text-sm py-1"
                        value={editStatus}
                        onChange={(e) => setEditStatus(e.target.value as "works" | "left")}
                      >
                        <option value="works">Работает</option>
                        <option value="left">Больше не работает</option>
                      </select>
                    ) : (
                      <span className={row.employment_status === "left" ? "text-gray-400" : "text-gray-900 dark:text-gray-100"}>
                        {EMPLOYMENT_STATUS_LABELS[row.employment_status]}
                      </span>
                    )}
                  </td>
                  <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">
                    {row.company_phone ?? "—"}
                  </td>
                  <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">
                    {row.company_email ?? "—"}
                  </td>
                  {editMode && (
                    <td className="py-2">
                      {editingRow === row.company_id ? (
                        <div className="flex items-center gap-1">
                          <button
                            type="button"
                            className="btn-primary text-xs py-1 px-2"
                            disabled={saving}
                            onClick={() => void saveEdit(row.company_id)}
                          >
                            {saving ? "…" : "OK"}
                          </button>
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2"
                            onClick={() => setEditingRow(null)}
                          >
                            Отмена
                          </button>
                        </div>
                      ) : (
                        <div className="flex items-center gap-1">
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2"
                            onClick={() => startEdit(row)}
                            title="Редактировать"
                          >
                            <i className="bi bi-pencil" />
                          </button>
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2 text-danger"
                            onClick={() => setConfirmDelete(row)}
                            title="Удалить связь"
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

      {/* Add link row */}
      {editMode && (
        adding ? (
          <div className="border border-gray-200 dark:border-gray-700 rounded-lg p-3 space-y-2 bg-gray-50 dark:bg-gray-800/50">
            <div className="grid grid-cols-3 gap-2">
              {/* Company search */}
              <div ref={companyDropRef} className="relative">
                <label className="label text-xs">Компания <span className="text-danger">*</span></label>
                {selectedCompany ? (
                  <div className="input text-sm flex items-center justify-between bg-white dark:bg-gray-800">
                    <span>{selectedCompany.legal_name}</span>
                    <button type="button" onClick={() => { setSelectedCompany(null); setCompanyQuery(""); }}>
                      <i className="bi bi-x text-gray-400" />
                    </button>
                  </div>
                ) : (
                  <input
                    className="input text-sm"
                    value={companyQuery}
                    onChange={(e) => handleCompanyQueryChange(e.target.value)}
                    placeholder="Поиск…"
                    autoComplete="off"
                  />
                )}
                {companyDropOpen && !selectedCompany && companySuggestions.length > 0 && (
                  <div className="absolute z-20 top-full mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-40 overflow-y-auto">
                    {companySuggestions.map((c) => (
                      <button
                        key={c.id}
                        type="button"
                        onClick={() => selectCompany(c)}
                        className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100"
                      >
                        {c.legal_name}
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
                <label className="label text-xs">Статус занятости</label>
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

            <div className="flex items-center gap-2">
              <button
                type="button"
                className="btn-primary text-sm"
                disabled={saving || !selectedCompany}
                onClick={() => void handleAdd()}
              >
                {saving ? "Сохранение…" : "Сохранить"}
              </button>
              <button
                type="button"
                className="btn-ghost text-sm"
                onClick={() => {
                  setAdding(false);
                  setSelectedCompany(null);
                  setCompanyQuery("");
                  setAddPos("");
                  setAddStatus("works");
                  setError(null);
                }}
              >
                Отмена
              </button>
            </div>
          </div>
        ) : (
          <button
            type="button"
            className="btn-secondary text-sm"
            onClick={() => setAdding(true)}
          >
            <i className="bi bi-plus-lg mr-1" /> Добавить компанию
          </button>
        )
      )}

      {/* Confirm delete */}
      <Modal
        open={confirmDelete != null}
        title="Убрать из компании?"
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
              Удалить связь
            </button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Контакт будет отвязан от компании «{confirmDelete?.company_name}».
        </p>
      </Modal>
    </div>
  );
}
