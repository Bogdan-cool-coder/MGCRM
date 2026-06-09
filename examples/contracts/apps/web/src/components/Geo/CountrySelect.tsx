"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import useSWR from "swr";
import clsx from "clsx";
import { fetcher } from "@/lib/api";
import { flagEmoji } from "@/lib/flag";
import type { Country } from "@/lib/types";

export interface CountrySelectProps {
  /** Текущее значение — ISO2-код страны (lowercase) или null. */
  value: string | null;
  /** Вызывается с новым кодом страны (lowercase) или null при очистке. */
  onChange: (code: string | null) => void;
  label?: string;
  required?: boolean;
  disabled?: boolean;
  placeholder?: string;
  /** Разрешить очистку выбора (показывать крестик). */
  clearable?: boolean;
}

function isCountryArray(v: unknown): v is Country[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "code" in v[0]));
}

export function CountrySelect({
  value, onChange, label, required, disabled, placeholder = "Выберите страну", clearable,
}: CountrySelectProps) {
  const { data: raw, isLoading } = useSWR<unknown>("/countries?only_active=true", fetcher);
  const countries = isCountryArray(raw) ? raw : [];

  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [highlight, setHighlight] = useState(0);
  const rootRef = useRef<HTMLDivElement>(null);

  const selected = useMemo(
    () => countries.find((c) => c.code === value) ?? null,
    [countries, value],
  );

  const filtered = useMemo(() => {
    const q = query.trim().toLowerCase();
    if (!q) return countries;
    return countries.filter(
      (c) =>
        c.name.toLowerCase().includes(q) ||
        c.code.toLowerCase().includes(q) ||
        (c.name_en?.toLowerCase().includes(q) ?? false),
    );
  }, [countries, query]);

  // Закрытие по клику вне компонента
  useEffect(() => {
    if (!open) return;
    function onDoc(e: MouseEvent) {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
        setQuery("");
      }
    }
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [open]);

  useEffect(() => { setHighlight(0); }, [query, open]);

  function choose(c: Country) {
    onChange(c.code);
    setOpen(false);
    setQuery("");
  }

  function onKeyDown(e: React.KeyboardEvent) {
    if (!open && (e.key === "ArrowDown" || e.key === "Enter")) {
      setOpen(true);
      return;
    }
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setHighlight((h) => Math.min(h + 1, filtered.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setHighlight((h) => Math.max(h - 1, 0));
    } else if (e.key === "Enter") {
      e.preventDefault();
      const c = filtered[highlight];
      if (c) choose(c);
    } else if (e.key === "Escape") {
      setOpen(false);
      setQuery("");
    }
  }

  return (
    <div ref={rootRef} className="relative">
      {label && (
        <label className="label">
          {label} {required && <span className="text-danger">*</span>}
        </label>
      )}
      <div
        className={clsx(
          "input flex items-center gap-2 cursor-pointer",
          disabled && "opacity-50 pointer-events-none",
        )}
        onClick={() => !disabled && setOpen((o) => !o)}
      >
        {open ? (
          <input
            autoFocus
            className="flex-1 bg-transparent outline-none min-w-0"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
            onKeyDown={onKeyDown}
            placeholder={selected ? `${selected.name}` : placeholder}
            onClick={(e) => e.stopPropagation()}
          />
        ) : selected ? (
          <span className="flex-1 flex items-center gap-2 min-w-0 truncate">
            <span>{flagEmoji(selected.code)}</span>
            <span className="truncate">{selected.name}</span>
          </span>
        ) : (
          <span className="flex-1 text-gray-400 dark:text-gray-500">{placeholder}</span>
        )}
        {clearable && selected && !open && (
          <button
            type="button"
            className="text-gray-400 hover:text-danger shrink-0"
            onClick={(e) => { e.stopPropagation(); onChange(null); }}
            aria-label="Очистить"
          >
            <i className="bi bi-x" />
          </button>
        )}
        <i className={`bi ${open ? "bi-chevron-up" : "bi-chevron-down"} text-xs text-gray-400 shrink-0`} />
      </div>

      {open && (
        <div className="absolute z-50 mt-1 w-full max-h-64 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg">
          {isLoading && (
            <div className="px-3 py-2 text-sm text-gray-400">Загрузка…</div>
          )}
          {!isLoading && countries.length === 0 && (
            <div className="px-3 py-2 text-sm text-gray-400">Справочник стран пуст</div>
          )}
          {!isLoading && countries.length > 0 && filtered.length === 0 && (
            <div className="px-3 py-2 text-sm text-gray-400">Ничего не найдено</div>
          )}
          {filtered.map((c, i) => (
            <button
              key={c.id}
              type="button"
              onMouseEnter={() => setHighlight(i)}
              onClick={() => choose(c)}
              className={clsx(
                "flex w-full items-center gap-2 px-3 py-2 text-sm text-left",
                i === highlight ? "bg-primary text-white" : "text-gray-700 dark:text-gray-200 hover:bg-gray-100 dark:hover:bg-gray-700",
              )}
            >
              <span>{flagEmoji(c.code)}</span>
              <span className="flex-1 truncate">{c.name}</span>
              <span className={clsx("text-xs uppercase", i === highlight ? "text-white/70" : "text-gray-400")}>{c.code}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
