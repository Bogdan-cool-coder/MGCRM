"use client";

import { useState } from "react";
import { useParams, useRouter } from "next/navigation";
import Link from "next/link";
import useSWR from "swr";
import { CategoryBadge } from "@/components/CategoryBadge";
import { Timeline } from "@/components/Timeline";
import { CustomFieldsBlock } from "@/components/CustomFields/CustomFieldsBlock";
import { AuditLogTimeline } from "@/components/AuditLog/AuditLogTimeline";
import { InlineEditableField } from "@/components/CRM/InlineEditableField";
import { FilesTab } from "@/components/CRM/FilesTab";
import { EmployeesTab } from "@/components/CRM/EmployeesTab";
import { HoldingTab } from "@/components/CRM/HoldingTab";
import { RelatedDealsTab } from "@/components/CRM/RelatedDealsTab";
import { ContactDocumentsTab } from "@/components/CRM/ContactDocumentsTab";
import { CompanyRightRail } from "@/components/CRM/CompanyRightRail";
import { CountrySelect } from "@/components/Geo/CountrySelect";
import { CitySelect } from "@/components/Geo/CitySelect";
import { SubscriptionsTab } from "@/components/SubscriptionsTab";
import { Modal } from "@/components/Modal";
import { api, ApiError, fetcher } from "@/lib/api";
import { useMe } from "@/lib/auth";
import { useToast } from "@/components/ui/Toast";
import type { Company, CompanyType, Source, User } from "@/lib/types";
import { formatDate } from "@/lib/dates";

// ── Tabs ─────────────────────────────────────────────────────────────────────

type CompanyTab =
  | "overview"
  | "timeline"
  | "files"
  | "audit"
  | "deals"
  | "employees"
  | "holding"
  | "documents"
  | "subscriptions";

const TABS: { key: CompanyTab; label: string; icon: string }[] = [
  { key: "overview",       label: "Обзор",         icon: "bi-info-circle" },
  { key: "timeline",       label: "Активности",    icon: "bi-clock-history" },
  { key: "subscriptions",  label: "Подписки",      icon: "bi-clipboard-data" },
  { key: "deals",          label: "Сделки",        icon: "bi-kanban" },
  { key: "employees",      label: "Сотрудники",    icon: "bi-people" },
  { key: "files",          label: "Файлы",         icon: "bi-folder" },
  { key: "audit",          label: "История изм.",  icon: "bi-journal-text" },
  { key: "holding",        label: "Холдинг",       icon: "bi-diagram-3" },
  { key: "documents",      label: "Документы",     icon: "bi-file-earmark-text" },
];

// ── Helpers ───────────────────────────────────────────────────────────────────

function companyInitials(name: string): string {
  const words = name.trim().split(/\s+/);
  if (words.length === 1) return words[0].slice(0, 2).toUpperCase();
  return (words[0][0] + words[1][0]).toUpperCase();
}

interface EmployeeCountResp {
  items?: unknown[];
}

function isEmployeeResp(v: unknown): v is EmployeeCountResp {
  return typeof v === "object" && v !== null;
}

// ── Page ──────────────────────────────────────────────────────────────────────

export default function CompanyCardPage() {
  const id = Number(useParams().id);
  const router = useRouter();

  const { user } = useMe();
  const { toast } = useToast();

  const [tab, setTab] = useState<CompanyTab>("overview");
  const [editMode, setEditMode] = useState(false);
  const [requisitesOpen, setRequisitesOpen] = useState(false);
  // Wave 5: локальное редактирование гео-блока (страна → город) по «Готово».
  const [geoEditing, setGeoEditing] = useState(false);
  const [geoCountry, setGeoCountry] = useState<string | null>(null);
  const [geoCity, setGeoCity] = useState("");
  const [geoSaving, setGeoSaving] = useState(false);
  const [deleteConfirmOpen, setDeleteConfirmOpen] = useState(false);
  const [deleting, setDeleting] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const { data: company, mutate: mCompany, error: companyError } =
    useSWR<Company>(`/companies/${id}`, fetcher);

  const { data: rawEmployees } = useSWR<unknown>(`/companies/${id}/employees`, fetcher);
  const { data: rawDeals } = useSWR<unknown[]>(`/companies/${id}/deals`, fetcher);
  const { data: users } = useSWR<User[]>("/users", fetcher);
  const { data: companyTypes } = useSWR<CompanyType[]>("/admin/company-types", fetcher);
  const { data: sources } = useSWR<Source[]>("/sources", fetcher);

  const employeeCount = isEmployeeResp(rawEmployees) && Array.isArray(rawEmployees)
    ? (rawEmployees as unknown[]).length
    : 0;
  const relatedDeals: { id: number; title: string }[] = Array.isArray(rawDeals)
    ? rawDeals
        .filter((d): d is { id: number; title: string } =>
          typeof d === "object" && d !== null && "id" in d && "title" in d)
        .map((d) => ({ id: d.id, title: d.title }))
    : [];
  const dealCount = relatedDeals.length;

  // Справочники для селектов типа компании / источника.
  const companyTypeOptions = (companyTypes ?? [])
    .filter((t) => t.is_active !== false)
    .map((t) => ({ value: String(t.id), label: t.name }));
  const sourceOptions = (sources ?? [])
    .filter((s) => s.is_active)
    .map((s) => ({ value: s.code, label: s.name }));
  const companyTypeName = company?.company_type_id
    ? (companyTypes?.find((t) => t.id === company.company_type_id)?.name ?? null)
    : null;
  const sourceName = company?.source
    ? (sources?.find((s) => s.code === company.source)?.name ?? company.source)
    : null;
  const ownerName = company?.owner_user_id && users
    ? (users.find((u) => u.id === company.owner_user_id)?.full_name ?? `#${company.owner_user_id}`)
    : null;

  // ── Patch helper ──────────────────────────────────────────────────────────

  async function patch(body: Record<string, unknown>) {
    try {
      await api<Company>(`/companies/${id}`, { method: "PATCH", body });
      await mCompany();
      toast.success("Сохранено");
    } catch (err) {
      const detail = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить";
      toast.error(detail);
      throw err;
    }
  }

  async function handleDelete() {
    setDeleting(true);
    setDeleteError(null);
    try {
      await api(`/companies/${id}`, { method: "DELETE" });
      router.push("/contacts");
    } catch (err) {
      const msg =
        err instanceof ApiError
          ? String((err.detail as { detail?: string })?.detail ?? err.message)
          : "Не удалось удалить компанию";
      setDeleteError(msg);
    } finally {
      setDeleting(false);
    }
  }

  const canDelete =
    user?.role === "admin" || user?.role === "director";

  // ── Loading / Error states ────────────────────────────────────────────────

  if (companyError) {
    return (
      <div className="p-8">
        <div className="flex items-start gap-2 text-sm text-danger bg-danger/10 border border-danger/20 px-4 py-3 rounded-2xl">
          <i className="bi bi-exclamation-triangle shrink-0 mt-0.5" aria-hidden="true" />
          Не удалось загрузить компанию (id={id}).
        </div>
        <Link href="/contacts" className="btn-ghost text-sm mt-4 inline-flex items-center gap-1">
          <i className="bi bi-arrow-left" aria-hidden="true" /> К списку
        </Link>
      </div>
    );
  }

  if (!company) {
    return (
      <div className="p-6">
        <div className="animate-pulse rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-6 space-y-3">
          <div className="flex items-center gap-4">
            <div className="w-14 h-14 rounded-2xl bg-gray-200 dark:bg-gray-700" />
            <div className="space-y-2 flex-1">
              <div className="h-5 bg-gray-200 dark:bg-gray-700 rounded w-1/3" />
              <div className="h-4 bg-gray-100 dark:bg-gray-700/60 rounded w-1/4" />
            </div>
          </div>
        </div>
      </div>
    );
  }

  const displayName = company.short_name ?? company.name ?? company.legal_name;

  const metaParts: string[] = [];
  if (company.legal_name && company.legal_name !== displayName) metaParts.push(company.legal_name);
  if (company.country) metaParts.push(company.country.toUpperCase());
  if (company.city) metaParts.push(company.city);

  // ── Render ────────────────────────────────────────────────────────────────

  return (
    <div className="flex flex-col bg-gray-50 dark:bg-gray-900">

      {/* ── Hero компании ──────────────────────────────────────────────── */}
      <div className="mx-6 mt-6 rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 px-6 py-5">
        <div className="flex items-start gap-5">
          {/* Company avatar */}
          <div
            className="shrink-0 w-14 h-14 rounded-2xl bg-primary text-white inline-flex items-center justify-center font-bold text-base select-none mt-0.5"
            aria-hidden="true"
          >
            {companyInitials(displayName ?? "Co")}
          </div>

          {/* Info */}
          <div className="flex-1 min-w-0">
            <div className="text-[10px] font-medium text-gray-400 dark:text-gray-500 uppercase tracking-widest mb-0.5">
              Компания
            </div>
            <h1 className="text-lg font-semibold text-gray-900 dark:text-white truncate leading-tight">
              {displayName ?? "Компания"}
            </h1>
            {metaParts.length > 0 && (
              <div className="text-sm text-gray-500 dark:text-gray-400 mt-0.5 truncate">
                {metaParts.join(" · ")}
              </div>
            )}
            {company.category_code && (
              <div className="mt-2">
                <CategoryBadge code={company.category_code} />
              </div>
            )}
          </div>

          {/* Actions */}
          <div className="flex items-center gap-2 shrink-0">
            <button
              type="button"
              onClick={() => setEditMode((v) => !v)}
              className="btn-secondary text-sm"
              title="Режим управления папками, холдингом и реквизитами. Поля «Обзора» редактируются двойным кликом без него."
            >
              <i className={`bi ${editMode ? "bi-check2" : "bi-gear"} mr-1`} aria-hidden="true" />
              {editMode ? "Готово" : "Управление"}
            </button>
            {canDelete && (
              <button
                type="button"
                onClick={() => { setDeleteError(null); setDeleteConfirmOpen(true); }}
                className="btn-ghost text-sm text-danger"
              >
                <i className="bi bi-trash" aria-hidden="true" />
              </button>
            )}
            <button type="button" onClick={() => router.push("/contacts")} className="btn-ghost text-sm">
              <i className="bi bi-arrow-left mr-1" aria-hidden="true" /> К списку
            </button>
          </div>
        </div>
      </div>

      {/* ── Tabs ────────────────────────────────────────────────────────── */}
      <div className="px-6 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 mt-4">
        <nav className="flex gap-1 overflow-x-auto whitespace-nowrap -mb-px">
          {TABS.map((t) => (
            <button
              key={t.key}
              onClick={() => setTab(t.key)}
              className={
                "inline-flex items-center gap-1.5 px-3 py-3.5 text-sm border-b-2 transition-colors whitespace-nowrap " +
                (tab === t.key
                  ? "border-primary text-primary dark:text-primary-light dark:border-primary-light font-medium"
                  : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200 hover:border-gray-300 dark:hover:border-gray-600")
              }
            >
              <i className={`bi ${t.icon} text-sm`} aria-hidden="true" />
              {t.label}
            </button>
          ))}
        </nav>
      </div>

      {/* Content */}
      <div className="flex flex-1">
        <div className="flex-1 p-8 min-w-0">

          {/* ── Обзор ── */}
          {tab === "overview" && (
            <div className="space-y-4 max-w-2xl">
              {/* Notes — двойной клик для редактирования */}
              <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-4">
                <div className="text-xs uppercase tracking-wide text-gray-500 dark:text-gray-400 mb-2">Заметки</div>
                <InlineEditableField
                  value={company.notes}
                  label=""
                  doubleClickEdit
                  kind="textarea"
                  placeholder="Заметки о компании…"
                  onSave={(v) => patch({ notes: v || null })}
                />
              </div>

              {/* Main info */}
              <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 p-4">
                <div className="text-sm font-medium text-gray-900 dark:text-gray-100 mb-3">Основная информация</div>
                <p className="text-xs text-gray-400 dark:text-gray-500 mb-3">
                  Двойной клик по значению — редактировать.
                </p>
                <div className="grid md:grid-cols-2 gap-x-6">
                  {/* Название */}
                  <InlineEditableField
                    value={company.name}
                    label="Название"
                    doubleClickEdit
                    kind="text"
                    placeholder="Краткое название"
                    onSave={(v) => patch({ name: v || null })}
                  />
                  {/* Краткое */}
                  <InlineEditableField
                    value={company.short_name}
                    label="Краткое название"
                    doubleClickEdit
                    kind="text"
                    placeholder="Аббревиатура"
                    onSave={(v) => patch({ short_name: v || null })}
                  />

                  {/* Тип компании — select из справочника */}
                  <InlineEditableField
                    value={company.company_type_id ? String(company.company_type_id) : ""}
                    label="Тип компании"
                    doubleClickEdit
                    kind="select"
                    options={companyTypeOptions}
                    onSave={(v) => patch({ company_type_id: v ? Number(v) : null })}
                  />

                  {/* Источник — select из реестра /sources, сохраняем code */}
                  <InlineEditableField
                    value={company.source ?? ""}
                    label="Источник"
                    doubleClickEdit
                    kind="select"
                    options={sourceOptions}
                    onSave={(v) => patch({ source: v || null })}
                  />

                  {/* Страна + Город — отдельный гео-блок (страна сохраняется сразу, город по «Готово») */}
                  <div className="md:col-span-2 border-b border-gray-100 dark:border-gray-700 py-2">
                    {geoEditing ? (
                      <div className="space-y-2">
                        <div className="grid md:grid-cols-2 gap-3">
                          <div>
                            <label className="text-xs text-gray-500 dark:text-gray-400 mb-1 block">Страна</label>
                            <CountrySelect
                              value={geoCountry}
                              clearable
                              onChange={(code) => {
                                setGeoCountry(code);
                                setGeoCity("");
                                void patch({
                                  country_code: code,
                                  country: code,
                                  city: null,
                                });
                              }}
                            />
                          </div>
                          <div>
                            <label className="text-xs text-gray-500 dark:text-gray-400 mb-1 block">Город</label>
                            <CitySelect
                              value={geoCity}
                              countryCode={geoCountry}
                              onChange={setGeoCity}
                            />
                          </div>
                        </div>
                        <div className="flex justify-end gap-2">
                          <button
                            type="button"
                            className="btn-ghost text-xs"
                            disabled={geoSaving}
                            onClick={() => setGeoEditing(false)}
                          >
                            Отмена
                          </button>
                          <button
                            type="button"
                            className="btn-primary text-xs"
                            disabled={geoSaving}
                            onClick={async () => {
                              setGeoSaving(true);
                              try {
                                await patch({ city: geoCity || null });
                                setGeoEditing(false);
                              } finally {
                                setGeoSaving(false);
                              }
                            }}
                          >
                            {geoSaving ? "Сохранение…" : "Готово"}
                          </button>
                        </div>
                      </div>
                    ) : (
                      <div
                        className="group flex justify-between gap-4 cursor-text"
                        onDoubleClick={() => {
                          setGeoCountry(company.country_code ?? company.country ?? null);
                          setGeoCity(company.city ?? "");
                          setGeoEditing(true);
                        }}
                        title="Двойной клик — редактировать"
                      >
                        <span className="text-gray-500 dark:text-gray-400 text-sm shrink-0">Страна / Город</span>
                        <span className="flex items-center gap-1.5 min-w-0">
                          <span className="text-sm text-right text-gray-900 dark:text-gray-100 break-words group-hover:border-b group-hover:border-dotted group-hover:border-gray-400 dark:group-hover:border-gray-500">
                            {[company.country?.toUpperCase(), company.city].filter(Boolean).join(" · ") || "—"}
                          </span>
                          <i className="bi bi-pencil text-xs text-gray-300 dark:text-gray-600 opacity-0 group-hover:opacity-100 shrink-0" />
                        </span>
                      </div>
                    )}
                  </div>

                  {/* Сайт */}
                  <InlineEditableField
                    value={company.website}
                    label="Сайт"
                    doubleClickEdit
                    kind="text"
                    placeholder="example.com"
                    onSave={(v) => patch({ website: v || null })}
                  />

                  {/* Телефон */}
                  <InlineEditableField
                    value={company.phone}
                    label="Телефон"
                    doubleClickEdit
                    kind="text"
                    placeholder="+7 999 000 00 00"
                    onSave={(v) => patch({ phone: v || null })}
                  />

                  {/* Email */}
                  <InlineEditableField
                    value={company.email}
                    label="Email"
                    doubleClickEdit
                    kind="text"
                    placeholder="info@company.com"
                    onSave={(v) => patch({ email: v || null })}
                  />

                  {/* Ответственный — select из справочника пользователей (показываем имя) */}
                  <InlineEditableField
                    value={company.owner_user_id ? String(company.owner_user_id) : ""}
                    label="Ответственный"
                    doubleClickEdit
                    kind="select"
                    options={(users ?? []).map((u) => ({ value: String(u.id), label: u.full_name ?? `#${u.id}` }))}
                    onSave={(v) => patch({ owner_user_id: v ? Number(v) : null })}
                  />

                  {/* Создана / Обновлена */}
                  <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                    <span className="text-gray-500 dark:text-gray-400 text-sm">Создана</span>
                    <span className="text-sm text-right text-gray-900 dark:text-gray-100">{formatDate(company.created_at)}</span>
                  </div>
                  <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
                    <span className="text-gray-500 dark:text-gray-400 text-sm">Обновлена</span>
                    <span className="text-sm text-right text-gray-900 dark:text-gray-100">{formatDate(company.updated_at)}</span>
                  </div>
                </div>
              </div>

              {/* Requisites accordion */}
              <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 overflow-hidden">
                <button
                  type="button"
                  className="w-full flex items-center justify-between px-4 py-3 text-sm font-medium text-gray-900 dark:text-gray-100 hover:bg-gray-50 dark:hover:bg-gray-700/50 transition-colors"
                  onClick={() => setRequisitesOpen((v) => !v)}
                >
                  <span>Реквизиты</span>
                  <i className={`bi ${requisitesOpen ? "bi-chevron-up" : "bi-chevron-down"} text-gray-400`} />
                </button>
                {requisitesOpen && (
                  <div className="px-4 pb-4 border-t border-gray-100 dark:border-gray-700 pt-3">
                    <div className="grid md:grid-cols-2 gap-x-6">
                      <InlineEditableField value={company.legal_name} label="Юр. название" editing={editMode} kind="text" onSave={(v) => patch({ legal_name: v })} />
                      <InlineEditableField value={null} label="Правовая форма" editing={editMode} kind="text" onSave={(v) => patch({ legal_form: v || null })} />
                      <InlineEditableField value={company.tax_id} label="Налоговый номер" editing={editMode} kind="text" onSave={(v) => patch({ tax_id: v || null })} />
                      <InlineEditableField value={null} label="Должность директора" editing={editMode} kind="text" onSave={(v) => patch({ director_position: v || null })} />
                      <InlineEditableField value={null} label="Директор (родит.)" editing={editMode} kind="text" onSave={(v) => patch({ director_genitive: v || null })} />
                      <InlineEditableField value={null} label="Директор (кратко)" editing={editMode} kind="text" onSave={(v) => patch({ director_short: v || null })} />
                      <InlineEditableField value={null} label="На основании" editing={editMode} kind="text" onSave={(v) => patch({ acts_basis: v || null })} />
                      <InlineEditableField value={null} label="Адрес" editing={editMode} kind="text" onSave={(v) => patch({ address: v || null })} />
                      <InlineEditableField value={null} label="Банк" editing={editMode} kind="text" onSave={(v) => patch({ bank: v || null })} />
                      <InlineEditableField value={null} label="БИК/Код банка" editing={editMode} kind="text" onSave={(v) => patch({ bank_code: v || null })} />
                      <InlineEditableField value={null} label="Расчётный счёт" editing={editMode} kind="text" onSave={(v) => patch({ account: v || null })} />
                    </div>
                  </div>
                )}
              </div>

              {/* Custom fields */}
              <CustomFieldsBlock
                entityScope="company"
                entityId={id}
                extraFields={company.extra_fields ?? {}}
                onSaved={() => mCompany()}
              />
            </div>
          )}

          {/* ── Активности ── */}
          {tab === "timeline" && (
            <div className="max-w-3xl">
              <Timeline
                targetType="company"
                targetId={id}
                includeRelated
                showSourceBadge
                relatedDeals={relatedDeals}
              />
            </div>
          )}

          {/* ── Файлы ── */}
          {tab === "files" && (
            <FilesTab entityType="company" entityId={id} editMode={editMode} />
          )}

          {/* ── История изменений ── */}
          {tab === "audit" && (
            <div className="max-w-3xl">
              <AuditLogTimeline entityType="company" entityId={id} />
            </div>
          )}

          {/* ── Сделки ── */}
          {tab === "deals" && (
            <RelatedDealsTab entityType="company" entityId={id} />
          )}

          {/* ── Сотрудники ── */}
          {tab === "employees" && (
            <EmployeesTab companyId={id} editMode={editMode} />
          )}

          {/* ── Холдинг ── */}
          {tab === "holding" && (
            <HoldingTab companyId={id} editMode={editMode} />
          )}

          {/* ── Подписки CS ── */}
          {tab === "subscriptions" && (
            <SubscriptionsTab companyId={id} />
          )}

          {/* ── Документы ── */}
          {tab === "documents" && (
            <ContactDocumentsTab
              entityType="company"
              entityId={id}
              counterpartyId={company.counterparty_id}
            />
          )}
        </div>

        {/* Right rail */}
        <CompanyRightRail
          company={company}
          employeeCount={employeeCount}
          dealCount={dealCount}
          editMode={editMode}
          onSaved={() => mCompany()}
          users={users}
          companyTypeName={companyTypeName}
          sourceName={sourceName}
        />
      </div>

      {/* Delete confirm modal */}
      {deleteConfirmOpen && (
        <Modal
          open
          title="Удалить компанию?"
          onClose={() => setDeleteConfirmOpen(false)}
          width="sm"
          footer={
            <>
              <button
                className="btn-ghost"
                onClick={() => setDeleteConfirmOpen(false)}
                disabled={deleting}
              >
                Отмена
              </button>
              <button
                className="btn-primary bg-danger hover:bg-danger/90 disabled:opacity-50"
                onClick={handleDelete}
                disabled={deleting}
              >
                {deleting ? "Удаление…" : "Удалить"}
              </button>
            </>
          }
        >
          <div className="space-y-3">
            <p className="text-sm text-gray-700 dark:text-gray-300">
              Удалить компанию{" "}
              <span className="font-medium">«{displayName}»</span>?
              Договоры и подписки заблокируют удаление.
            </p>
            {deleteError && (
              <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded flex items-start gap-2">
                <i className="bi bi-exclamation-triangle shrink-0 mt-0.5" />
                {deleteError}
              </div>
            )}
          </div>
        </Modal>
      )}
    </div>
  );
}
