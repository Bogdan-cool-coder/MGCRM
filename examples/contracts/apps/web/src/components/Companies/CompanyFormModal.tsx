"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { api, ApiError } from "@/lib/api";
import type { ClientCategory, ClientGroup, Company } from "@/lib/types";
import { useDupCheck } from "@/hooks/useDupCheck";
import { DupCheckWarning } from "@/components/Duplicates/DupCheckWarning";

interface CompanyFormModalProps {
  open: boolean;
  company: Company | null;
  groups: ClientGroup[] | undefined;
  categories: ClientCategory[] | undefined;
  onClose: () => void;
  onSaved: (saved: Company) => void;
}

type FormState = {
  legal_name: string;
  short_name: string;
  tax_id: string;
  country: string;
  city: string;
  website: string;
  phone: string;
  email: string;
  industry: string;
  notes: string;
  group_id: string;
  category_code: string;
};

const COUNTRY_OPTS = [
  { value: "", label: "—" },
  { value: "kz", label: "Казахстан (KZ)" },
  { value: "uz", label: "Узбекистан (UZ)" },
  { value: "ru", label: "Россия (RU)" },
  { value: "by", label: "Беларусь (BY)" },
];

function emptyForm(): FormState {
  return {
    legal_name: "",
    short_name: "",
    tax_id: "",
    country: "",
    city: "",
    website: "",
    phone: "",
    email: "",
    industry: "",
    notes: "",
    group_id: "",
    category_code: "",
  };
}

function fromCompany(c: Company): FormState {
  return {
    legal_name: c.legal_name,
    short_name: c.short_name ?? "",
    tax_id: c.tax_id ?? "",
    country: c.country ?? "",
    city: c.city ?? "",
    website: c.website ?? "",
    phone: c.phone ?? "",
    email: c.email ?? "",
    industry: c.industry ?? "",
    notes: c.notes ?? "",
    group_id: c.group_id ? String(c.group_id) : "",
    category_code: c.category_code ?? "",
  };
}

export function CompanyFormModal({
  open, company, groups, categories, onClose, onSaved,
}: CompanyFormModalProps) {
  const isEdit = !!company;
  const [form, setForm] = useState<FormState>(emptyForm());
  const [initialForm, setInitialForm] = useState<FormState>(emptyForm());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const emailDup = useDupCheck("company", "email");
  const phoneDup = useDupCheck("company", "phone");
  const taxDup = useDupCheck("company", "tax_id");

  useEffect(() => {
    if (!open) return;
    const next = company ? fromCompany(company) : emptyForm();
    setForm(next);
    setInitialForm(next);
    setError(null);
    setSaving(false);
    emailDup.reset();
    phoneDup.reset();
    taxDup.reset();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, company]);

  function isValid() {
    return form.legal_name.trim().length > 0;
  }

  function isDirty() {
    return JSON.stringify(form) !== JSON.stringify(initialForm);
  }

  async function save(): Promise<boolean> {
    if (!isValid()) {
      setError("Укажите название компании");
      return false;
    }
    setSaving(true);
    setError(null);
    const body = {
      legal_name: form.legal_name.trim(),
      short_name: form.short_name.trim() || null,
      tax_id: form.tax_id.trim() || null,
      country: form.country || null,
      city: form.city.trim() || null,
      website: form.website.trim() || null,
      phone: form.phone.trim() || null,
      email: form.email.trim() || null,
      industry: form.industry.trim() || null,
      notes: form.notes.trim() || null,
      group_id: form.group_id ? Number(form.group_id) : null,
      category_code: form.category_code || null,
    };
    try {
      let saved: Company;
      if (company) {
        saved = await api<Company>(`/companies/${company.id}`, { method: "PATCH", body });
      } else {
        saved = await api<Company>("/companies", { method: "POST", body });
      }
      onSaved(saved);
      return true;
    } catch (err) {
      setError(err instanceof ApiError
        ? String((err.detail as { detail?: string })?.detail ?? err.message)
        : "Не удалось сохранить");
      return false;
    } finally {
      setSaving(false);
    }
  }

  const groupOptions = (groups ?? []).slice().sort((a, b) => a.name.localeCompare(b.name, "ru"));
  const categoryOptions = (categories ?? []).slice().sort((a, b) => a.sort_order - b.sort_order);

  return (
    <Modal
      open={open}
      title={isEdit ? "Редактирование компании" : "Новая компания"}
      onClose={onClose}
      onTrySave={save}
      isDirty={isDirty()}
      width="lg"
      footer={
        <>
          <button className="btn-secondary" onClick={onClose}>Отмена</button>
          <button
            className="btn-primary"
            onClick={async () => { if (await save()) onClose(); }}
            disabled={saving || !isValid()}
          >
            {saving ? "Сохранение…" : "Сохранить"}
          </button>
        </>
      }
    >
      <div className="space-y-3">
        {error && <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>}

        <Field
          label="Юридическое название"
          value={form.legal_name}
          onChange={(v) => setForm({ ...form, legal_name: v })}
          required
          placeholder="ТОО «Ромашка»"
        />

        <Field
          label="Краткое название"
          value={form.short_name}
          onChange={(v) => setForm({ ...form, short_name: v })}
          placeholder="Ромашка"
          hint="Используется в списках и заголовках, когда нужно покороче"
        />

        <div className="grid grid-cols-3 gap-3">
          <div>
            <label className="label">Налоговый номер</label>
            <input
              className="input"
              placeholder="БИН / ИНН / TIN"
              inputMode="numeric"
              value={form.tax_id}
              onChange={(e) => { setForm({ ...form, tax_id: e.target.value }); taxDup.reset(); }}
              onBlur={(e) => taxDup.check(e.target.value)}
            />
            <DupCheckWarning
              matches={taxDup.matches}
              checking={taxDup.checking}
              entityType="company"
              onDismiss={taxDup.dismiss}
            />
          </div>
          <div>
            <label className="label">Страна</label>
            <select
              className="input"
              value={form.country}
              onChange={(e) => setForm({ ...form, country: e.target.value })}
            >
              {COUNTRY_OPTS.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
          </div>
          <Field
            label="Город"
            value={form.city}
            onChange={(v) => setForm({ ...form, city: v })}
          />
        </div>

        <div className="grid grid-cols-3 gap-3">
          <Field
            label="Сайт"
            value={form.website}
            onChange={(v) => setForm({ ...form, website: v })}
            placeholder="example.com"
          />
          <div>
            <label className="label">Email</label>
            <input
              className="input"
              type="email"
              value={form.email}
              onChange={(e) => { setForm({ ...form, email: e.target.value }); emailDup.reset(); }}
              onBlur={(e) => emailDup.check(e.target.value)}
            />
            <DupCheckWarning
              matches={emailDup.matches}
              checking={emailDup.checking}
              entityType="company"
              onDismiss={emailDup.dismiss}
            />
          </div>
          <div>
            <label className="label">Телефон</label>
            <input
              className="input"
              type="tel"
              value={form.phone}
              onChange={(e) => { setForm({ ...form, phone: e.target.value }); phoneDup.reset(); }}
              onBlur={(e) => phoneDup.check(e.target.value)}
            />
            <DupCheckWarning
              matches={phoneDup.matches}
              checking={phoneDup.checking}
              entityType="company"
              onDismiss={phoneDup.dismiss}
            />
          </div>
        </div>

        <Field
          label="Индустрия"
          value={form.industry}
          onChange={(v) => setForm({ ...form, industry: v })}
          placeholder="ритейл, общепит, e-com…"
        />

        <div className="grid grid-cols-2 gap-3">
          <div>
            <label className="label">Холдинг (группа юрлиц)</label>
            <select
              className="input"
              value={form.group_id}
              onChange={(e) => setForm({ ...form, group_id: e.target.value })}
            >
              <option value="">— без холдинга —</option>
              {groupOptions.map((g) => (
                <option key={g.id} value={g.id}>{g.name}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Категория клиента</label>
            <select
              className="input"
              value={form.category_code}
              onChange={(e) => setForm({ ...form, category_code: e.target.value })}
            >
              <option value="">—</option>
              {categoryOptions.map((c) => (
                <option key={c.id} value={c.code}>
                  {c.code} · {c.name}
                </option>
              ))}
            </select>
          </div>
        </div>

        <div>
          <label className="label">Заметки</label>
          <textarea
            className="input min-h-[80px]"
            value={form.notes}
            onChange={(e) => setForm({ ...form, notes: e.target.value })}
            placeholder="Контекст: как познакомились, особенности и т.д."
          />
        </div>
      </div>
    </Modal>
  );
}
