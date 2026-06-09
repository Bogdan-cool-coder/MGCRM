"use client";

import { useEffect, useMemo, useRef, useState } from "react";
import useSWR from "swr";
import clsx from "clsx";
import { fetcher } from "@/lib/api";
import type { City } from "@/lib/types";

export interface CitySelectProps {
  /** Свободный текст города (value = строка). */
  value: string;
  onChange: (city: string) => void;
  /** ISO2-код страны для подсказок. Без него подсказки не грузятся. */
  countryCode: string | null;
  label?: string;
  required?: boolean;
  disabled?: boolean;
  placeholder?: string;
}

function isCityArray(v: unknown): v is City[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "name" in v[0]));
}

export function CitySelect({
  value, onChange, countryCode, label, required, disabled, placeholder = "Город",
}: CitySelectProps) {
  const [open, setOpen] = useState(false);
  const [highlight, setHighlight] = useState(-1);
  const rootRef = useRef<HTMLDivElement>(null);

  // Подсказки грузим только при выбранной стране; q = текущий ввод
  const key = countryCode
    ? `/cities?country_code=${encodeURIComponent(countryCode)}&only_active=true${value.trim() ? `&q=${encodeURIComponent(value.trim())}` : ""}`
    : null;
  const { data: raw, isLoading } = useSWR<unknown>(key, fetcher);
  const cities = isCityArray(raw) ? raw : [];

  const suggestions = useMemo(() => {
    const q = value.trim().toLowerCase();
    // Бэкенд уже фильтрует по q, но подстрахуемся и отбросим точное совпадение
    return cities.filter((c) => c.name.toLowerCase() !== q).slice(0, 20);
  }, [cities, value]);

  useEffect(() => {
    if (!open) return;
    function onDoc(e: MouseEvent) {
      if (rootRef.current && !rootRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", onDoc);
    return () => document.removeEventListener("mousedown", onDoc);
  }, [open]);

  useEffect(() => { setHighlight(-1); }, [value]);

  function choose(c: City) {
    onChange(c.name);
    setOpen(false);
  }

  function onKeyDown(e: React.KeyboardEvent) {
    if (e.key === "ArrowDown") {
      e.preventDefault();
      setOpen(true);
      setHighlight((h) => Math.min(h + 1, suggestions.length - 1));
    } else if (e.key === "ArrowUp") {
      e.preventDefault();
      setHighlight((h) => Math.max(h - 1, 0));
    } else if (e.key === "Enter") {
      if (open && highlight >= 0 && suggestions[highlight]) {
        e.preventDefault();
        choose(suggestions[highlight]);
      }
    } else if (e.key === "Escape") {
      setOpen(false);
    }
  }

  const showDropdown = open && countryCode != null;

  return (
    <div ref={rootRef} className="relative">
      {label && (
        <label className="label">
          {label} {required && <span className="text-danger">*</span>}
        </label>
      )}
      <input
        className={clsx("input", disabled && "opacity-50")}
        value={value}
        disabled={disabled}
        placeholder={countryCode ? placeholder : "Сначала выберите страну"}
        onChange={(e) => { onChange(e.target.value); setOpen(true); }}
        onFocus={() => setOpen(true)}
        onKeyDown={onKeyDown}
        autoComplete="off"
      />

      {showDropdown && (
        <div className="absolute z-50 mt-1 w-full max-h-56 overflow-y-auto rounded-lg border border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 shadow-lg">
          {isLoading && <div className="px-3 py-2 text-sm text-gray-400">Загрузка…</div>}
          {!isLoading && suggestions.length === 0 && (
            <div className="px-3 py-2 text-sm text-gray-400">
              {value.trim() ? "Нет подсказок — можно ввести вручную" : "Начните вводить название"}
            </div>
          )}
          {suggestions.map((c, i) => (
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
              <i className={clsx("bi bi-geo-alt", i === highlight ? "text-white/70" : "text-gray-400")} />
              <span className="flex-1 truncate">{c.name}</span>
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
