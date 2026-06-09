"use client";

import { useEffect, useRef, useState } from "react";
import { UserSelect } from "@/components/UserSelect";

// Тип редактора поля. text/number/date — нативные input'ы; select — выпадашка;
// user — UserSelect (переназначение ответственного); tags — список строк.
export type InlineFieldKind = "text" | "number" | "date" | "select" | "user" | "tags";

export interface SelectOption {
  value: string;
  label: string;
}

interface InlineFieldProps {
  label: string;
  /** Текущее значение в строковом виде (для tags — joined через запятую не нужен). */
  value: string;
  /** Готовое отображение значения (бейджи, форматированная сумма и т.д.). */
  display?: React.ReactNode;
  kind: InlineFieldKind;
  options?: SelectOption[];
  /** Для tags-режима — массив тегов. */
  tags?: string[];
  placeholder?: string;
  /** Поле обязательно для ожидающего перехода, но пусто → красная рамка + подсказка. */
  missing?: boolean;
  /** Нередактируемое поле (например, «Сумма» при наличии позиций-продуктов). */
  readOnly?: boolean;
  readOnlyHint?: string;
  /** Суффикс к значению (валюта рядом с суммой и т.п.). */
  suffix?: string;
  /**
   * Сохранение нового значения. Должен зарезолвиться при успехе и кинуть при
   * ошибке (показываем inline). Для tags newValue — JSON массива строк.
   */
  onSave: (newValue: string) => Promise<void>;
}

/**
 * Wave 4: инлайн-редактируемое поле карточки сделки. Клик по значению → редактор.
 * Состояния: read / editing / saving / error / missing. PATCH одного поля.
 */
export function InlineField({
  label,
  value,
  display,
  kind,
  options = [],
  tags = [],
  placeholder,
  missing = false,
  readOnly = false,
  readOnlyHint,
  suffix,
  onSave,
}: InlineFieldProps) {
  const [editing, setEditing] = useState(false);
  const [draft, setDraft] = useState(value);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);

  // tags-локальное состояние
  const [tagDraft, setTagDraft] = useState<string[]>(tags);
  const [tagInput, setTagInput] = useState("");

  const inputRef = useRef<HTMLInputElement | null>(null);
  const selectRef = useRef<HTMLSelectElement | null>(null);

  useEffect(() => {
    if (editing) {
      setDraft(value);
      setTagDraft(tags);
      setError(null);
      // autofocus
      const t = setTimeout(() => {
        inputRef.current?.focus();
        selectRef.current?.focus();
      }, 0);
      return () => clearTimeout(t);
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [editing]);

  async function commit(raw: string) {
    if (saving) return;
    setSaving(true);
    setError(null);
    try {
      await onSave(raw);
      setEditing(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Не удалось сохранить");
    } finally {
      setSaving(false);
    }
  }

  async function commitTags(next: string[]) {
    if (saving) return;
    setSaving(true);
    setError(null);
    try {
      await onSave(JSON.stringify(next));
      setEditing(false);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Не удалось сохранить");
    } finally {
      setSaving(false);
    }
  }

  const labelCls = "w-44 shrink-0 text-xs text-gray-500 dark:text-gray-400 pt-1.5";

  // ── Read-state ──────────────────────────────────────────────────────────
  if (!editing) {
    return (
      <div className="flex gap-2 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
        <span className={labelCls}>
          {label}
          {missing && <span className="text-danger ml-0.5">*</span>}
        </span>
        <div className="flex-1 min-w-0">
          <button
            type="button"
            disabled={readOnly}
            onClick={() => !readOnly && setEditing(true)}
            title={readOnly ? readOnlyHint : "Нажмите, чтобы изменить"}
            className={
              "group/inline w-full text-left text-sm rounded px-1.5 py-1 -mx-1.5 transition-colors " +
              (readOnly
                ? "cursor-default text-gray-700 dark:text-gray-300"
                : "cursor-text hover:bg-gray-100 dark:hover:bg-gray-700/60 text-gray-900 dark:text-gray-100 ") +
              (missing ? " ring-1 ring-danger/60 bg-danger/5" : "")
            }
          >
            <span className="inline-flex items-center gap-1.5">
              {display ?? (value ? value : <span className="text-gray-400 italic">{placeholder ?? "—"}</span>)}
              {suffix && value && <span className="text-gray-400">{suffix}</span>}
              {!readOnly && (
                <i className="bi bi-pencil text-xs text-gray-300 dark:text-gray-600 opacity-0 group-hover/inline:opacity-100 transition-opacity" />
              )}
            </span>
          </button>
          {missing && (
            <p className="text-xs text-danger mt-0.5 px-1.5">Заполните для перехода в этап</p>
          )}
        </div>
      </div>
    );
  }

  // ── Editing-state ───────────────────────────────────────────────────────
  return (
    <div className="flex gap-2 py-1.5 border-b border-gray-100 dark:border-gray-800 last:border-0">
      <span className={labelCls}>{label}</span>
      <div className="flex-1 min-w-0">
        {kind === "tags" ? (
          <div className="space-y-2">
            <div className="flex flex-wrap gap-1">
              {tagDraft.map((t) => (
                <span
                  key={t}
                  className="inline-flex items-center gap-1 px-1.5 py-0.5 text-xs rounded bg-gray-100 dark:bg-gray-700 text-gray-600 dark:text-gray-300"
                >
                  {t}
                  <button
                    type="button"
                    className="hover:text-danger"
                    onClick={() => setTagDraft((prev) => prev.filter((x) => x !== t))}
                  >
                    <i className="bi bi-x" />
                  </button>
                </span>
              ))}
            </div>
            <input
              ref={inputRef}
              className="input text-sm py-1"
              placeholder="Добавить тег и Enter"
              value={tagInput}
              disabled={saving}
              onChange={(e) => setTagInput(e.target.value)}
              onKeyDown={(e) => {
                if (e.key === "Enter") {
                  e.preventDefault();
                  const v = tagInput.trim();
                  if (v && !tagDraft.includes(v)) setTagDraft((prev) => [...prev, v]);
                  setTagInput("");
                }
                if (e.key === "Escape") setEditing(false);
              }}
            />
            <div className="flex gap-1.5">
              <button
                type="button"
                className="btn-primary text-xs py-1 px-2 disabled:opacity-50"
                disabled={saving}
                onClick={() => void commitTags(tagDraft)}
              >
                {saving ? "Сохранение…" : "Сохранить"}
              </button>
              <button type="button" className="btn-ghost text-xs py-1 px-2" onClick={() => setEditing(false)}>
                Отмена
              </button>
            </div>
            {error && <p className="text-xs text-danger">{error}</p>}
          </div>
        ) : kind === "user" ? (
          <div className="space-y-1">
            <UserSelect
              value={draft}
              onChange={(v) => { setDraft(v); void commit(v); }}
              placeholder="—"
              className="input text-sm py-1"
            />
            {saving && <p className="text-xs text-gray-400">Сохранение…</p>}
            {error && <p className="text-xs text-danger">{error}</p>}
          </div>
        ) : kind === "select" ? (
          <div className="space-y-1">
            <select
              ref={selectRef}
              className="input text-sm py-1"
              value={draft}
              disabled={saving}
              onChange={(e) => { setDraft(e.target.value); void commit(e.target.value); }}
            >
              {options.map((o) => (
                <option key={o.value} value={o.value}>{o.label}</option>
              ))}
            </select>
            {saving && <p className="text-xs text-gray-400">Сохранение…</p>}
            {error && <p className="text-xs text-danger">{error}</p>}
          </div>
        ) : (
          <div className="space-y-1">
            <input
              ref={inputRef}
              type={kind === "number" ? "number" : kind === "date" ? "date" : "text"}
              className="input text-sm py-1"
              value={draft}
              placeholder={placeholder}
              disabled={saving}
              onChange={(e) => setDraft(e.target.value)}
              onBlur={() => void commit(draft)}
              onKeyDown={(e) => {
                if (e.key === "Enter") { e.preventDefault(); void commit(draft); }
                if (e.key === "Escape") setEditing(false);
              }}
            />
            {saving && <p className="text-xs text-gray-400">Сохранение…</p>}
            {error && <p className="text-xs text-danger">{error}</p>}
          </div>
        )}
      </div>
    </div>
  );
}
