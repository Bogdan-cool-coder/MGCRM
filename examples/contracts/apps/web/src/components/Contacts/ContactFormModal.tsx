"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { UserSelect } from "@/components/UserSelect";
import { api, ApiError } from "@/lib/api";
import type { Company, Contact, User } from "@/lib/types";
import { useDupCheck } from "@/hooks/useDupCheck";
import { DupCheckWarning } from "@/components/Duplicates/DupCheckWarning";

interface ContactFormModalProps {
  open: boolean;
  contact: Contact | null;
  companies: Company[] | undefined;
  users: User[] | undefined;
  /** Если задан — модалка открывается с предзаполненной компанией и блокирует её смену. */
  lockedCompanyId?: number | null;
  onClose: () => void;
  onSaved: () => void;
}

type FormState = {
  full_name: string;
  email: string;
  phone: string;
  position: string;
  company_id: string;
  is_primary: boolean;
  owner_id: string;
  tg_username: string;
  notes: string;
};

function emptyForm(lockedCompanyId?: number | null): FormState {
  return {
    full_name: "",
    email: "",
    phone: "",
    position: "",
    company_id: lockedCompanyId ? String(lockedCompanyId) : "",
    is_primary: false,
    owner_id: "",
    tg_username: "",
    notes: "",
  };
}

function fromContact(contact: Contact): FormState {
  return {
    full_name: contact.full_name,
    email: contact.email ?? "",
    phone: contact.phone ?? "",
    position: contact.position ?? "",
    company_id: contact.company_id ? String(contact.company_id) : "",
    is_primary: contact.is_primary,
    owner_id: contact.owner_id ? String(contact.owner_id) : "",
    tg_username: contact.tg_username ?? "",
    notes: contact.notes ?? "",
  };
}

export function ContactFormModal({
  open, contact, companies, users, lockedCompanyId, onClose, onSaved,
}: ContactFormModalProps) {
  const isEdit = !!contact;
  const [form, setForm] = useState<FormState>(emptyForm(lockedCompanyId));
  const [initialForm, setInitialForm] = useState<FormState>(emptyForm(lockedCompanyId));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const emailDup = useDupCheck("contact", "email");
  const phoneDup = useDupCheck("contact", "phone");

  useEffect(() => {
    if (!open) return;
    const next = contact ? fromContact(contact) : emptyForm(lockedCompanyId);
    setForm(next);
    setInitialForm(next);
    setError(null);
    setSaving(false);
    emailDup.reset();
    phoneDup.reset();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, contact, lockedCompanyId]);

  function isValid() {
    return form.full_name.trim().length > 0;
  }

  function isDirty() {
    return JSON.stringify(form) !== JSON.stringify(initialForm);
  }

  async function save(): Promise<boolean> {
    if (!isValid()) {
      setError("Укажите имя контакта");
      return false;
    }
    setSaving(true);
    setError(null);
    const body = {
      full_name: form.full_name.trim(),
      email: form.email.trim() || null,
      phone: form.phone.trim() || null,
      position: form.position.trim() || null,
      company_id: form.company_id ? Number(form.company_id) : null,
      is_primary: form.is_primary,
      owner_id: form.owner_id ? Number(form.owner_id) : null,
      tg_username: form.tg_username.trim() || null,
      notes: form.notes.trim() || null,
    };
    try {
      if (contact) {
        await api(`/contacts/${contact.id}`, { method: "PATCH", body });
      } else {
        await api("/contacts", { method: "POST", body });
      }
      onSaved();
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

  const companyOptions = (companies ?? [])
    .slice()
    .sort((a, b) => a.legal_name.localeCompare(b.legal_name, "ru"));

  return (
    <Modal
      open={open}
      title={isEdit ? "Редактирование контакта" : "Новый контакт"}
      onClose={onClose}
      onTrySave={save}
      isDirty={isDirty()}
      width="md"
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
          label="ФИО"
          value={form.full_name}
          onChange={(v) => setForm({ ...form, full_name: v })}
          required
          placeholder="Иван Иванов"
        />

        <div className="grid grid-cols-2 gap-3">
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
              entityType="contact"
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
              entityType="contact"
              onDismiss={phoneDup.dismiss}
            />
          </div>
        </div>

        <Field
          label="Должность"
          value={form.position}
          onChange={(v) => setForm({ ...form, position: v })}
          placeholder="Руководитель отдела продаж"
        />

        <div>
          <label className="label">Компания</label>
          <select
            className="input"
            value={form.company_id}
            onChange={(e) => setForm({ ...form, company_id: e.target.value })}
            disabled={!!lockedCompanyId}
          >
            <option value="">— без компании —</option>
            {companyOptions.map((c) => (
              <option key={c.id} value={c.id}>
                {c.legal_name}{c.short_name ? ` (${c.short_name})` : ""}
              </option>
            ))}
          </select>
          {!!lockedCompanyId && (
            <div className="text-xs text-gray-500 mt-1">
              Компания задана автоматически: новый контакт привяжется к открытой карточке.
            </div>
          )}
        </div>

        <div>
          <label className="label">Владелец</label>
          <UserSelect
            value={form.owner_id}
            onChange={(v) => setForm({ ...form, owner_id: v })}
            users={users}
            placeholder="—"
          />
        </div>

        <div className="grid grid-cols-2 gap-3">
          <Field
            label="Telegram"
            value={form.tg_username}
            onChange={(v) => setForm({ ...form, tg_username: v })}
            placeholder="@username"
          />
          <div className="flex items-end pb-1">
            <label className="inline-flex items-center gap-2 text-sm select-none">
              <input
                type="checkbox"
                checked={form.is_primary}
                onChange={(e) => setForm({ ...form, is_primary: e.target.checked })}
              />
              Основной контакт компании
            </label>
          </div>
        </div>

        <div>
          <label className="label">Заметки</label>
          <textarea
            className="input min-h-[80px]"
            value={form.notes}
            onChange={(e) => setForm({ ...form, notes: e.target.value })}
            placeholder="Дополнительные сведения, контекст знакомства и т.д."
          />
        </div>
      </div>
    </Modal>
  );
}
