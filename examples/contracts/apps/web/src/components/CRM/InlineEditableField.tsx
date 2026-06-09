"use client";

import { useState, useEffect, useRef } from "react";
import { UserSelect } from "@/components/UserSelect";
import { PositionSearch } from "./PositionSearch";
import { SourceSelect } from "./SourceSelect";

export type InlineFieldKind = "text" | "textarea" | "select" | "user" | "position" | "source";

interface SelectOption { value: string; label: string; }

interface Props {
  value: string | null | undefined;
  label: string;
  /**
   * Legacy-режим: внешнее управление редактированием через global editMode.
   * Когда задан doubleClickEdit — этот проп игнорируется (поле само рулит состоянием).
   */
  editing?: boolean;
  /**
   * Wave 5: поле само управляет своим editing-состоянием по двойному клику.
   * В read-режиме показывает hover-affordance (карандаш + пунктир),
   * по dblclick переходит в edit, сохраняет на blur/Enter, отменяет на Esc.
   */
  doubleClickEdit?: boolean;
  kind?: InlineFieldKind;
  options?: SelectOption[];
  placeholder?: string;
  onSave: (v: string) => Promise<void>;
}

/**
 * Универсальное inline-редактируемое поле.
 *
 * Два режима управления editing-состоянием:
 * - legacy (`editing` проп): родитель через global editMode переключает все поля разом.
 * - `doubleClickEdit`: поле самостоятельно входит в редактирование по двойному клику.
 *
 * Сохранение по blur/Enter → onSave → при ошибке откат + текст под полем. Esc — отмена.
 */
export function InlineEditableField({
  value,
  label,
  editing = false,
  doubleClickEdit = false,
  kind = "text",
  options,
  placeholder,
  onSave,
}: Props) {
  const [localVal, setLocalVal] = useState(value ?? "");
  const [saving, setSaving] = useState(false);
  const [saveErr, setSaveErr] = useState<string | null>(null);
  // Внутреннее editing — используется только в doubleClickEdit-режиме.
  const [selfEditing, setSelfEditing] = useState(false);
  const prevRef = useRef(value ?? "");
  // Синхронный in-flight флаг (ref, чтобы не зависеть от async setState).
  const savingRef = useRef(false);
  const inputRef = useRef<HTMLInputElement | HTMLTextAreaElement | HTMLSelectElement>(null);

  // Итоговый признак: редактируем сейчас или нет.
  const isEditing = doubleClickEdit ? selfEditing : editing;

  // Синхронизируем локальное значение при смене внешнего (после mutate).
  // ВАЖНО: пока поле в режиме редактирования (или идёт сохранение), фоновая
  // SWR-ревалидация НЕ должна затирать введённый текст и базовое prevRef —
  // иначе ввод молча теряется (commit сравнивает newVal с prevRef.current).
  useEffect(() => {
    if (isEditing || saving) return;
    setLocalVal(value ?? "");
    prevRef.current = value ?? "";
  }, [value, isEditing, saving]);

  // Автофокус при входе в self-editing
  useEffect(() => {
    if (selfEditing && inputRef.current) {
      inputRef.current.focus();
    }
  }, [selfEditing]);

  async function commit(newVal: string) {
    // In-flight guard: select onChange→commit + onBlur, либо Enter(blur)+повторный
    // blur могут вызвать commit дважды подряд — не шлём дубль PATCH.
    if (savingRef.current) return;
    if (newVal === prevRef.current) {
      if (doubleClickEdit) setSelfEditing(false);
      return;
    }
    savingRef.current = true;
    setSaving(true);
    setSaveErr(null);
    try {
      await onSave(newVal);
      prevRef.current = newVal;
      if (doubleClickEdit) setSelfEditing(false);
    } catch (err) {
      setSaveErr(err instanceof Error ? err.message : "Не удалось сохранить");
      setLocalVal(prevRef.current);
    } finally {
      savingRef.current = false;
      setSaving(false);
    }
  }

  function handleKeyDown(e: React.KeyboardEvent<HTMLInputElement | HTMLTextAreaElement>) {
    if (e.key === "Enter" && kind !== "textarea") {
      e.currentTarget.blur();
    }
    if (e.key === "Escape") {
      setLocalVal(prevRef.current);
      setSaveErr(null);
      if (doubleClickEdit) setSelfEditing(false);
    }
  }

  // Текст для read-режима: для select показываем label опции, а не raw value.
  const displayText =
    kind === "select" && options
      ? (options.find((o) => o.value === (value ?? ""))?.label ?? (value || "—"))
      : (value || "—");

  // ── display mode ──────────────────────────────────────────────────────────
  if (!isEditing) {
    // doubleClickEdit: интерактивный read с hover-affordance
    if (doubleClickEdit) {
      return (
        <div
          className="group flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2 cursor-text"
          onDoubleClick={() => setSelfEditing(true)}
          title="Двойной клик — редактировать"
        >
          <span className="text-gray-500 dark:text-gray-400 text-sm shrink-0">{label}</span>
          <span className="flex items-center gap-1.5 min-w-0">
            <span className="text-sm text-right text-gray-900 dark:text-gray-100 break-words group-hover:border-b group-hover:border-dotted group-hover:border-gray-400 dark:group-hover:border-gray-500">
              {displayText}
            </span>
            <i className="bi bi-pencil text-xs text-gray-300 dark:text-gray-600 opacity-0 group-hover:opacity-100 shrink-0" />
          </span>
        </div>
      );
    }
    // legacy read
    return (
      <div className="flex justify-between gap-4 border-b border-gray-100 dark:border-gray-700 py-2">
        <span className="text-gray-500 dark:text-gray-400 text-sm shrink-0">{label}</span>
        <span className="text-sm text-right text-gray-900 dark:text-gray-100 break-words">
          {displayText}
        </span>
      </div>
    );
  }

  // ── editing mode ──────────────────────────────────────────────────────────
  return (
    <div className="border-b border-gray-100 dark:border-gray-700 py-2">
      <label className="text-xs text-gray-500 dark:text-gray-400 mb-1 block">{label}</label>

      {kind === "text" && (
        <input
          ref={inputRef as React.RefObject<HTMLInputElement>}
          className="input text-sm"
          value={localVal}
          placeholder={placeholder}
          disabled={saving}
          onChange={(e) => setLocalVal(e.target.value)}
          onBlur={(e) => commit(e.target.value)}
          onKeyDown={handleKeyDown}
        />
      )}

      {kind === "textarea" && (
        <textarea
          ref={inputRef as React.RefObject<HTMLTextAreaElement>}
          className="input text-sm min-h-[80px]"
          value={localVal}
          placeholder={placeholder}
          disabled={saving}
          onChange={(e) => setLocalVal(e.target.value)}
          onBlur={(e) => commit(e.target.value)}
          onKeyDown={handleKeyDown}
        />
      )}

      {kind === "select" && options && (
        <select
          ref={inputRef as React.RefObject<HTMLSelectElement>}
          className="input text-sm"
          value={localVal}
          disabled={saving}
          onChange={(e) => {
            setLocalVal(e.target.value);
            void commit(e.target.value);
          }}
          onBlur={() => { if (doubleClickEdit) setSelfEditing(false); }}
        >
          <option value="">—</option>
          {options.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
      )}

      {kind === "user" && (
        <UserSelect
          className="input text-sm"
          value={localVal}
          onChange={(v) => {
            setLocalVal(v);
            void commit(v);
          }}
        />
      )}

      {kind === "position" && (
        <PositionSearch
          className="input text-sm"
          value={localVal}
          onChange={(v) => {
            setLocalVal(v);
            void commit(v);
          }}
          placeholder={placeholder ?? "Должность"}
        />
      )}

      {kind === "source" && (
        <SourceSelect
          className="input text-sm"
          value={localVal}
          onChange={(v) => {
            setLocalVal(v);
            void commit(v);
          }}
        />
      )}

      {saveErr && (
        <p className="text-xs text-danger mt-1">{saveErr}</p>
      )}
    </div>
  );
}
