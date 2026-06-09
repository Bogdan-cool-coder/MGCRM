"use client";

import { useEffect, useMemo, useRef, useState } from "react";

interface Props {
  title: string;
  data: Record<string, number>;
  /** Маппинг сырого ключа в человеко-читаемую подпись. */
  labelMap?: (k: string) => string;
}

const TOP_N = 10;

/**
 * Виджет-разбивка (Wave 2b): топ-10 по убыванию + инлайн «Показать все»
 * + клиентский мультиселект «Выбрать» (поповер) для выбора отображаемых ключей.
 * Данные уже полностью на клиенте — никаких доп. запросов.
 */
export function BreakdownWidget({ title, data, labelMap }: Props) {
  const [expanded, setExpanded] = useState(false);
  const [pickerOpen, setPickerOpen] = useState(false);
  // null = фильтр не применён (показываем по дефолтному правилу top-10/all)
  const [selectedKeys, setSelectedKeys] = useState<Set<string> | null>(null);
  const pickerRef = useRef<HTMLDivElement>(null);

  const allEntries = useMemo(
    () => Object.entries(data).sort((a, b) => b[1] - a[1]),
    [data],
  );
  const max = useMemo(() => Math.max(1, ...allEntries.map(([, v]) => v)), [allEntries]);

  useEffect(() => {
    function handler(e: MouseEvent) {
      if (pickerRef.current && !pickerRef.current.contains(e.target as Node)) setPickerOpen(false);
    }
    if (pickerOpen) document.addEventListener("mousedown", handler);
    return () => document.removeEventListener("mousedown", handler);
  }, [pickerOpen]);

  const lbl = (k: string) => (labelMap ? labelMap(k) : k);

  // Какие строки показываем
  const visibleEntries = useMemo(() => {
    if (selectedKeys) {
      return allEntries.filter(([k]) => selectedKeys.has(k));
    }
    return expanded ? allEntries : allEntries.slice(0, TOP_N);
  }, [allEntries, expanded, selectedKeys]);

  const hasMore = !selectedKeys && allEntries.length > TOP_N;

  function toggleKey(k: string) {
    setSelectedKeys((prev) => {
      const next = new Set(prev ?? []);
      if (next.has(k)) next.delete(k);
      else next.add(k);
      return next;
    });
  }

  function clearSelection() {
    setSelectedKeys(null);
    setPickerOpen(false);
  }

  return (
    <div className="card p-5">
      <div className="flex items-center justify-between mb-3 gap-2">
        <h3 className="text-h5">{title}</h3>
        {allEntries.length > 0 && (
          <div className="relative" ref={pickerRef}>
            <button
              type="button"
              onClick={() => setPickerOpen((o) => !o)}
              className="btn-ghost text-xs flex items-center gap-1"
            >
              <i className="bi bi-funnel" />
              Выбрать
              {selectedKeys && selectedKeys.size > 0 && (
                <span className="bg-primary text-white text-[10px] font-bold rounded-full px-1.5 min-w-[16px] text-center">
                  {selectedKeys.size}
                </span>
              )}
            </button>
            {pickerOpen && (
              <div className="absolute right-0 z-30 mt-1 w-56 bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 rounded-md shadow-lg p-2 max-h-72 overflow-y-auto">
                <div className="flex items-center justify-between mb-1 px-1">
                  <span className="text-xs font-medium text-gray-500 dark:text-gray-400">Показать ключи</span>
                  {selectedKeys && (
                    <button type="button" onClick={clearSelection} className="text-[11px] text-primary hover:underline">
                      Сбросить
                    </button>
                  )}
                </div>
                {allEntries.map(([k, v]) => {
                  const checked = selectedKeys ? selectedKeys.has(k) : false;
                  return (
                    <label
                      key={k}
                      className="flex items-center gap-2 px-1 py-1 rounded hover:bg-gray-50 dark:hover:bg-gray-700 cursor-pointer text-sm"
                    >
                      <input
                        type="checkbox"
                        className="h-3.5 w-3.5"
                        checked={checked}
                        onChange={() => toggleKey(k)}
                      />
                      <span className="flex-1 truncate" title={lbl(k)}>{lbl(k)}</span>
                      <span className="text-xs text-gray-400 tabular-nums">{v}</span>
                    </label>
                  );
                })}
              </div>
            )}
          </div>
        )}
      </div>

      {allEntries.length === 0 && <div className="text-sm text-gray-400">Нет данных.</div>}

      <div className="space-y-1.5">
        {visibleEntries.map(([k, v]) => (
          <div key={k} className="flex items-center gap-2 text-sm">
            <span className="w-40 truncate" title={lbl(k)}>{lbl(k)}</span>
            <div className="flex-1 h-2 rounded-full bg-gray-100 dark:bg-gray-700 overflow-hidden">
              <div className="h-full bg-primary-light" style={{ width: `${(v / max) * 100}%` }} />
            </div>
            <span className="w-8 text-right tabular-nums text-gray-600 dark:text-gray-400">{v}</span>
          </div>
        ))}
      </div>

      {selectedKeys && visibleEntries.length === 0 && (
        <div className="text-sm text-gray-400 mt-1">Ничего не выбрано.</div>
      )}

      {hasMore && (
        <button
          type="button"
          onClick={() => setExpanded((e) => !e)}
          className="mt-3 text-xs text-primary hover:underline"
        >
          {expanded ? "Свернуть" : `Показать все (${allEntries.length})`}
        </button>
      )}
    </div>
  );
}
