"use client";

import { useRef, useState, useCallback, useEffect } from "react";
import Link from "next/link";
import useSWR from "swr";
import { api, ApiError, fetcher } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { HoldingSetupModal } from "./HoldingSetupModal";
import type { Holding, HoldingRole } from "@/lib/types";
import { HOLDING_ROLE_LABELS } from "@/lib/types";
import type { Company } from "@/lib/types";

// ── Types ────────────────────────────────────────────────────────────────────

interface HoldingCompanyRow {
  company_id: number;
  name: string | null;
  legal_name: string | null;
  holding_role: "parent" | "subsidiary";
  country: string | null;
  city: string | null;
}

interface HoldingData {
  holding_id: number | null;
  holding_name: string | null;
  members: HoldingCompanyRow[];
}

function isHoldingData(v: unknown): v is HoldingData {
  return typeof v === "object" && v !== null && "holding_id" in v && "members" in v;
}

function isHoldingArray(v: unknown): v is Holding[] {
  return Array.isArray(v) && (
    v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "name" in v[0])
  );
}

function isCompanyArray(v: unknown): v is Company[] {
  return Array.isArray(v) && (
    v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "legal_name" in v[0])
  );
}

const ROLE_BADGE: Record<string, string> = {
  parent: "bg-primary/10 text-primary",
  subsidiary: "bg-gray-100 dark:bg-gray-700 text-gray-700 dark:text-gray-300",
  main: "bg-primary/10 text-primary",
};

const ROLE_LABEL: Record<string, string> = {
  parent: "Основная",
  subsidiary: "Дочерняя",
  main: "Основная",
};

// ── Props ─────────────────────────────────────────────────────────────────────

interface Props {
  companyId: number;
  editMode: boolean;
}

// ── Component ─────────────────────────────────────────────────────────────────

export function HoldingTab({ companyId, editMode }: Props) {
  const swrKey = `/companies/${companyId}/holding`;
  const { data: rawHolding, mutate, error: holdingError } = useSWR<unknown>(swrKey, fetcher);
  const holdingData = isHoldingData(rawHolding) ? rawHolding : null;

  const [error, setError] = useState<string | null>(null);
  const [editingRoleId, setEditingRoleId] = useState<number | null>(null); // tracks company_id
  const [editRole, setEditRole] = useState<"parent" | "subsidiary">("subsidiary");
  const [saving, setSaving] = useState(false);

  // Confirm modals
  const [confirmRemove, setConfirmRemove] = useState<HoldingCompanyRow | null>(null);
  const [confirmLeave, setConfirmLeave] = useState(false);
  const [parentConflictPending, setParentConflictPending] = useState<{ linkedId: number; role: "parent" | "subsidiary" } | null>(null);

  // Holding search (for adding company to holding or joining one)
  const [holdingSearchOpen, setHoldingSearchOpen] = useState(false);
  const [holdingQuery, setHoldingQuery] = useState("");
  const [holdingSuggestions, setHoldingSuggestions] = useState<Holding[]>([]);
  const [holdingDropOpen, setHoldingDropOpen] = useState(false);
  const [holdingSetupOpen, setHoldingSetupOpen] = useState(false);
  const holdingTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const holdingDropRef = useRef<HTMLDivElement>(null);

  // Company search (for adding company to existing holding)
  const [companySearchOpen, setCompanySearchOpen] = useState(false);
  const [companyQuery, setCompanyQuery] = useState("");
  const [companySuggestions, setCompanySuggestions] = useState<Company[]>([]);
  const [companyDropOpen, setCompanyDropOpen] = useState(false);
  const [selectedCompanyToAdd, setSelectedCompanyToAdd] = useState<Company | null>(null);
  const [addRole, setAddRole] = useState<"parent" | "subsidiary">("subsidiary");
  const companyTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const companyDropRef = useRef<HTMLDivElement>(null);

  // ── Holding search ────────────────────────────────────────────────────────

  const fetchHoldings = useCallback(async (q: string) => {
    if (q.trim().length < 1) { setHoldingSuggestions([]); return; }
    try {
      const res = await api<unknown>(`/holdings?q=${encodeURIComponent(q)}&limit=10`);
      if (isHoldingArray(res)) setHoldingSuggestions(res);
    } catch { /* silent */ }
  }, []);

  function handleHoldingQueryChange(q: string) {
    setHoldingQuery(q);
    if (holdingTimerRef.current) clearTimeout(holdingTimerRef.current);
    holdingTimerRef.current = setTimeout(() => fetchHoldings(q), 300);
    setHoldingDropOpen(true);
  }

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (holdingDropRef.current && !holdingDropRef.current.contains(e.target as Node)) {
        setHoldingDropOpen(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

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

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (companyDropRef.current && !companyDropRef.current.contains(e.target as Node)) {
        setCompanyDropOpen(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // ── Actions ───────────────────────────────────────────────────────────────

  async function joinHolding(holdingId: number, role: HoldingRole) {
    setSaving(true);
    setError(null);
    try {
      await api(`/companies/${companyId}/holding-links`, {
        method: "POST",
        body: { holding_id: holdingId, role },
      });
      await mutate();
      setHoldingSearchOpen(false);
      setHoldingQuery("");
      setHoldingSuggestions([]);
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        // parent conflict — ask confirmation
        setParentConflictPending({ linkedId: holdingId, role: role as "parent" | "subsidiary" });
      } else {
        setError(err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка");
      }
    } finally {
      setSaving(false);
    }
  }

  async function joinHoldingWithConfirm() {
    if (!parentConflictPending) return;
    setSaving(true);
    setError(null);
    const { linkedId, role } = parentConflictPending;
    setParentConflictPending(null);
    try {
      await api(`/companies/${companyId}/holding-links`, {
        method: "POST",
        body: { holding_id: linkedId, role, confirm: true },
      });
      await mutate();
      setHoldingSearchOpen(false);
      setHoldingQuery("");
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка");
    } finally {
      setSaving(false);
    }
  }

  async function updateRole(linkedId: number, role: "parent" | "subsidiary") {
    setSaving(true);
    setError(null);
    try {
      await api(`/companies/${companyId}/holding-links/${linkedId}`, {
        method: "PATCH",
        body: { role },
      });
      await mutate();
      setEditingRoleId(null);
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setParentConflictPending({ linkedId, role });
      } else {
        setError(err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка");
      }
    } finally {
      setSaving(false);
    }
  }

  async function removeCompanyFromHolding(row: HoldingCompanyRow) {
    setConfirmRemove(null);
    setError(null);
    try {
      await api(`/companies/${companyId}/holding-links/${row.company_id}`, { method: "DELETE" });
      await mutate();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка");
    }
  }

  async function leaveHolding() {
    setConfirmLeave(false);
    setError(null);
    // Remove self from holding: use holding_id
    if (!holdingData) return;
    try {
      await api(`/companies/${companyId}/holding-links/${companyId}`, { method: "DELETE" });
      await mutate();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка");
    }
  }

  async function addCompanyToHolding() {
    if (!selectedCompanyToAdd || !holdingData) return;
    setSaving(true);
    setError(null);
    try {
      await api(`/companies/${selectedCompanyToAdd.id}/holding-links`, {
        method: "POST",
        body: { holding_id: holdingData.holding_id, role: addRole },
      });
      await mutate();
      setCompanySearchOpen(false);
      setSelectedCompanyToAdd(null);
      setCompanyQuery("");
    } catch (err) {
      if (err instanceof ApiError && err.status === 409) {
        setParentConflictPending({ linkedId: selectedCompanyToAdd.id, role: addRole });
      } else {
        setError(err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Ошибка");
      }
    } finally {
      setSaving(false);
    }
  }

  // ── Render ────────────────────────────────────────────────────────────────

  if (holdingError) {
    return (
      <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">
        Не удалось загрузить данные холдинга.
      </div>
    );
  }

  return (
    <div className="space-y-4 max-w-3xl">
      {error && (
        <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-center justify-between gap-2">
          <span>{error}</span>
          <button type="button" onClick={() => setError(null)}><i className="bi bi-x" /></button>
        </div>
      )}

      {/* ── No holding ── */}
      {!holdingData || holdingData.holding_id === null ? (
        <div className="flex flex-col items-center gap-3 py-12 text-gray-400 dark:text-gray-500">
          <i className="bi bi-diagram-3 text-3xl" />
          <span className="text-sm">Эта компания не входит ни в один холдинг</span>
          <div className="flex items-center gap-2">
            <button
              type="button"
              className="btn-secondary text-sm"
              onClick={() => setHoldingSearchOpen(true)}
            >
              <i className="bi bi-plus-lg mr-1" /> Добавить в холдинг
            </button>
          </div>
        </div>
      ) : (
        <>
          {/* Holding header */}
          <div className="flex items-center justify-between">
            <div className="text-base font-semibold text-gray-900 dark:text-gray-100">
              Холдинг: {holdingData.holding_name}
            </div>
            <div className="flex items-center gap-2">
              {holdingData.members && (
                <button
                  type="button"
                  className="btn-secondary text-sm"
                  onClick={() => setCompanySearchOpen(true)}
                >
                  <i className="bi bi-plus-lg mr-1" /> Добавить компанию
                </button>
              )}
              {editMode && (
                <button
                  type="button"
                  className="btn-ghost text-sm text-danger"
                  onClick={() => setConfirmLeave(true)}
                >
                  <i className="bi bi-box-arrow-left mr-1" /> Убрать из холдинга
                </button>
              )}
            </div>
          </div>

          {/* Companies table */}
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="text-left text-xs text-gray-500 dark:text-gray-400 border-b border-gray-200 dark:border-gray-700">
                  <th className="py-2 pr-4 font-medium">Название</th>
                  <th className="py-2 pr-4 font-medium">Роль</th>
                  <th className="py-2 pr-4 font-medium">Страна</th>
                  <th className="py-2 pr-4 font-medium">Город</th>
                  {editMode && <th className="py-2 font-medium"></th>}
                </tr>
              </thead>
              <tbody>
                {(holdingData.members ?? []).map((row) => (
                  <tr key={row.company_id} className="border-b border-gray-100 dark:border-gray-800 hover:bg-gray-50 dark:hover:bg-gray-800/50">
                    <td className="py-2 pr-4">
                      <Link href={`/companies/${row.company_id}`} className="text-primary hover:underline font-medium">
                        {row.name ?? row.legal_name ?? `Компания #${row.company_id}`}
                      </Link>
                    </td>
                    <td className="py-2 pr-4">
                      {editMode && editingRoleId === row.company_id ? (
                        <div className="flex items-center gap-1">
                          <select
                            className="input text-sm py-1 w-auto"
                            value={editRole}
                            onChange={(e) => setEditRole(e.target.value as "parent" | "subsidiary")}
                          >
                            <option value="parent">Основная</option>
                            <option value="subsidiary">Дочерняя</option>
                          </select>
                          <button
                            type="button"
                            className="btn-primary text-xs py-1 px-2"
                            disabled={saving}
                            onClick={() => void updateRole(row.company_id, editRole)}
                          >
                            OK
                          </button>
                          <button
                            type="button"
                            className="btn-ghost text-xs py-1 px-2"
                            onClick={() => setEditingRoleId(null)}
                          >
                            Отмена
                          </button>
                        </div>
                      ) : (
                        <span className={`badge text-xs px-2 py-0.5 rounded-full font-medium ${ROLE_BADGE[row.holding_role] ?? "bg-gray-100 text-gray-700"}`}>
                          {ROLE_LABEL[row.holding_role] ?? row.holding_role}
                        </span>
                      )}
                    </td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400 uppercase text-xs">
                      {row.country ?? "—"}
                    </td>
                    <td className="py-2 pr-4 text-gray-500 dark:text-gray-400">
                      {row.city ?? "—"}
                    </td>
                    {editMode && (
                      <td className="py-2">
                        {editingRoleId !== row.company_id && (
                          <div className="flex items-center gap-1">
                            <button
                              type="button"
                              className="btn-ghost text-xs py-1 px-2"
                              onClick={() => { setEditingRoleId(row.company_id); setEditRole(row.holding_role); }}
                              title="Изменить роль"
                            >
                              <i className="bi bi-pencil" />
                            </button>
                            <button
                              type="button"
                              className="btn-ghost text-xs py-1 px-2 text-danger"
                              onClick={() => setConfirmRemove(row)}
                              title="Убрать из холдинга"
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
        </>
      )}

      {/* Search & join holding modal */}
      <Modal
        open={holdingSearchOpen}
        title="Добавить в холдинг"
        onClose={() => { setHoldingSearchOpen(false); setHoldingQuery(""); setHoldingSuggestions([]); }}
        width="sm"
      >
        <div className="space-y-3">
          <div ref={holdingDropRef} className="relative">
            <label className="label">Найти холдинг</label>
            <input
              className="input"
              value={holdingQuery}
              onChange={(e) => handleHoldingQueryChange(e.target.value)}
              placeholder="Название холдинга…"
              autoFocus
              autoComplete="off"
            />
            {holdingDropOpen && holdingSuggestions.length > 0 && (
              <div className="absolute z-20 top-full mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-40 overflow-y-auto">
                {holdingSuggestions.map((h) => (
                  <button
                    key={h.id}
                    type="button"
                    onClick={() => void joinHolding(h.id, "subsidiary")}
                    className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100"
                  >
                    {h.name}
                    {h.company_count != null && (
                      <span className="text-xs text-gray-400 ml-2">{h.company_count} компаний</span>
                    )}
                  </button>
                ))}
              </div>
            )}
          </div>
          {holdingQuery.trim().length > 0 && (
            <button
              type="button"
              className="btn-secondary text-sm w-full"
              onClick={() => { setHoldingSearchOpen(false); setHoldingSetupOpen(true); }}
            >
              <i className="bi bi-plus-circle mr-1" /> Создать холдинг «{holdingQuery.trim()}»
            </button>
          )}
          {error && <p className="text-sm text-danger">{error}</p>}
        </div>
      </Modal>

      {/* Add company to existing holding */}
      <Modal
        open={companySearchOpen}
        title="Добавить компанию в холдинг"
        onClose={() => { setCompanySearchOpen(false); setSelectedCompanyToAdd(null); setCompanyQuery(""); }}
        width="sm"
        footer={
          <>
            <button type="button" className="btn-secondary" onClick={() => setCompanySearchOpen(false)}>Отмена</button>
            <button
              type="button"
              className="btn-primary"
              disabled={!selectedCompanyToAdd || saving}
              onClick={() => void addCompanyToHolding()}
            >
              {saving ? "Добавление…" : "Добавить"}
            </button>
          </>
        }
      >
        <div className="space-y-3">
          <div ref={companyDropRef} className="relative">
            <label className="label">Компания</label>
            {selectedCompanyToAdd ? (
              <div className="input flex items-center justify-between bg-gray-50 dark:bg-gray-700/50">
                <span className="text-sm">{selectedCompanyToAdd.legal_name}</span>
                <button type="button" onClick={() => { setSelectedCompanyToAdd(null); setCompanyQuery(""); }}>
                  <i className="bi bi-x text-gray-400" />
                </button>
              </div>
            ) : (
              <input
                className="input"
                value={companyQuery}
                onChange={(e) => handleCompanyQueryChange(e.target.value)}
                placeholder="Поиск компании…"
                autoComplete="off"
              />
            )}
            {companyDropOpen && !selectedCompanyToAdd && companySuggestions.length > 0 && (
              <div className="absolute z-20 top-full mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-40 overflow-y-auto">
                {companySuggestions.map((c) => (
                  <button
                    key={c.id}
                    type="button"
                    onClick={() => { setSelectedCompanyToAdd(c); setCompanyQuery(c.legal_name); setCompanyDropOpen(false); }}
                    className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100"
                  >
                    {c.legal_name}
                  </button>
                ))}
              </div>
            )}
          </div>
          <div>
            <label className="label">Роль в холдинге</label>
            <select className="input" value={addRole} onChange={(e) => setAddRole(e.target.value as "parent" | "subsidiary")}>
              <option value="subsidiary">Дочерняя</option>
              <option value="parent">Основная</option>
            </select>
          </div>
        </div>
      </Modal>

      {/* Confirm leave holding */}
      <Modal
        open={confirmLeave}
        title="Убрать из холдинга?"
        onClose={() => setConfirmLeave(false)}
        width="sm"
        footer={
          <>
            <button type="button" className="btn-secondary" onClick={() => setConfirmLeave(false)}>Отмена</button>
            <button type="button" className="btn-primary" onClick={() => void leaveHolding()}>Убрать</button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          Компания будет исключена из холдинга «{holdingData?.holding_name}».
        </p>
      </Modal>

      {/* Confirm remove company */}
      <Modal
        open={confirmRemove != null}
        title="Убрать компанию из холдинга?"
        onClose={() => setConfirmRemove(null)}
        width="sm"
        footer={
          <>
            <button type="button" className="btn-secondary" onClick={() => setConfirmRemove(null)}>Отмена</button>
            <button type="button" className="btn-primary" onClick={() => confirmRemove && void removeCompanyFromHolding(confirmRemove)}>Убрать</button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          «{confirmRemove ? (confirmRemove.name ?? confirmRemove.legal_name ?? `Компания #${confirmRemove.company_id}`) : ""}» будет исключена из холдинга.
        </p>
      </Modal>

      {/* Parent conflict confirm */}
      <Modal
        open={parentConflictPending != null}
        title="Конфликт: уже есть основная компания"
        onClose={() => setParentConflictPending(null)}
        width="sm"
        footer={
          <>
            <button type="button" className="btn-secondary" onClick={() => setParentConflictPending(null)}>Отмена</button>
            <button type="button" className="btn-primary" onClick={() => void joinHoldingWithConfirm()}>Сменить и продолжить</button>
          </>
        }
      >
        <p className="text-sm text-gray-700 dark:text-gray-300">
          В холдинге уже есть основная компания. Заменить её на текущую?
        </p>
      </Modal>

      {/* Holding create modal */}
      <HoldingSetupModal
        open={holdingSetupOpen}
        onClose={() => setHoldingSetupOpen(false)}
        onCreated={(holding, role) => {
          setHoldingSetupOpen(false);
          void joinHolding(holding.id, role);
        }}
      />
    </div>
  );
}
