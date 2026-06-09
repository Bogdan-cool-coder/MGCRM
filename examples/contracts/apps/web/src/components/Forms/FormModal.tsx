"use client";

import { useEffect, useState } from "react";
import useSWR from "swr";
import { Modal } from "@/components/Modal";
import { Field } from "@/components/Field";
import { FieldsBuilder } from "@/components/Forms/FieldsBuilder";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Channel, CrmForm, FormField } from "@/lib/types";
import { formatDateTime } from "@/lib/dates";

interface Props {
  open: boolean;
  form: CrmForm | null;
  onClose: () => void;
  onSaved: () => void;
}

type State = {
  name: string;
  public_slug: string;
  channel_id: string;
  thank_you_text: string;
  is_active: boolean;
  fields: FormField[];
};

function fromForm(f: CrmForm): State {
  return {
    name: f.name,
    public_slug: f.public_slug,
    channel_id: f.channel_id ? String(f.channel_id) : "",
    thank_you_text: f.thank_you_text ?? "",
    is_active: f.is_active,
    fields: f.fields,
  };
}

function emptyForm(): State {
  return {
    name: "",
    public_slug: "",
    channel_id: "",
    thank_you_text: "Спасибо! Мы свяжемся с вами.",
    is_active: true,
    fields: [],
  };
}

export function FormModal({ open, form, onClose, onSaved }: Props) {
  const isEdit = !!form;
  const [state, setState] = useState<State>(emptyForm());
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const { data: channels } = useSWR<Channel[]>(open ? "/channels" : null, fetcher);

  useEffect(() => {
    if (!open) return;
    setError(null);
    setSaving(false);
    setState(form ? fromForm(form) : emptyForm());
  }, [open, form]);

  function validate(): string | null {
    if (!state.name.trim()) return "Укажите название формы";
    for (let i = 0; i < state.fields.length; i++) {
      const f = state.fields[i];
      if (!f.name.trim()) return `Поле #${i + 1}: укажите имя (name)`;
      if (!f.label.trim()) return `Поле #${i + 1}: укажите подпись (label)`;
      // Имя поля — латиница/цифры/подчёркивания (мягкая проверка)
      if (!/^[A-Za-z][A-Za-z0-9_]*$/.test(f.name.trim())) {
        return `Поле #${i + 1}: имя должно начинаться с латиницы и содержать только латиницу/цифры/«_»`;
      }
      if (f.type === "select" && (!f.options || f.options.length === 0)) {
        return `Поле #${i + 1}: для типа «select» нужно указать хотя бы один вариант`;
      }
    }
    const names = state.fields.map((f) => f.name.trim());
    const dups = names.filter((n, idx) => names.indexOf(n) !== idx);
    if (dups.length > 0) return `Дубликаты имён полей: ${Array.from(new Set(dups)).join(", ")}`;
    return null;
  }

  async function save() {
    const err = validate();
    if (err) {
      setError(err);
      return;
    }
    setSaving(true);
    setError(null);

    const body = {
      name: state.name.trim(),
      public_slug: state.public_slug.trim() || null,
      fields: state.fields.map((f) => {
        const base: Record<string, unknown> = {
          name: f.name.trim(),
          label: f.label.trim(),
          type: f.type,
          required: f.required,
        };
        if (f.type === "select" && f.options) base.options = f.options;
        return base;
      }),
      channel_id: state.channel_id ? Number(state.channel_id) : null,
      thank_you_text: state.thank_you_text.trim() || null,
      is_active: state.is_active,
    };

    try {
      if (form) {
        await api(`/forms/${form.id}`, { method: "PATCH", body });
      } else {
        await api("/forms", { method: "POST", body });
      }
      onSaved();
      onClose();
    } catch (e) {
      setError(
        e instanceof ApiError
          ? String((e.detail as { detail?: string })?.detail ?? e.message)
          : "Не удалось сохранить",
      );
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      onClose={onClose}
      title={isEdit ? "Редактирование формы" : "Новая форма"}
      width="lg"
      footer={
        <>
          <button className="btn-secondary" onClick={onClose}>Отмена</button>
          <button className="btn-primary" onClick={save} disabled={saving}>
            {saving ? "Сохранение…" : isEdit ? "Сохранить" : "Создать"}
          </button>
        </>
      }
    >
      <div className="space-y-3">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded whitespace-pre-wrap">{error}</div>
        )}

        <Field
          label="Название"
          value={state.name}
          onChange={(v) => setState({ ...state, name: v })}
          required
          placeholder="Например: Заявка с лендинга «Стартовый тариф»"
        />

        <div className="grid grid-cols-2 gap-3">
          <Field
            label="Slug (URL)"
            value={state.public_slug}
            onChange={(v) => setState({ ...state, public_slug: v })}
            placeholder="оставьте пустым для авто-генерации"
            hint={state.public_slug ? `URL: /f/${state.public_slug}` : "Будет /f/<автогенерация>"}
          />
          <div>
            <label className="label">Канал (для авто-Lead)</label>
            <select
              className="input"
              value={state.channel_id}
              onChange={(e) => setState({ ...state, channel_id: e.target.value })}
            >
              <option value="">— не задан (submission будет потерян) —</option>
              {(channels ?? []).map((c) => (
                <option key={c.id} value={c.id}>{c.name} ({c.kind})</option>
              ))}
            </select>
          </div>
        </div>

        <div>
          <label className="label">Текст «Спасибо»</label>
          <textarea
            className="input min-h-[60px]"
            value={state.thank_you_text}
            onChange={(e) => setState({ ...state, thank_you_text: e.target.value })}
            placeholder="Спасибо! Мы свяжемся с вами."
          />
        </div>

        <label className="flex items-center gap-2 text-sm">
          <input
            type="checkbox"
            checked={state.is_active}
            onChange={(e) => setState({ ...state, is_active: e.target.checked })}
          />
          Форма активна (отдаётся по публичному slug)
        </label>

        <div className="border-t border-gray-200 pt-3">
          <div className="flex items-center justify-between mb-2">
            <div>
              <h3 className="text-sm font-semibold">Поля формы</h3>
              <p className="text-xs text-gray-500">
                Имя поля — латиница/цифры/«_», без пробелов. Подпись — что увидит пользователь.
              </p>
            </div>
          </div>
          <FieldsBuilder
            fields={state.fields}
            onChange={(next) => setState({ ...state, fields: next })}
          />
        </div>

        {isEdit && form && (
          <div className="text-xs text-gray-500 border-t border-gray-200 pt-3 mt-3">
            Создана: {formatDateTime(form.created_at)}
            {" · "}
            Обновлена: {formatDateTime(form.updated_at)}
            {" · "}
            ID: #{form.id}
          </div>
        )}
      </div>
    </Modal>
  );
}
