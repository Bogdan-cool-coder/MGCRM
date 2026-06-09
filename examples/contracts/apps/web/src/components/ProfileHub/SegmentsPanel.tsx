"use client";

import { useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { fetcher, api } from "@/lib/api";
import { PAGE_KEY_LABELS, type SavedFilter } from "@/lib/types";
import { formatDate } from "@/lib/dates";

export function SegmentsPanel() {
  const key = "/saved-filters";
  const { data: filters, isLoading, error } = useSWR<SavedFilter[]>(key, fetcher);

  const [toggleLoading, setToggleLoading] = useState<number | null>(null);

  async function handleTogglePin(filter: SavedFilter) {
    setToggleLoading(filter.id);
    try {
      await api(`/saved-filters/${filter.id}`, {
        method: "PATCH",
        body: { is_pinned: !filter.is_pinned },
      });
      await globalMutate((k) => typeof k === "string" && k.startsWith("/saved-filters"), undefined, { revalidate: true });
    } catch {
      // silent
    } finally {
      setToggleLoading(null);
    }
  }

  async function handleDelete(filter: SavedFilter) {
    if (!window.confirm(`Удалить сегмент «${filter.name}»?`)) return;
    try {
      await api(`/saved-filters/${filter.id}`, { method: "DELETE" });
      await globalMutate((k) => typeof k === "string" && k.startsWith("/saved-filters"), undefined, { revalidate: true });
    } catch {
      alert("Не удалось удалить сегмент");
    }
  }

  return (
    <div className="p-6 space-y-4">
      <div className="rounded-2xl bg-white dark:bg-gray-800 border border-gray-200 dark:border-gray-700 shadow-elev-1 overflow-hidden">
        {error && (
          <div className="m-4 text-sm text-danger bg-danger/10 px-3 py-2 rounded">
            Не удалось загрузить сегменты
          </div>
        )}

        {isLoading && (
          <div className="animate-pulse space-y-px">
            {[1, 2, 3].map((i) => (
              <div key={i} className="flex gap-4 px-4 py-3 border-b border-gray-100">
                <div className="h-4 bg-gray-200 rounded w-1/3" />
                <div className="h-4 bg-gray-200 rounded w-1/6" />
                <div className="h-4 bg-gray-200 rounded w-16 ml-auto" />
              </div>
            ))}
          </div>
        )}

        {!isLoading && !error && (!filters || filters.length === 0) && (
          <div className="py-16 text-center">
            <i className="bi bi-bookmark text-5xl text-gray-300 block mb-3" />
            <div className="text-base font-medium text-gray-700 mb-1">Нет сохранённых сегментов</div>
            <div className="text-sm text-gray-400">
              Сохраняй часто используемые наборы фильтров для быстрого доступа
            </div>
          </div>
        )}

        {!isLoading && filters && filters.length > 0 && (
          <div className="overflow-x-auto">
            <table className="w-full text-sm">
              <thead>
                <tr className="border-b border-gray-200 bg-gray-50 dark:bg-gray-800 dark:border-gray-700">
                  <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Название</th>
                  <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Страница</th>
                  <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Закреплён</th>
                  <th className="text-left px-4 py-2 text-xs text-gray-500 font-medium">Создан</th>
                  <th className="px-4 py-2 w-16" />
                </tr>
              </thead>
              <tbody>
                {filters.map((filter) => (
                  <tr key={filter.id} className="border-b border-gray-100 dark:border-gray-700 hover:bg-gray-50 dark:hover:bg-gray-800 transition-colors">
                    <td className="px-4 py-3 font-medium text-gray-900 dark:text-gray-100">{filter.name}</td>
                    <td className="px-4 py-3 text-gray-600 dark:text-gray-400">
                      {PAGE_KEY_LABELS[filter.page_key]}
                    </td>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => void handleTogglePin(filter)}
                        disabled={toggleLoading === filter.id}
                        className={`text-xs font-medium transition-colors ${
                          filter.is_pinned ? "text-primary" : "text-gray-400 hover:text-gray-600"
                        } ${toggleLoading === filter.id ? "opacity-50" : ""}`}
                      >
                        <i className={`bi ${filter.is_pinned ? "bi-bookmark-fill" : "bi-bookmark"} mr-1`} />
                        {filter.is_pinned ? "Закреплён" : "Не закреплён"}
                      </button>
                    </td>
                    <td className="px-4 py-3 text-gray-500 dark:text-gray-400">{formatDate(filter.created_at)}</td>
                    <td className="px-4 py-3">
                      <button
                        onClick={() => void handleDelete(filter)}
                        className="btn-ghost p-1 text-xs text-danger hover:text-danger"
                        title="Удалить"
                      >
                        <i className="bi bi-trash" />
                      </button>
                    </td>
                  </tr>
                ))}
              </tbody>
            </table>
          </div>
        )}
      </div>
    </div>
  );
}
