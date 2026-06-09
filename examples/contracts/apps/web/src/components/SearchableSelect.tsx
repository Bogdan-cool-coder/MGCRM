"use client";

import { useEffect, useRef, useState } from "react";
import clsx from "clsx";

export interface Option {
  value: string;
  label: string;
  hint?: string;
}

export function SearchableSelect({
  label, value, onChange, options, required, placeholder = "Поиск…", hint,
}: {
  label: string;
  value: string;
  onChange: (v: string) => void;
  options: Option[];
  required?: boolean;
  placeholder?: string;
  hint?: React.ReactNode;
}) {
  const [open, setOpen] = useState(false);
  const [query, setQuery] = useState("");
  const [touched, setTouched] = useState(false);
  const containerRef = useRef<HTMLDivElement>(null);

  const selected = options.find((o) => o.value === value);

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (containerRef.current && !containerRef.current.contains(e.target as Node)) {
        setOpen(false);
        setTouched(true);
      }
    }
    if (open) document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [open]);

  const filtered = query
    ? options.filter((o) =>
        o.label.toLowerCase().includes(query.toLowerCase()) ||
        (o.hint?.toLowerCase().includes(query.toLowerCase()) ?? false),
      )
    : options;

  const invalid = required && touched && !value;

  return (
    <div ref={containerRef} className="relative">
      <label className="label">
        {label} {required && <span className="text-danger">*</span>}
      </label>
      <button
        type="button"
        onClick={() => setOpen((o) => !o)}
        className={clsx(
          "input flex items-center justify-between text-left",
          invalid && "border-danger",
        )}
      >
        <span className={clsx(!selected && "text-gray-400")}>
          {selected ? selected.label : placeholder}
        </span>
        <i className="bi bi-chevron-down text-gray-500" />
      </button>
      {open && (
        <div className="absolute z-30 mt-1 w-full bg-white border border-gray-200 rounded-md shadow-lg max-h-72 overflow-hidden flex flex-col">
          <input
            autoFocus
            className="w-full px-3 py-2 border-b border-gray-200 focus:outline-none text-sm"
            placeholder="Начните вводить название…"
            value={query}
            onChange={(e) => setQuery(e.target.value)}
          />
          <div className="overflow-y-auto">
            {filtered.length === 0 && (
              <div className="px-3 py-4 text-sm text-gray-500 text-center">Ничего не найдено</div>
            )}
            {filtered.map((o) => (
              <button
                key={o.value}
                type="button"
                onClick={() => { onChange(o.value); setOpen(false); setQuery(""); setTouched(true); }}
                className={clsx(
                  "w-full text-left px-3 py-2 text-sm hover:bg-gray-100 flex items-center justify-between",
                  o.value === value && "bg-primary/10 text-primary",
                )}
              >
                <span>{o.label}</span>
                {o.hint && <span className="text-xs text-gray-500 ml-2">{o.hint}</span>}
              </button>
            ))}
          </div>
        </div>
      )}
      {invalid && <div className="text-xs text-danger mt-1">Обязательное поле</div>}
      {hint && !invalid && <div className="text-xs text-gray-500 mt-1">{hint}</div>}
    </div>
  );
}
