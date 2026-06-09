"use client";

import { useState, useRef, useEffect, useCallback } from "react";
import { api } from "@/lib/api";
import type { ContactPosition } from "@/lib/types";

interface Props {
  value: string;
  onChange: (value: string) => void;
  className?: string;
  placeholder?: string;
}

function isPositionArray(v: unknown): v is ContactPosition[] {
  return Array.isArray(v) && (v.length === 0 || (typeof v[0] === "object" && v[0] !== null && "name" in v[0]));
}

/**
 * Debounced поиск должности по /api/admin/contact-positions?q=.
 * Разрешает свободный ввод (не обязательно выбирать из списка).
 */
export function PositionSearch({ value, onChange, className = "input", placeholder = "Должность" }: Props) {
  const [suggestions, setSuggestions] = useState<ContactPosition[]>([]);
  const [open, setOpen] = useState(false);
  const timerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  const fetchSuggestions = useCallback(async (q: string) => {
    if (q.trim().length < 1) { setSuggestions([]); return; }
    try {
      const result = await api<unknown>(`/admin/contact-positions?q=${encodeURIComponent(q)}&limit=10`);
      if (isPositionArray(result)) setSuggestions(result);
    } catch {
      // silent
    }
  }, []);

  function handleChange(e: React.ChangeEvent<HTMLInputElement>) {
    const v = e.target.value;
    onChange(v);
    if (timerRef.current) clearTimeout(timerRef.current);
    timerRef.current = setTimeout(() => fetchSuggestions(v), 300);
    setOpen(true);
  }

  function handleSelect(name: string) {
    onChange(name);
    setSuggestions([]);
    setOpen(false);
  }

  // Закрыть при клике снаружи
  useEffect(() => {
    function onClickOutside(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", onClickOutside);
    return () => document.removeEventListener("mousedown", onClickOutside);
  }, []);

  return (
    <div ref={containerRef} className="relative">
      <input
        className={className}
        value={value}
        onChange={handleChange}
        onFocus={() => { if (suggestions.length > 0) setOpen(true); }}
        placeholder={placeholder}
        autoComplete="off"
      />
      {open && suggestions.length > 0 && (
        <div className="absolute z-20 top-full mt-1 w-full bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg max-h-48 overflow-y-auto">
          {suggestions.map((s) => (
            <button
              key={s.id}
              type="button"
              onClick={() => handleSelect(s.name)}
              className="w-full text-left px-3 py-2 text-sm hover:bg-gray-50 dark:hover:bg-gray-700 text-gray-900 dark:text-gray-100"
            >
              {s.name}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
