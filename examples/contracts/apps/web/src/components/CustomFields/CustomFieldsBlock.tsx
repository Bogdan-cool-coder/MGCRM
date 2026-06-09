"use client";

import { useState } from "react";
import useSWR from "swr";
import { fetcher, api, ApiError } from "@/lib/api";
import { type CustomFieldDef, type EntityScope } from "@/lib/types";
import { CustomFieldInput, CustomFieldDisplay } from "./CustomFieldInput";

const ENTITY_PATH_MAP: Record<EntityScope, string> = {
  lead: "leads",
  contact: "contacts",
  company: "companies",
  counterparty: "counterparties",
  deal: "deals",
  contract: "contracts",
  subscription: "subscriptions",
};

interface CustomFieldsBlockProps {
  entityScope: EntityScope;
  entityId: number;
  extraFields: Record<string, unknown>;
  onSaved?: () => void;
}

export function CustomFieldsBlock({
  entityScope,
  entityId,
  extraFields,
  onSaved,
}: CustomFieldsBlockProps) {
  const { data: defs, isLoading } = useSWR<CustomFieldDef[]>(
    `/custom-field-defs?entity_scope=${entityScope}&is_active=true`,
    fetcher,
  );

  const [editing, setEditing] = useState(false);
  const [localFields, setLocalFields] = useState<Record<string, unknown>>({});
  const [saving, setSaving] = useState(false);
  const [saveError, setSaveError] = useState<string | null>(null);

  // Loading skeleton
  if (isLoading) {
    return (
      <div className="card p-4 space-y-3 animate-pulse">
        <div className="h-4 bg-gray-200 rounded w-1/3" />
        <div className="h-3 bg-gray-200 rounded w-3/4" />
        <div className="h-3 bg-gray-200 rounded w-2/3" />
        <div className="h-3 bg-gray-200 rounded w-1/2" />
      </div>
    );
  }

  // No active fields — don't render at all
  if (!defs || defs.length === 0) return null;

  function startEdit() {
    const initial: Record<string, unknown> = {};
    for (const d of defs!) {
      initial[d.code] = extraFields[d.code] ?? d.default_value ?? null;
    }
    setLocalFields(initial);
    setSaveError(null);
    setEditing(true);
  }

  function cancelEdit() {
    setEditing(false);
    setSaveError(null);
  }

  async function handleSave() {
    setSaveError(null);
    setSaving(true);
    try {
      const path = ENTITY_PATH_MAP[entityScope];
      await api(`/${path}/${entityId}/extra-fields`, {
        method: "PATCH",
        body: { extra_fields: localFields },
      });
      setEditing(false);
      onSaved?.();
    } catch (err) {
      if (err instanceof ApiError) {
        const d = (err.detail as { detail?: string })?.detail;
        setSaveError(typeof d === "string" ? d : "Не удалось сохранить. Проверь обязательные поля.");
      } else {
        setSaveError("Не удалось сохранить. Проверь обязательные поля.");
      }
    } finally {
      setSaving(false);
    }
  }

  return (
    <div className="card p-4">
      <div className="flex items-center justify-between mb-3">
        <span className="text-xs uppercase tracking-wide text-gray-500 font-semibold">
          Дополнительные поля
        </span>
        {!editing && (
          <button
            type="button"
            onClick={startEdit}
            className="btn-ghost text-xs py-1 px-2"
          >
            <i className="bi bi-pencil mr-1" />
            Редактировать поля
          </button>
        )}
      </div>

      <div className="grid md:grid-cols-2 gap-x-6 gap-y-3">
        {defs.map((def) => (
          <div key={def.code}>
            <div className="text-xs text-gray-500 mb-0.5">
              {def.label_ru}
              {def.is_required && <span className="text-danger ml-0.5">*</span>}
            </div>
            {editing ? (
              <CustomFieldInput
                def={def}
                value={localFields[def.code] ?? null}
                onChange={(v) => setLocalFields((f) => ({ ...f, [def.code]: v }))}
                disabled={saving}
              />
            ) : (
              <CustomFieldDisplay def={def} value={extraFields[def.code] ?? null} />
            )}
          </div>
        ))}
      </div>

      {editing && (
        <div className="mt-4 pt-3 border-t border-gray-100">
          {saveError && (
            <div className="text-sm text-danger mb-2">{saveError}</div>
          )}
          <div className="flex items-center gap-2">
            <button
              type="button"
              onClick={cancelEdit}
              className="btn-ghost"
              disabled={saving}
            >
              Отмена
            </button>
            <button
              type="button"
              onClick={handleSave}
              className="btn-primary"
              disabled={saving}
            >
              {saving ? "Сохраняем…" : "Сохранить поля"}
            </button>
          </div>
        </div>
      )}
    </div>
  );
}
