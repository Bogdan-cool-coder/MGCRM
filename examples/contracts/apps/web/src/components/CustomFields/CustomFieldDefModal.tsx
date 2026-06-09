"use client";

import { useEffect, useState } from "react";
import { Modal } from "@/components/Modal";
import { api, ApiError } from "@/lib/api";
import {
  ENTITY_SCOPE_LABELS,
  CUSTOM_FIELD_KIND_LABELS,
  type CustomFieldDef,
  type EntityScope,
  type CustomFieldKind,
} from "@/lib/types";

interface CustomFieldDefModalProps {
  open: boolean;
  def: CustomFieldDef | null;
  defaultScope?: EntityScope;
  onClose: () => void;
  onSaved: () => void;
}

const SCOPES = Object.keys(ENTITY_SCOPE_LABELS) as EntityScope[];
const KINDS = Object.keys(CUSTOM_FIELD_KIND_LABELS) as CustomFieldKind[];

interface FormState {
  entity_scope: EntityScope;
  label_ru: string;
  code: string;
  kind: CustomFieldKind;
  sort_order: string;
  options_text: string;
  default_value: string;
  is_required: boolean;
  is_active: boolean;
}

function buildForm(def: CustomFieldDef | null, defaultScope?: EntityScope): FormState {
  if (def) {
    return {
      entity_scope: def.entity_scope,
      label_ru: def.label_ru,
      code: def.code,
      kind: def.kind,
      sort_order: String(def.sort_order),
      options_text: def.options_json.join("\n"),
      default_value: def.default_value ?? "",
      is_required: def.is_required,
      is_active: def.is_active,
    };
  }
  return {
    entity_scope: defaultScope ?? "lead",
    label_ru: "",
    code: "",
    kind: "text",
    sort_order: "10",
    options_text: "",
    default_value: "",
    is_required: false,
    is_active: true,
  };
}

const CODE_REGEX = /^[a-z][a-z0-9_]*$/;

export function CustomFieldDefModal({
  open, def, defaultScope, onClose, onSaved,
}: CustomFieldDefModalProps) {
  const isEdit = def !== null;
  const [form, setForm] = useState<FormState>(() => buildForm(def, defaultScope));
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [codeError, setCodeError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setForm(buildForm(def, defaultScope));
      setError(null);
      setCodeError(null);
    }
  }, [open, def, defaultScope]);

  function set(field: keyof FormState, value: unknown) {
    setForm((f) => ({ ...f, [field]: value }));
  }

  const needsOptions = form.kind === "select" || form.kind === "multiselect";

  async function handleSubmit(e: React.FormEvent) {
    e.preventDefault();
    setError(null);
    setCodeError(null);

    if (!form.label_ru.trim()) { setError("Введите название поля"); return; }
    if (!form.code.trim()) { setError("Введите код поля"); return; }
    if (!CODE_REGEX.test(form.code)) {
      setCodeError("Только латиница, цифры и _. Должен начинаться с буквы.");
      return;
    }
    if (needsOptions && !form.options_text.trim()) {
      setError("Укажите хотя бы один вариант ответа");
      return;
    }

    const options = needsOptions
      ? form.options_text.split("\n").map((s) => s.trim()).filter(Boolean)
      : [];

    const payload = {
      entity_scope: form.entity_scope,
      label_ru: form.label_ru.trim(),
      code: form.code.trim(),
      kind: form.kind,
      sort_order: parseInt(form.sort_order) || 10,
      options_json: options,
      default_value: form.default_value.trim() || null,
      is_required: form.is_required,
      is_active: form.is_active,
    };

    setSaving(true);
    try {
      if (isEdit) {
        await api(`/custom-field-defs/${def!.id}`, { method: "PATCH", body: payload });
      } else {
        await api("/custom-field-defs", { method: "POST", body: payload });
      }
      onSaved();
      onClose();
    } catch (err) {
      if (err instanceof ApiError) {
        const detail = err.detail;
        if (typeof detail === "object" && detail !== null && "detail" in detail) {
          const d = (detail as { detail: unknown }).detail;
          if (typeof d === "string" && d.toLowerCase().includes("code")) {
            setCodeError("Поле с таким кодом уже существует для этой сущности");
            return;
          }
          setError(typeof d === "string" ? d : "Не удалось сохранить");
        } else {
          setError("Не удалось сохранить");
        }
      } else {
        setError("Не удалось сохранить");
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <Modal
      open={open}
      title={isEdit ? "Редактировать поле" : "Новое поле"}
      onClose={onClose}
      width="md"
      footer={
        <>
          <button type="button" onClick={onClose} className="btn-ghost" disabled={saving}>
            Отмена
          </button>
          <button
            type="submit"
            form="cfdef-form"
            className="btn-primary"
            disabled={saving}
          >
            {saving ? "Сохраняем…" : "Сохранить"}
          </button>
        </>
      }
    >
      <form id="cfdef-form" onSubmit={handleSubmit} className="space-y-4">
        {error && (
          <div className="text-sm text-danger bg-danger/10 px-3 py-2 rounded">{error}</div>
        )}

        <div>
          <label className="label">Сущность *</label>
          <select
            className="input w-full"
            value={form.entity_scope}
            onChange={(e) => set("entity_scope", e.target.value)}
            disabled={isEdit}
          >
            {SCOPES.map((s) => (
              <option key={s} value={s}>{ENTITY_SCOPE_LABELS[s]}</option>
            ))}
          </select>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label">Название поля *</label>
            <input
              className="input w-full"
              value={form.label_ru}
              onChange={(e) => set("label_ru", e.target.value)}
              placeholder="Регион"
            />
          </div>
          <div>
            <label className="label">Код (snake_case) *</label>
            <input
              className={`input w-full ${codeError ? "border-danger" : ""}`}
              value={form.code}
              onChange={(e) => { set("code", e.target.value); setCodeError(null); }}
              placeholder="region"
              disabled={isEdit}
            />
            {codeError ? (
              <p className="text-xs text-danger mt-1">{codeError}</p>
            ) : (
              <p className="text-xs text-gray-400 mt-1">
                Только латиница, цифры и _. {isEdit ? "Изменить нельзя." : "Изменить после создания нельзя."}
              </p>
            )}
          </div>
        </div>

        <div className="grid grid-cols-2 gap-4">
          <div>
            <label className="label">Тип поля *</label>
            <select
              className="input w-full"
              value={form.kind}
              onChange={(e) => set("kind", e.target.value)}
            >
              {KINDS.map((k) => (
                <option key={k} value={k}>{CUSTOM_FIELD_KIND_LABELS[k]}</option>
              ))}
            </select>
          </div>
          <div>
            <label className="label">Порядок сортировки</label>
            <input
              type="number"
              className="input w-full"
              value={form.sort_order}
              onChange={(e) => set("sort_order", e.target.value)}
              min={0}
            />
          </div>
        </div>

        {needsOptions && (
          <div>
            <label className="label">Варианты ответа *</label>
            <textarea
              className="input w-full"
              rows={4}
              value={form.options_text}
              onChange={(e) => set("options_text", e.target.value)}
              placeholder={"Вариант 1\nВариант 2\nВариант 3"}
            />
            <p className="text-xs text-gray-400 mt-1">По одному варианту на строке</p>
          </div>
        )}

        <div>
          <label className="label">Значение по умолчанию</label>
          <input
            className="input w-full"
            value={form.default_value}
            onChange={(e) => set("default_value", e.target.value)}
            placeholder="—"
          />
        </div>

        <div className="space-y-2">
          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_required}
              onChange={(e) => set("is_required", e.target.checked)}
              className="w-4 h-4 accent-primary"
            />
            Обязательное поле
          </label>
          <label className="flex items-center gap-2 text-sm cursor-pointer">
            <input
              type="checkbox"
              checked={form.is_active}
              onChange={(e) => set("is_active", e.target.checked)}
              className="w-4 h-4 accent-primary"
            />
            Активное (показывать в карточках)
          </label>
        </div>
      </form>
    </Modal>
  );
}
