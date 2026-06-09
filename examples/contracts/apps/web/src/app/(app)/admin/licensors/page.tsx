"use client";

import { useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { Modal } from "@/components/Modal";
import { RoleGate } from "@/components/RoleGate";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { FloatingInput } from "@/components/ui/FloatingInput";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import { ALL_COUNTRIES, type LicensorBankAccount, type LicensorEntity } from "@/lib/types";

const CURRENCY_OPTS = [
  { value: "KZT", label: "KZT (тенге)" },
  { value: "UZS", label: "UZS (сум)" },
  { value: "USD", label: "USD" },
  { value: "EUR", label: "EUR" },
  { value: "RUB", label: "RUB" },
];

const EMPTY: Partial<LicensorEntity> = {
  country_code: "kz",
  legal_form: "ТОО",
  full_legal_form: "Товарищество с ограниченной ответственностью",
  gender_ending_oe: "ое",
  name: "",
  director_position: "Директора",
  director_short: "",
  director_genitive: "",
  acts_basis: "Устава",
  tax_id_label: "БИН",
  tax_id: "",
  address: "",
  bank: "",
  bank_code_label: "БИК",
  bank_code: "",
  account: "",
};

// Флаги стран
const COUNTRY_FLAGS: Record<string, string> = {
  kz: "🇰🇿", uz: "🇺🇿", ru: "🇷🇺", by: "🇧🇾", kg: "🇰🇬",
  tj: "🇹🇯", am: "🇦🇲", az: "🇦🇿", ge: "🇬🇪",
};

function CountryBadge({ code }: { code: string }) {
  const flag = COUNTRY_FLAGS[code.toLowerCase()] ?? "🏳";
  return (
    <span className="inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-semibold uppercase bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300">
      <span aria-hidden="true">{flag}</span>
      {code.toUpperCase()}
    </span>
  );
}

export default function LicensorsPage() {
  const { data, mutate } = useSWR<LicensorEntity[]>("/licensors", fetcher);
  const { toast } = useToast();

  const [form, setForm] = useState<Partial<LicensorEntity> | null>(null);
  const [initial, setInitial] = useState<Partial<LicensorEntity> | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [saving, setSaving] = useState(false);

  function openCreate() { setForm(EMPTY); setInitial(EMPTY); setError(null); }
  function openEdit(l: LicensorEntity) { setForm(l); setInitial(l); setError(null); }
  function isDirty() {
    return form && initial ? JSON.stringify(form) !== JSON.stringify(initial) : false;
  }
  function isValid() {
    if (!form) return false;
    const required = ["country_code", "legal_form", "name", "director_short", "director_genitive", "tax_id", "address", "bank", "bank_code", "account"];
    return required.every((k) => (form as Record<string, unknown>)[k]);
  }

  async function save(): Promise<boolean> {
    if (!form || !isValid()) { setError("Заполните обязательные поля"); return false; }
    setSaving(true);
    setError(null);
    try {
      const id = (form as LicensorEntity).id;
      if (id) {
        await api(`/licensors/${id}`, { method: "PATCH", body: form });
        toast.success("Юр.лицо обновлено");
      } else {
        await api("/licensors", { method: "POST", body: form });
        toast.success("Юр.лицо добавлено");
      }
      await mutate();
      return true;
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      setError(msg);
      toast.error(msg);
      return false;
    } finally {
      setSaving(false);
    }
  }

  const columns: DataTableColumn<LicensorEntity>[] = [
    {
      key: "country_code",
      header: "Страна",
      width: "7rem",
      skeletonWidth: "60%",
      render: (l) => <CountryBadge code={l.country_code} />,
    },
    {
      key: "name",
      header: "Название",
      skeletonWidth: "65%",
      render: (l) => (
        <div>
          <p className="font-medium text-gray-900 dark:text-gray-100">
            {l.legal_form} «{l.name}»
          </p>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            {l.full_legal_form}
          </p>
        </div>
      ),
    },
    {
      key: "tax_id",
      header: "БИН/ИНН",
      width: "12rem",
      skeletonWidth: "55%",
      render: (l) => (
        <span className="text-sm text-gray-700 dark:text-gray-300">
          {l.tax_id_label} {l.tax_id}
        </span>
      ),
    },
    {
      key: "director_short",
      header: "Директор",
      width: "12rem",
      skeletonWidth: "60%",
      render: (l) => (
        <span className="text-sm text-gray-700 dark:text-gray-300">{l.director_short}</span>
      ),
    },
  ];

  return (
    <RoleGate allowed={["admin", "lawyer", "director"]} fallback={<LicensorsNoAccess />}>
      <PageHeader
        title="Наши юр.лица"
        description="Реквизиты компаний MACRO Global по странам. Автоматически подставляются в договор."
        actions={
          <button className="btn-primary" onClick={openCreate}>
            <i className="bi bi-plus-lg mr-1" /> Добавить
          </button>
        }
      />
      <div className="p-8">
        <DataTable
          columns={columns}
          rows={data}
          getRowKey={(l) => l.id}
          onRowClick={openEdit}
          emptyIcon="bi-building"
          emptyTitle="Юр.лица не созданы"
          emptyText="Добавьте реквизиты компаний MACRO Global для автоподстановки в договоры."
          emptyCta={
            <button className="btn-primary" onClick={openCreate}>
              <i className="bi bi-plus-lg mr-1" /> Добавить первое юр.лицо
            </button>
          }
          ariaLabel="Юр.лица лицензиара"
        />
      </div>

      <Modal
        open={!!form}
        onClose={() => setForm(null)}
        onTrySave={save}
        isDirty={isDirty() ?? false}
        title={(form as LicensorEntity)?.id ? "Редактирование юр.лица" : "Новое юр.лицо лицензиара"}
        width="lg"
        footer={
          <>
            <button className="btn-secondary" onClick={() => setForm(null)}>Отмена</button>
            <button onClick={save} disabled={saving || !isValid()} className="btn-primary">
              {saving ? "Сохранение…" : "Сохранить"}
            </button>
          </>
        }
      >
        {form && (
          <div className="space-y-4">
            {error && (
              <div className="text-danger text-sm bg-danger/10 dark:bg-danger-500/10 px-3 py-2 rounded-lg flex items-center gap-2">
                <i className="bi bi-exclamation-triangle shrink-0" />
                {error}
              </div>
            )}

            {/* Организационно-правовая форма */}
            <SectionTitle>Организация</SectionTitle>
            <div className="grid grid-cols-3 gap-3">
              <div>
                <label className="label">Страна</label>
                <select
                  className="input"
                  value={form.country_code ?? "kz"}
                  onChange={(e) => setForm({ ...form, country_code: e.target.value })}
                >
                  {ALL_COUNTRIES.map((c) => (
                    <option key={c.value} value={c.value}>{c.label}</option>
                  ))}
                </select>
              </div>
              <FloatingInput
                label="Краткая форма"
                required
                value={form.legal_form ?? ""}
                onChange={(e) => setForm({ ...form, legal_form: e.target.value })}
              />
              <FloatingInput
                label="Окончание (ое/ый)"
                value={form.gender_ending_oe ?? "ое"}
                onChange={(e) => setForm({ ...form, gender_ending_oe: e.target.value })}
              />
            </div>
            <FloatingInput
              label="Полная форма"
              value={form.full_legal_form ?? ""}
              onChange={(e) => setForm({ ...form, full_legal_form: e.target.value })}
            />
            <FloatingInput
              label="Название"
              required
              value={form.name ?? ""}
              onChange={(e) => setForm({ ...form, name: e.target.value })}
            />

            {/* Налоговый номер */}
            <SectionTitle>Налоговые данные</SectionTitle>
            <div className="grid grid-cols-2 gap-3">
              <FloatingInput
                label="Метка налог. номера"
                value={form.tax_id_label ?? ""}
                onChange={(e) => setForm({ ...form, tax_id_label: e.target.value })}
              />
              <FloatingInput
                label={form.tax_id_label || "БИН/ИНН"}
                required
                value={form.tax_id ?? ""}
                onChange={(e) => setForm({ ...form, tax_id: e.target.value })}
              />
            </div>

            {/* Подписант */}
            <SectionTitle>Подписант</SectionTitle>
            <FloatingInput
              label="Должность подписанта"
              value={form.director_position ?? ""}
              onChange={(e) => setForm({ ...form, director_position: e.target.value })}
            />
            <FloatingInput
              label="Подписант (ФИО род. падеж)"
              required
              value={form.director_genitive ?? ""}
              onChange={(e) => setForm({ ...form, director_genitive: e.target.value })}
            />
            <FloatingInput
              label="Подписант кратко"
              required
              value={form.director_short ?? ""}
              onChange={(e) => setForm({ ...form, director_short: e.target.value })}
            />
            <FloatingInput
              label="На основании"
              value={form.acts_basis ?? ""}
              onChange={(e) => setForm({ ...form, acts_basis: e.target.value })}
            />

            {/* Адрес и контакты */}
            <SectionTitle>Контактные данные</SectionTitle>
            <FloatingInput
              label="Адрес"
              required
              value={form.address ?? ""}
              onChange={(e) => setForm({ ...form, address: e.target.value })}
            />
            <div className="grid grid-cols-2 gap-3">
              <FloatingInput
                label="Телефон"
                value={form.phone ?? ""}
                onChange={(e) => setForm({ ...form, phone: e.target.value })}
              />
              <FloatingInput
                label="Email"
                type="email"
                value={form.email ?? ""}
                onChange={(e) => setForm({ ...form, email: e.target.value })}
              />
            </div>
            <FloatingInput
              label="Сайт"
              value={form.website ?? ""}
              onChange={(e) => setForm({ ...form, website: e.target.value })}
            />
            <FloatingInput
              label="Логин для обучения (Zoom/Telegram/…)"
              value={form.training_login ?? ""}
              onChange={(e) => setForm({ ...form, training_login: e.target.value })}
            />

            {/* Основной банковский счёт */}
            <SectionTitle>Основной банковский счёт</SectionTitle>
            <FloatingInput
              label="Банк"
              required
              value={form.bank ?? ""}
              onChange={(e) => setForm({ ...form, bank: e.target.value })}
            />
            <div className="grid grid-cols-3 gap-3">
              <FloatingInput
                label="Метка кода банка"
                value={form.bank_code_label ?? ""}
                onChange={(e) => setForm({ ...form, bank_code_label: e.target.value })}
              />
              <FloatingInput
                label={form.bank_code_label || "Код банка"}
                required
                value={form.bank_code ?? ""}
                onChange={(e) => setForm({ ...form, bank_code: e.target.value })}
              />
              <FloatingInput
                label="Счёт"
                required
                value={form.account ?? ""}
                onChange={(e) => setForm({ ...form, account: e.target.value })}
              />
            </div>

            {/* Дополнительные счета */}
            {(form as LicensorEntity).id && (
              <AccountsSection licensorId={(form as LicensorEntity).id} />
            )}
          </div>
        )}
      </Modal>
    </RoleGate>
  );
}

function LicensorsNoAccess() {
  return (
    <div className="p-8">
      <div className="card flex flex-col items-center justify-center py-16 text-center">
        <i className="bi bi-shield-lock text-5xl text-gray-300 dark:text-gray-600 mb-4" />
        <p className="text-base font-semibold text-gray-700 dark:text-gray-300 mb-1">
          Доступ ограничен
        </p>
        <p className="text-sm text-gray-500 dark:text-gray-400">
          Раздел доступен администраторам, юристам и руководителям.
        </p>
      </div>
    </div>
  );
}

// ─── Вспомогательные компоненты ───────────────────────────────────────────────

function SectionTitle({ children }: { children: React.ReactNode }) {
  return (
    <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500 pt-2">
      {children}
    </p>
  );
}

function AccountsSection({ licensorId }: { licensorId: number }) {
  const { data, mutate } = useSWR<LicensorBankAccount[]>(`/licensors/${licensorId}/accounts`, fetcher);
  const { toast } = useToast();
  const [editing, setEditing] = useState<Partial<LicensorBankAccount> | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  function openNew() {
    setEditing({
      currency: "KZT",
      bank: "",
      bank_code_label: "БИК",
      bank_code: "",
      account: "",
      is_primary: !data?.length,
      swift: "",
    });
    setError(null);
  }

  function openEdit(a: LicensorBankAccount) {
    setEditing(a);
    setError(null);
  }

  async function save() {
    if (!editing) return;
    setBusy(true);
    setError(null);
    try {
      if ((editing as LicensorBankAccount).id) {
        await api(`/licensors/${licensorId}/accounts/${(editing as LicensorBankAccount).id}`, {
          method: "PATCH",
          body: editing,
        });
        toast.success("Счёт обновлён");
      } else {
        await api(`/licensors/${licensorId}/accounts`, { method: "POST", body: editing });
        toast.success("Счёт добавлен");
      }
      setEditing(null);
      await mutate();
    } catch (err) {
      const msg = err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Ошибка";
      setError(msg);
      toast.error(msg);
    } finally {
      setBusy(false);
    }
  }

  async function remove(id: number) {
    if (!confirm("Удалить счёт?")) return;
    await api(`/licensors/${licensorId}/accounts/${id}`, { method: "DELETE" });
    await mutate();
    toast.success("Счёт удалён");
  }

  return (
    <div className="border-t border-gray-200 dark:border-gray-700 pt-4 mt-2">
      <div className="flex items-center justify-between mb-3">
        <div>
          <p className="text-xs font-semibold uppercase tracking-wide text-gray-400 dark:text-gray-500">
            Дополнительные банковские счета
          </p>
          <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
            Несколько счетов по разным валютам. Основной счёт каждой валюты помечается ★.
          </p>
        </div>
        <button type="button" onClick={openNew} className="btn-ghost text-sm">
          <i className="bi bi-plus-lg mr-1" /> Добавить счёт
        </button>
      </div>

      {error && (
        <div className="text-danger text-sm bg-danger/10 dark:bg-danger-500/10 px-3 py-2 rounded-lg flex items-center gap-2 mb-3">
          <i className="bi bi-exclamation-triangle shrink-0" />
          {error}
        </div>
      )}

      <div className="space-y-2">
        {data?.map((a) => (
          <div
            key={a.id}
            className="flex items-center gap-3 p-3 rounded-xl border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-900"
          >
            <span className="font-mono text-sm font-bold uppercase text-primary dark:text-blue-300">
              {a.currency}
            </span>
            {a.is_primary && (
              <span title="Основной счёт" className="text-warning-500 text-sm">★</span>
            )}
            <div className="flex-1 text-sm min-w-0">
              <p className="text-gray-800 dark:text-gray-200 truncate">
                {a.bank} • {a.bank_code_label} {a.bank_code}
              </p>
              <p className="text-xs text-gray-500 dark:text-gray-400 mt-0.5">
                Счёт: {a.account}{a.swift ? ` • SWIFT: ${a.swift}` : ""}
              </p>
              {a.note && (
                <p className="text-xs text-gray-400 dark:text-gray-500 italic mt-0.5">{a.note}</p>
              )}
            </div>
            <button type="button" onClick={() => openEdit(a)} className="btn-ghost text-sm">
              <i className="bi bi-pencil" />
            </button>
            <button
              type="button"
              onClick={() => remove(a.id)}
              className="btn-ghost text-sm text-danger"
            >
              <i className="bi bi-trash" />
            </button>
          </div>
        ))}
        {data && data.length === 0 && (
          <div className="text-sm text-gray-500 dark:text-gray-400 text-center py-4 rounded-xl border border-dashed border-gray-200 dark:border-gray-700">
            Дополнительных счетов пока нет
          </div>
        )}
      </div>

      {/* Форма добавления/редактирования счёта */}
      {editing && (
        <div className="rounded-xl border border-primary/40 dark:border-primary/30 p-4 mt-3 bg-primary/5 dark:bg-primary/5 space-y-3">
          <p className="text-sm font-semibold text-gray-800 dark:text-gray-200 mb-1">
            {(editing as LicensorBankAccount).id ? "Редактирование счёта" : "Новый счёт"}
          </p>
          <div className="grid grid-cols-3 gap-3">
            <div>
              <label className="label">Валюта</label>
              <select
                className="input"
                value={editing.currency ?? "KZT"}
                onChange={(e) => setEditing({ ...editing, currency: e.target.value })}
              >
                {CURRENCY_OPTS.map((c) => (
                  <option key={c.value} value={c.value}>{c.label}</option>
                ))}
              </select>
            </div>
            <FloatingInput
              label="Метка кода банка"
              value={editing.bank_code_label ?? ""}
              onChange={(e) => setEditing({ ...editing, bank_code_label: e.target.value })}
            />
            <FloatingInput
              label="Код банка"
              required
              value={editing.bank_code ?? ""}
              onChange={(e) => setEditing({ ...editing, bank_code: e.target.value })}
            />
          </div>
          <FloatingInput
            label="Банк"
            required
            value={editing.bank ?? ""}
            onChange={(e) => setEditing({ ...editing, bank: e.target.value })}
          />
          <div className="grid grid-cols-2 gap-3">
            <FloatingInput
              label="Номер счёта"
              required
              value={editing.account ?? ""}
              onChange={(e) => setEditing({ ...editing, account: e.target.value })}
            />
            <FloatingInput
              label="SWIFT (опционально)"
              value={editing.swift ?? ""}
              onChange={(e) => setEditing({ ...editing, swift: e.target.value })}
            />
          </div>
          <FloatingInput
            label="Примечание"
            value={editing.note ?? ""}
            onChange={(e) => setEditing({ ...editing, note: e.target.value })}
          />
          <label className="flex items-center gap-2.5 text-sm text-gray-800 dark:text-gray-200 cursor-pointer">
            <input
              type="checkbox"
              checked={!!editing.is_primary}
              onChange={(e) => setEditing({ ...editing, is_primary: e.target.checked })}
              className="h-4 w-4 rounded border-gray-300 text-primary focus:ring-primary dark:border-gray-600 dark:bg-gray-700"
            />
            Основной счёт для этой валюты
          </label>
          <div className="flex justify-end gap-2 pt-1">
            <button type="button" className="btn-secondary" onClick={() => setEditing(null)}>
              Отмена
            </button>
            <button type="button" className="btn-primary" disabled={busy} onClick={save}>
              {busy ? "Сохранение…" : "Сохранить счёт"}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
