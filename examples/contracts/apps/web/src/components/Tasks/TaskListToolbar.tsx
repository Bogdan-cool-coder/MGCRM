"use client";

import { useRef, useEffect } from "react";

export type SortOption =
  | "due_at_asc"
  | "due_at_desc"
  | "priority_desc"
  | "created_at_desc"
  | "created_at_asc"
  | "updated_at_desc";

const SORT_LABELS: Record<SortOption, string> = {
  due_at_asc:      "Срок: ближайший",
  due_at_desc:     "Срок: дальний",
  priority_desc:   "Приоритет: высокий",
  created_at_desc: "Создание: новые",
  created_at_asc:  "Создание: старые",
  updated_at_desc: "Обновление",
};

interface Props {
  allSelected: boolean;
  someSelected: boolean;
  sort: SortOption;
  onSelectAll: (checked: boolean) => void;
  onSortChange: (sort: SortOption) => void;
  onToggleFilters: () => void;
  activeFiltersCount: number;
  filtersOpen?: boolean;
}

export function TaskListToolbar({
  allSelected,
  someSelected,
  sort,
  onSelectAll,
  onSortChange,
  onToggleFilters,
  activeFiltersCount,
  filtersOpen = false,
}: Props) {
  const checkRef = useRef<HTMLInputElement>(null);

  useEffect(() => {
    if (checkRef.current) {
      checkRef.current.indeterminate = someSelected && !allSelected;
    }
  }, [allSelected, someSelected]);

  return (
    <div className="flex items-center gap-3 px-4 py-2 border-b border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800 sticky top-0 z-10">
      <input
        ref={checkRef}
        type="checkbox"
        className="w-4 h-4 cursor-pointer shrink-0"
        checked={allSelected}
        onChange={(e) => onSelectAll(e.target.checked)}
        aria-label="Выбрать все"
      />

      <select
        className="input text-sm py-1 pl-2 pr-6"
        value={sort}
        onChange={(e) => onSortChange(e.target.value as SortOption)}
      >
        {(Object.keys(SORT_LABELS) as SortOption[]).map((k) => (
          <option key={k} value={k}>{SORT_LABELS[k]}</option>
        ))}
      </select>

      <div className="flex-1" />

      <button
        onClick={onToggleFilters}
        className={
          "flex items-center gap-1.5 text-sm px-3 py-1.5 rounded-md border transition-colors " +
          (filtersOpen || activeFiltersCount > 0
            ? "bg-primary/10 border-primary/40 text-primary dark:text-primary"
            : "border-gray-200 dark:border-gray-600 text-gray-600 dark:text-gray-400 hover:border-gray-300 dark:hover:border-gray-500")
        }
      >
        <i className={`bi ${filtersOpen ? "bi-funnel-fill" : "bi-funnel"}`} />
        <span>Фильтры</span>
        {activeFiltersCount > 0 && (
          <span className="bg-primary text-white text-[10px] font-bold rounded-full px-1.5 min-w-[18px] text-center">
            {activeFiltersCount}
          </span>
        )}
      </button>
    </div>
  );
}
