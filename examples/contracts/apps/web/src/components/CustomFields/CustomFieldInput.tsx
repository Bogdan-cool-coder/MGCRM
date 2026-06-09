"use client";

import { DatePicker } from "@/components/ui/DatePicker";
import type { CustomFieldDef } from "@/lib/types";

interface CustomFieldInputProps {
  def: CustomFieldDef;
  value: unknown;
  onChange: (v: unknown) => void;
  disabled?: boolean;
  error?: string;
}

function toStr(v: unknown): string {
  if (v === null || v === undefined) return "";
  return String(v);
}

export function CustomFieldInput({ def, value, onChange, disabled, error }: CustomFieldInputProps) {
  const cls = `input w-full ${error ? "border-danger" : ""} ${disabled ? "opacity-60" : ""}`;

  if (def.kind === "checkbox") {
    return (
      <div className="flex items-center gap-2">
        <input
          type="checkbox"
          id={`cf-${def.code}`}
          checked={Boolean(value)}
          onChange={(e) => onChange(e.target.checked)}
          disabled={disabled}
          className="w-4 h-4 accent-primary"
        />
        <label htmlFor={`cf-${def.code}`} className="text-sm text-gray-700">
          {def.label_ru}
        </label>
      </div>
    );
  }

  if (def.kind === "textarea") {
    return (
      <textarea
        className={cls}
        rows={3}
        value={toStr(value)}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
        placeholder={def.default_value ?? ""}
      />
    );
  }

  if (def.kind === "select") {
    return (
      <select
        className={cls}
        value={toStr(value)}
        onChange={(e) => onChange(e.target.value)}
        disabled={disabled}
      >
        <option value="">— Выбрать —</option>
        {def.options_json.map((opt) => (
          <option key={opt} value={opt}>{opt}</option>
        ))}
      </select>
    );
  }

  if (def.kind === "multiselect") {
    const selected = Array.isArray(value) ? (value as string[]) : [];
    return (
      <div className="space-y-1">
        {def.options_json.map((opt) => (
          <label key={opt} className="flex items-center gap-2 text-sm">
            <input
              type="checkbox"
              checked={selected.includes(opt)}
              onChange={(e) => {
                if (e.target.checked) {
                  onChange([...selected, opt]);
                } else {
                  onChange(selected.filter((s) => s !== opt));
                }
              }}
              disabled={disabled}
              className="w-4 h-4 accent-primary"
            />
            {opt}
          </label>
        ))}
      </div>
    );
  }

  if (def.kind === "number") {
    return (
      <input
        type="number"
        className={cls}
        value={toStr(value)}
        onChange={(e) => onChange(e.target.value === "" ? null : Number(e.target.value))}
        disabled={disabled}
        placeholder={def.default_value ?? ""}
      />
    );
  }

  if (def.kind === "date") {
    return (
      <DatePicker
        value={toStr(value) || null}
        onChange={(v) => onChange(v)}
        disabled={disabled}
        className={error ? "border-danger" : ""}
      />
    );
  }

  if (def.kind === "url") {
    return (
      <input
        type="url"
        className={cls}
        value={toStr(value)}
        onChange={(e) => onChange(e.target.value || null)}
        disabled={disabled}
        placeholder="https://..."
      />
    );
  }

  // default: text
  return (
    <input
      type="text"
      className={cls}
      value={toStr(value)}
      onChange={(e) => onChange(e.target.value || null)}
      disabled={disabled}
      placeholder={def.default_value ?? ""}
    />
  );
}

/** Read-only display of a custom field value */
export function CustomFieldDisplay({ def, value }: { def: CustomFieldDef; value: unknown }) {
  if (def.kind === "checkbox") {
    return (
      <span className={`text-sm font-medium ${value ? "text-success" : "text-gray-400"}`}>
        {value ? "Да" : "Нет"}
      </span>
    );
  }
  if (def.kind === "multiselect") {
    const arr = Array.isArray(value) ? (value as string[]) : [];
    if (arr.length === 0) return <span className="text-gray-400 text-sm">—</span>;
    return (
      <div className="flex flex-wrap gap-1">
        {arr.map((v) => (
          <span key={v} className="text-xs bg-gray-100 text-gray-700 px-1.5 py-0.5 rounded">{v}</span>
        ))}
      </div>
    );
  }
  if (def.kind === "url" && value) {
    return (
      <a
        href={String(value)}
        target="_blank"
        rel="noopener noreferrer"
        className="text-primary hover:underline text-sm truncate max-w-[200px] inline-block"
      >
        {String(value)}
      </a>
    );
  }
  if (def.kind === "date" && value) {
    const d = new Date(String(value));
    if (!isNaN(d.getTime())) return <span className="text-sm">{d.toLocaleDateString("ru-RU")}</span>;
  }
  const str = value !== null && value !== undefined ? String(value) : "";
  if (!str) return <span className="text-gray-400 text-sm">—</span>;
  return <span className="text-sm">{str}</span>;
}
