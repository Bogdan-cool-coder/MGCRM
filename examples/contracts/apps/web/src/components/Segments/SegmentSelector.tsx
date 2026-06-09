"use client";

import { useState, useRef, useEffect } from "react";
import { useSavedFilters } from "@/hooks/useSavedFilters";
import type { PageKey } from "@/lib/types";

interface SegmentSelectorProps {
  pageKey: PageKey;
  activeSegmentId?: number | null;
  onApply: (filterJson: Record<string, unknown>, filterId: number, filterName: string) => void;
  onClear?: () => void;
}

export function SegmentSelector({ pageKey, activeSegmentId, onApply, onClear }: SegmentSelectorProps) {
  const [open, setOpen] = useState(false);
  const ref = useRef<HTMLDivElement>(null);
  const { filters, isLoading } = useSavedFilters(pageKey);

  // Close on click outside
  useEffect(() => {
    function handler(e: MouseEvent) {
      if (ref.current && !ref.current.contains(e.target as Node)) {
        setOpen(false);
      }
    }
    document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, []);

  const activeFilter = activeSegmentId
    ? filters.find((f) => f.id === activeSegmentId)
    : null;

  if (activeFilter) {
    return (
      <div className="flex items-center gap-1">
        <span className="text-sm bg-primary/10 text-primary border border-primary/20 rounded-md px-3 py-1.5 flex items-center gap-1.5">
          <i className="bi bi-bookmark-fill text-xs" />
          {activeFilter.name}
        </span>
        {onClear && (
          <button
            onClick={onClear}
            className="btn-ghost text-sm py-1.5 px-2"
            title="Сбросить сегмент"
          >
            <i className="bi bi-x" />
            Сбросить
          </button>
        )}
      </div>
    );
  }

  return (
    <div className="relative" ref={ref}>
      <button
        onClick={() => setOpen((o) => !o)}
        className="btn-ghost text-sm flex items-center gap-1.5"
      >
        <i className="bi bi-bookmark" />
        Применить сегмент
        <i className="bi bi-chevron-down text-xs" />
      </button>

      {open && (
        <div className="absolute top-full left-0 mt-1 z-30 bg-white border border-gray-200 rounded-lg shadow-lg min-w-[200px] py-1">
          {isLoading && (
            <div className="px-3 py-2 text-sm text-gray-500">Загружаем…</div>
          )}
          {!isLoading && filters.length === 0 && (
            <div className="px-3 py-2 text-sm text-gray-500">Нет сохранённых сегментов</div>
          )}
          {!isLoading && filters.map((f) => (
            <button
              key={f.id}
              onClick={() => {
                onApply(f.filter_json, f.id, f.name);
                setOpen(false);
              }}
              className="w-full text-left px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 flex items-center gap-2"
            >
              <i className="bi bi-bookmark text-gray-400 text-xs" />
              {f.name}
            </button>
          ))}
        </div>
      )}
    </div>
  );
}
