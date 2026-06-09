"use client";

import { useState, useEffect, useRef, useCallback } from "react";
import { Modal } from "@/components/Modal";
import { SourceSelect } from "./SourceSelect";
import { PositionSearch } from "./PositionSearch";
import { TagInput } from "./TagInput";
import { CompanyExpressForm } from "./CompanyExpressForm";
import { PrimaryContactWarningModal } from "./PrimaryContactWarningModal";
import { HoldingSetupModal } from "./HoldingSetupModal";
import { api, ApiError } from "@/lib/api";
import { useDupCheck } from "@/hooks/useDupCheck";
import { DupCheckWarning } from "@/components/Duplicates/DupCheckWarning";
import type { Company, Holding, HoldingRole, ContactSource } from "@/lib/types";
import { HOLDING_ROLE_LABELS } from "@/lib/types";
import { mutate as globalMutate } from "swr";

// ─── Состояния стейт-машины ──────────────────────────────────────────────────

type Step = "TYPE_SELECT" | "PERSON_FORM" | "COMPANY_FORM";

const COUNTRY_OPTS = [
  { value: "kz", label: "Казахстан" },
  { value: "uz", label: "Узбекистан" },
  { value: "ru", label: "Россия" },
  { value: "by", label: "Беларусь" },
  { value: "other", label: "Другая" },
];

// ─── Тип для существующего primary-контакта ───────────────────────────────────

interface ExistingPrimary {
  id: number;
  name: string;
  position: string | null;
}

function isCompanyArray(v: unknown): v is Company[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "legal_name" in v[0]));
}

function isHoldingArray(v: unknown): v is Holding[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "name" in v[0]));
}

interface ContactEmployee {
  id: number;
  full_name: string;
  position: string | null;
  is_primary: boolean;
}

function isEmployeeList(v: unknown): v is { items: ContactEmployee[] } {
  return typeof v === "object" && v !== null && "items" in v && Array.isArray((v as { items: unknown }).items);
}

// ─── Person form state ────────────────────────────────────────────────────────

type PersonForm = {
  full_name: string;
  phone: string;
  email: string;
  source: string;
  company_id: string;
  company_name: string; // display
  position: string;
  notes: string;
  is_primary: boolean;
  tags: string[];
};

function emptyPerson(): PersonForm {
  return {
    full_name: "", phone: "", email: "", source: "",
    company_id: "", company_name: "", position: "", notes: "",
    is_primary: false, tags: [],
  };
}

// ─── Company form state ───────────────────────────────────────────────────────

type CompanyForm = {
  name: string;
  legal_name: string;
  phone: string;
  email: string;
  source: string;
  country: string;
  city: string;
  website: string;
  company_type_id: string;
  holding_id: string;
  holding_name: string; // display
  holding_role: HoldingRole;
  notes: string;
  tags: string[];
};

function emptyCompany(): CompanyForm {
  return {
    name: "", legal_name: "", phone: "", email: "", source: "",
    country: "kz", city: "", website: "", company_type_id: "",
    holding_id: "", holding_name: "", holding_role: "subsidiary",
    notes: "", tags: [],
  };
}

// ─── CompanyType stub ─────────────────────────────────────────────────────────

interface CompanyTypeItem { id: number; name: string; }
function isCompanyTypeArray(v: unknown): v is CompanyTypeItem[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "name" in v[0]));
}

// ─── Props ────────────────────────────────────────────────────────────────────

interface Props {
  open: boolean;
  onClose: () => void;
  /** Вызывается после успешного создания — родитель делает mutate SWR. */
  onCreated?: () => void;
}

// ─── Компонент ────────────────────────────────────────────────────────────────

export function ContactQuickForm({ open, onClose, onCreated }: Props) {
  const [step, setStep] = useState<Step>("TYPE_SELECT");
  const [personForm, setPersonForm] = useState<PersonForm>(emptyPerson());
  const [companyForm, setCompanyForm] = useState<CompanyForm>(emptyCompany());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // company search for person form
  const [companyQuery, setCompanyQuery] = useState("");
  const [companySuggestions, setCompanySuggestions] = useState<Company[]>([]);
  const [companyDropOpen, setCompanyDropOpen] = useState(false);
  const [showExpressForm, setShowExpressForm] = useState(false);
  const companyTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const companyDropRef = useRef<HTMLDivElement>(null);

  // holding search for company form
  const [holdingQuery, setHoldingQuery] = useState("");
  const [holdingSuggestions, setHoldingSuggestions] = useState<Holding[]>([]);
  const [holdingDropOpen, setHoldingDropOpen] = useState(false);
  const [holdingModalOpen, setHoldingModalOpen] = useState(false);
  const holdingTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const holdingDropRef = useRef<HTMLDivElement>(null);

  // company types
  const [companyTypes, setCompanyTypes] = useState<CompanyTypeItem[]>([]);

  // primary contact warning
  const [primaryWarningOpen, setPrimaryWarningOpen] = useState(false);
  const [existingPrimary, setExistingPrimary] = useState<ExistingPrimary | null>(null);
  // pending submit payload for after confirmation
  const pendingSubmitRef = useRef<(() => Promise<void>) | null>(null);

  // dup checks
  const personPhoneDup = useDupCheck("contact", "phone");
  const personEmailDup = useDupCheck("contact", "email");
  const companyPhoneDup = useDupCheck("company", "phone");
  const companyEmailDup = useDupCheck("company", "email");

  // ─── Reset when closed/reopened ─────────────────────────────────────────────

  useEffect(() => {
    if (!open) return;
    setStep("TYPE_SELECT");
    setPersonForm(emptyPerson());
    setCompanyForm(emptyCompany());
    setSaving(false);
    setError(null);
    setCompanyQuery("");
    setCompanySuggestions([]);
    setShowExpressForm(false);
    setHoldingQuery("");
    setHoldingSuggestions([]);
    personPhoneDup.reset();
    personEmailDup.reset();
    companyPhoneDup.reset();
    companyEmailDup.reset();
  // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open]);

  // ─── Load company types ──────────────────────────────────────────────────────

  useEffect(() => {
    if (!open || step !== "COMPANY_FORM") return;
    api<unknown>("/admin/company-types?limit=100").then((res) => {
      if (isCompanyTypeArray(res)) setCompanyTypes(res);
    }).catch(() => { /* silent */ });
  }, [open, step]);

  // ─── Company search for PersonForm ───────────────────────────────────────────

  const fetchCompanies = useCallback(async (q: string) => {
    if (q.trim().length < 1) { setCompanySuggestions([]); return; }
    try {
      const result = await api<unknown>(`/companies?q=${encodeURIComponent(q)}&limit=10`);
      if (isCompanyArray(result)) setCompanySuggestions(result);
    } catch { /* silent */ }
  }, []);

  function handleCompanyQueryChange(q: string) {
    setCompanyQuery(q);
    if (companyTimerRef.current) clearTimeout(companyTimerRef.current);
    companyTimerRef.current = setTimeout(() => fetchCompanies(q), 300);
    setCompanyDropOpen(true);
    setShowExpressForm(false);
  }

  function selectCompany(c: Company) {
    setPersonForm((prev) => ({
      ...prev,
      company_id: String(c.id),
      company_name: c.legal_name,
    }));
    setCompanyQuery(c.legal_name);
    setCompanyDropOpen(false);
    setCompanySuggestions([]);
    setShowExpressForm(false);
  }

  function clearCompany() {
    setPersonForm((prev) => ({ ...prev, company_id: "", company_name: "" }));
    setCompanyQuery("");
    setCompanySuggestions([]);
    setCompanyDropOpen(false);
    setShowExpressForm(false);
  }

  // close company dropdown on outside click
  useEffect(() => {
    function handler(e: MouseEvent) {
      if (companyDropRef.current && !companyDropRef.current.contains(e.target as Node)) {
        setCompanyDropOpen(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  // ─── Holding search for CompanyForm ──────────────────────────────────────────

  const fetchHoldings = useCallback(async (q: string) => {
    if (q.trim().length < 1) { setHoldingSuggestions([]); return; }
    try {
      const result = await api<unknown>(`/holdings?q=${encodeURIComponent(q)}&limit=10`);
      if (isHoldingArray(result)) setHoldingSuggestions(result);
    } catch { /* silent */ }
  }, []);

  function handleHoldingQueryChange(q: string) {
    setHoldingQuery(q);
    if (holdingTimerRef.current) clearTimeout(holdingTimerRef.current);
    holdingTimerRef.current = setTimeout(() => fetchHoldings(q), 300);
    setHoldingDropOpen(true);
  }

  function selectHolding(h: Holding) {
    setCompanyForm((prev) => ({
      ...prev,
      holding_id: String(h.id),
      holding_name: h.name,
    }));
    setHoldingQuery(h.name);
    setHoldingDropOpen(false);
    setHoldingSuggestions([]);
  }

  function clearHolding() {
    setCompanyForm((prev) => ({ ...prev, holding_id: "", holding_name: "" }));
    setHoldingQuery("");
    setHoldingSuggestions([]);
    setHoldingDropOpen(false);
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

  // ─── Submit: Person ──────────────────────────────────────────────────────────

  async function checkAndSubmitPerson(skipPrimaryCheck = false) {
    if (!personForm.full_name.trim()) { setError("Укажите ФИО"); return; }
    if (!personForm.phone.trim()) { setError("Укажите телефон"); return; }

    // Если is_primary и есть компания — проверить существующий primary
    if (!skipPrimaryCheck && personForm.is_primary && personForm.company_id) {
      try {
        const res = await api<unknown>(`/companies/${personForm.company_id}/employees?limit=100`);
        if (isEmployeeList(res)) {
          const primary = res.items.find((e) => e.is_primary);
          if (primary) {
            setExistingPrimary({ id: primary.id, name: primary.full_name, position: primary.position });
            pendingSubmitRef.current = () => submitPerson();
            setPrimaryWarningOpen(true);
            return;
          }
        }
      } catch { /* ignore — продолжаем без проверки */ }
    }

    await submitPerson();
  }

  async function submitPerson() {
    setSaving(true);
    setError(null);
    try {
      await api("/contacts", {
        method: "POST",
        body: {
          full_name: personForm.full_name.trim(),
          phone: personForm.phone.trim() || null,
          email: personForm.email.trim() || null,
          source: (personForm.source as ContactSource) || null,
          company_id: personForm.company_id ? Number(personForm.company_id) : null,
          position: personForm.position.trim() || null,
          is_primary: personForm.is_primary,
          notes: personForm.notes.trim() || null,
          tags: personForm.tags.length > 0 ? personForm.tags : null,
        },
      });
      await globalMutate((key: unknown) => typeof key === "string" && key.includes("/contacts"));
      onCreated?.();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить контакт");
    } finally {
      setSaving(false);
    }
  }

  // ─── Submit: Company ─────────────────────────────────────────────────────────

  async function submitCompany() {
    if (!companyForm.name.trim()) { setError("Укажите название компании"); return; }
    if (!companyForm.phone.trim()) { setError("Укажите телефон"); return; }
    if (!companyForm.country) { setError("Выберите страну"); return; }
    if (!companyForm.city.trim()) { setError("Укажите город"); return; }
    setSaving(true);
    setError(null);
    try {
      await api("/companies", {
        method: "POST",
        body: {
          name: companyForm.name.trim(),
          legal_name: companyForm.legal_name.trim() || companyForm.name.trim(),
          phone: companyForm.phone.trim() || null,
          email: companyForm.email.trim() || null,
          source: (companyForm.source as ContactSource) || null,
          country: companyForm.country || null,
          city: companyForm.city.trim() || null,
          website: companyForm.website.trim() || null,
          company_type_id: companyForm.company_type_id ? Number(companyForm.company_type_id) : null,
          group_id: companyForm.holding_id ? Number(companyForm.holding_id) : null,
          holding_role: companyForm.holding_id ? companyForm.holding_role : null,
          notes: companyForm.notes.trim() || null,
          tags: companyForm.tags.length > 0 ? companyForm.tags : null,
        },
      });
      await globalMutate((key: unknown) => typeof key === "string" && key.includes("/contacts"));
      onCreated?.();
      onClose();
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось создать компанию");
    } finally {
      setSaving(false);
    }
  }

  // ─── Primary warning confirm ─────────────────────────────────────────────────

  async function handlePrimaryConfirm() {
    setPrimaryWarningOpen(false);
    setExistingPrimary(null);
    if (pendingSubmitRef.current) {
      await pendingSubmitRef.current();
      pendingSubmitRef.current = null;
    }
  }

  function handlePrimaryCancel() {
    setPrimaryWarningOpen(false);
    setExistingPrimary(null);
    pendingSubmitRef.current = null;
  }

  // ─── Modal title ─────────────────────────────────────────────────────────────

  const modalTitle =
    step === "TYPE_SELECT" ? "Новый контакт"
    : step === "PERSON_FORM" ? "Новый контакт: Физ. лицо"
    : "Новый контакт: Компания";

  // ─── Footer ──────────────────────────────────────────────────────────────────

  const footer = step === "TYPE_SELECT" ? undefined : (
    <>
      <button type="button" className="btn-secondary" onClick={onClose}>Отмена</button>
      <button
        type="button"
        className="btn-primary"
        onClick={step === "PERSON_FORM" ? () => checkAndSubmitPerson() : submitCompany}
        disabled={saving}
      >
        {saving ? "Сохранение…" : "Сохранить"}
      </button>
    </>
  );

  // ─── Render ──────────────────────────────────────────────────────────────────

  return (
    <>
      <Modal
        open={open}
        onClose={onClose}
        title={modalTitle}
        width="md"
        footer={footer}
      >
        {/* ── Шаг 0: Выбор типа ── */}
        {step === "TYPE_SELECT" && (
          <div className="grid grid-cols-2 gap-4">
            <button
              type="button"
              onClick={() => setStep("PERSON_FORM")}
              className="flex flex-col items-center gap-3 p-6 border-2 border-gray-200 dark:border-gray-700 rounded-xl hover:border-primary hover:bg-primary/5 dark:hover:border-primary dark:hover:bg-primary/10 transition-colors text-center"
            >
              <i className="bi bi-person text-3xl text-primary" />
              <div>
                <div className="font-semibold text-gray-900 dark:text-gray-100">Физ. лицо</div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  Сотрудник, представитель компании
                </div>
              </div>
            </button>
            <button
              type="button"
              onClick={() => setStep("COMPANY_FORM")}
              className="flex flex-col items-center gap-3 p-6 border-2 border-gray-200 dark:border-gray-700 rounded-xl hover:border-primary hover:bg-primary/5 dark:hover:border-primary dark:hover:bg-primary/10 transition-colors text-center"
            >
              <i className="bi bi-building text-3xl text-primary" />
              <div>
                <div className="font-semibold text-gray-900 dark:text-gray-100">Компания</div>
                <div className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                  Юрлицо, клиент или партнёр
                </div>
              </div>
            </button>
          </div>
        )}

        {/* ── Шаг 1: Форма физлица ── */}
        {step === "PERSON_FORM" && (
          <div className="space-y-3">
            <button
              type="button"
              className="text-xs text-primary hover:underline flex items-center gap-1"
              onClick={() => { setStep("TYPE_SELECT"); setError(null); }}
            >
              <i className="bi bi-arrow-left" /> сменить тип
            </button>

            {error && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-center justify-between gap-2">
                <span>{error}</span>
                <button type="button" onClick={() => setError(null)}><i className="bi bi-x" /></button>
              </div>
            )}

            <div>
              <label className="label">ФИО <span className="text-danger">*</span></label>
              <input
                className="input"
                value={personForm.full_name}
                onChange={(e) => setPersonForm((p) => ({ ...p, full_name: e.target.value }))}
                placeholder="Иван Иванов"
                autoFocus
              />
            </div>

            <div>
              <label className="label">Телефон <span className="text-danger">*</span></label>
              <input
                className="input"
                type="tel"
                value={personForm.phone}
                onChange={(e) => { setPersonForm((p) => ({ ...p, phone: e.target.value })); personPhoneDup.reset(); }}
                onBlur={(e) => personPhoneDup.check(e.target.value)}
                placeholder="+7 999 000 00 00"
              />
              <DupCheckWarning matches={personPhoneDup.matches} checking={personPhoneDup.checking} entityType="contact" onDismiss={personPhoneDup.dismiss} />
            </div>

            <div>
              <label className="label">Email</label>
              <input
                className="input"
                type="email"
                value={personForm.email}
                onChange={(e) => { setPersonForm((p) => ({ ...p, email: e.target.value })); personEmailDup.reset(); }}
                onBlur={(e) => personEmailDup.check(e.target.value)}
                placeholder="ivan@company.com"
              />
              <DupCheckWarning matches={personEmailDup.matches} checking={personEmailDup.checking} entityType="contact" onDismiss={personEmailDup.dismiss} />
            </div>

            <div>
              <label className="label">Источник</label>
              <SourceSelect value={personForm.source} onChange={(v) => setPersonForm((p) => ({ ...p, source: v }))} />
            </div>

            {/* Компания с поиском */}
            <div ref={companyDropRef} className="relative">
              <label className="label">Компания</label>
              {personForm.company_id ? (
                <div className="input flex items-center justify-between bg-gray-50 dark:bg-gray-700/50">
                  <span className="text-sm text-gray-900 dark:text-gray-100">{personForm.company_name}</span>
                  <button type="button" onClick={clearCompany} className="text-gray-400 hover:text-danger">
                    <i className="bi bi-x" />
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

              {/* Dropdown */}
              {companyDropOpen && !personForm.company_id && (companySuggestions.length > 0 || companyQuery.trim().length > 0) && (
                <div className="absolute z-20 top-full mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-48 overflow-y-auto">
                  {companySuggestions.map((c) => (
                    <button
                      key={c.id}
                      type="button"
                      onClick={() => selectCompany(c)}
                      className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100"
                    >
                      <span className="font-medium">{c.legal_name}</span>
                      {c.country && <span className="text-xs text-gray-400 ml-2 uppercase">{c.country}</span>}
                    </button>
                  ))}
                  {companyQuery.trim().length > 0 && (
                    <button
                      type="button"
                      onClick={() => { setCompanyDropOpen(false); setShowExpressForm(true); }}
                      className="w-full text-left px-3 py-2 text-sm text-primary hover:bg-primary/5 dark:hover:bg-primary/10 border-t border-gray-100 dark:border-gray-700 flex items-center gap-1"
                    >
                      <i className="bi bi-plus-circle text-xs" />
                      Создать компанию «{companyQuery.trim()}»
                    </button>
                  )}
                </div>
              )}

              {/* Express form */}
              {showExpressForm && (
                <CompanyExpressForm
                  initialName={companyQuery.trim()}
                  onCreated={(c) => {
                    selectCompany(c);
                    setShowExpressForm(false);
                  }}
                  onCancel={() => setShowExpressForm(false)}
                />
              )}
            </div>

            <div>
              <label className="label">Должность</label>
              <PositionSearch
                value={personForm.position}
                onChange={(v) => setPersonForm((p) => ({ ...p, position: v }))}
                placeholder="Руководитель отдела…"
              />
            </div>

            <div>
              <label className="label">Заметки</label>
              <textarea
                className="input min-h-[72px]"
                value={personForm.notes}
                onChange={(e) => setPersonForm((p) => ({ ...p, notes: e.target.value }))}
                placeholder="Контекст знакомства, особенности и т.д."
              />
            </div>

            {personForm.company_id && (
              <label className="flex items-center gap-2 text-sm cursor-pointer select-none">
                <input
                  type="checkbox"
                  checked={personForm.is_primary}
                  onChange={(e) => setPersonForm((p) => ({ ...p, is_primary: e.target.checked }))}
                  className="accent-primary"
                />
                Основной контакт этой компании
              </label>
            )}

            <div>
              <label className="label">Теги</label>
              <TagInput value={personForm.tags} onChange={(tags) => setPersonForm((p) => ({ ...p, tags }))} />
            </div>
          </div>
        )}

        {/* ── Шаг 1: Форма компании ── */}
        {step === "COMPANY_FORM" && (
          <div className="space-y-3">
            <button
              type="button"
              className="text-xs text-primary hover:underline flex items-center gap-1"
              onClick={() => { setStep("TYPE_SELECT"); setError(null); }}
            >
              <i className="bi bi-arrow-left" /> сменить тип
            </button>

            {error && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-center justify-between gap-2">
                <span>{error}</span>
                <button type="button" onClick={() => setError(null)}><i className="bi bi-x" /></button>
              </div>
            )}

            <div>
              <label className="label">Название <span className="text-danger">*</span></label>
              <input
                className="input"
                value={companyForm.name}
                onChange={(e) => setCompanyForm((p) => ({ ...p, name: e.target.value }))}
                placeholder="ПТС Казахстан"
                autoFocus
              />
            </div>

            <div>
              <label className="label">Юридическое название</label>
              <input
                className="input"
                value={companyForm.legal_name}
                onChange={(e) => setCompanyForm((p) => ({ ...p, legal_name: e.target.value }))}
                placeholder="ТОО «Проптехсервис Казахстан»"
              />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Телефон <span className="text-danger">*</span></label>
                <input
                  className="input"
                  type="tel"
                  value={companyForm.phone}
                  onChange={(e) => { setCompanyForm((p) => ({ ...p, phone: e.target.value })); companyPhoneDup.reset(); }}
                  onBlur={(e) => companyPhoneDup.check(e.target.value)}
                  placeholder="+7 999 000 00 00"
                />
                <DupCheckWarning matches={companyPhoneDup.matches} checking={companyPhoneDup.checking} entityType="company" onDismiss={companyPhoneDup.dismiss} />
              </div>
              <div>
                <label className="label">Email</label>
                <input
                  className="input"
                  type="email"
                  value={companyForm.email}
                  onChange={(e) => { setCompanyForm((p) => ({ ...p, email: e.target.value })); companyEmailDup.reset(); }}
                  onBlur={(e) => companyEmailDup.check(e.target.value)}
                  placeholder="info@company.com"
                />
                <DupCheckWarning matches={companyEmailDup.matches} checking={companyEmailDup.checking} entityType="company" onDismiss={companyEmailDup.dismiss} />
              </div>
            </div>

            <div>
              <label className="label">Источник</label>
              <SourceSelect value={companyForm.source} onChange={(v) => setCompanyForm((p) => ({ ...p, source: v }))} />
            </div>

            <div className="grid grid-cols-2 gap-3">
              <div>
                <label className="label">Страна <span className="text-danger">*</span></label>
                <select
                  className="input"
                  value={companyForm.country}
                  onChange={(e) => setCompanyForm((p) => ({ ...p, country: e.target.value }))}
                >
                  {COUNTRY_OPTS.map((o) => (
                    <option key={o.value} value={o.value}>{o.label}</option>
                  ))}
                </select>
              </div>
              <div>
                <label className="label">Город <span className="text-danger">*</span></label>
                <input
                  className="input"
                  value={companyForm.city}
                  onChange={(e) => setCompanyForm((p) => ({ ...p, city: e.target.value }))}
                  placeholder="Алматы"
                />
              </div>
            </div>

            <div>
              <label className="label">Сайт</label>
              <input
                className="input"
                value={companyForm.website}
                onChange={(e) => setCompanyForm((p) => ({ ...p, website: e.target.value }))}
                placeholder="example.com"
              />
            </div>

            <div>
              <label className="label">Тип компании</label>
              <select
                className="input"
                value={companyForm.company_type_id}
                onChange={(e) => setCompanyForm((p) => ({ ...p, company_type_id: e.target.value }))}
              >
                <option value="">—</option>
                {companyTypes.map((t) => (
                  <option key={t.id} value={t.id}>{t.name}</option>
                ))}
              </select>
            </div>

            {/* Холдинг с поиском */}
            <div ref={holdingDropRef} className="relative">
              <label className="label">Холдинг</label>
              {companyForm.holding_id ? (
                <div className="input flex items-center justify-between bg-gray-50 dark:bg-gray-700/50">
                  <span className="text-sm text-gray-900 dark:text-gray-100">{companyForm.holding_name}</span>
                  <button type="button" onClick={clearHolding} className="text-gray-400 hover:text-danger">
                    <i className="bi bi-x" />
                  </button>
                </div>
              ) : (
                <input
                  className="input"
                  value={holdingQuery}
                  onChange={(e) => handleHoldingQueryChange(e.target.value)}
                  placeholder="Поиск холдинга…"
                  autoComplete="off"
                />
              )}

              {holdingDropOpen && !companyForm.holding_id && (holdingSuggestions.length > 0 || holdingQuery.trim().length > 0) && (
                <div className="absolute z-20 top-full mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-40 overflow-y-auto">
                  {holdingSuggestions.map((h) => (
                    <button
                      key={h.id}
                      type="button"
                      onClick={() => selectHolding(h)}
                      className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100"
                    >
                      {h.name}
                      {h.company_count != null && (
                        <span className="text-xs text-gray-400 ml-2">{h.company_count} компаний</span>
                      )}
                    </button>
                  ))}
                  {holdingQuery.trim().length > 0 && (
                    <button
                      type="button"
                      onClick={() => { setHoldingDropOpen(false); setHoldingModalOpen(true); }}
                      className="w-full text-left px-3 py-2 text-sm text-primary hover:bg-primary/5 dark:hover:bg-primary/10 border-t border-gray-100 dark:border-gray-700 flex items-center gap-1"
                    >
                      <i className="bi bi-plus-circle text-xs" />
                      Создать холдинг «{holdingQuery.trim()}»
                    </button>
                  )}
                </div>
              )}
            </div>

            {/* Роль в холдинге — показываем только если холдинг выбран */}
            {companyForm.holding_id && (
              <div>
                <label className="label">Роль в холдинге</label>
                <select
                  className="input"
                  value={companyForm.holding_role}
                  onChange={(e) => setCompanyForm((p) => ({ ...p, holding_role: e.target.value as HoldingRole }))}
                >
                  {(Object.entries(HOLDING_ROLE_LABELS) as [HoldingRole, string][]).map(([val, label]) => (
                    <option key={val} value={val}>{label}</option>
                  ))}
                </select>
              </div>
            )}

            <div>
              <label className="label">Заметки</label>
              <textarea
                className="input min-h-[72px]"
                value={companyForm.notes}
                onChange={(e) => setCompanyForm((p) => ({ ...p, notes: e.target.value }))}
                placeholder="Контекст знакомства, особенности компании и т.д."
              />
            </div>

            <div>
              <label className="label">Теги</label>
              <TagInput value={companyForm.tags} onChange={(tags) => setCompanyForm((p) => ({ ...p, tags }))} />
            </div>
          </div>
        )}
      </Modal>

      {/* Primary contact warning */}
      <PrimaryContactWarningModal
        open={primaryWarningOpen}
        existing={existingPrimary}
        onCancel={handlePrimaryCancel}
        onConfirm={handlePrimaryConfirm}
      />

      {/* Holding setup modal */}
      <HoldingSetupModal
        open={holdingModalOpen}
        onClose={() => setHoldingModalOpen(false)}
        onCreated={(holding, role) => {
          selectHolding(holding);
          setCompanyForm((prev) => ({ ...prev, holding_role: role }));
          setHoldingModalOpen(false);
        }}
      />

      {/* Fallback UserSelect — не рендерим в форме, он встроен выше через UserSelect компонент */}
    </>
  );
}

