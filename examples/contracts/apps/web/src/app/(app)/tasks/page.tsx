"use client";

import { useState, useMemo } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import puzzleIcon from "@/lib/lordicon/puzzle.json";
import { TaskPresetBar, type TaskPreset } from "@/components/Tasks/TaskPresetBar";
import { TaskListToolbar, type SortOption } from "@/components/Tasks/TaskListToolbar";
import { TaskListItem } from "@/components/Tasks/TaskListItem";
import { TaskFiltersPanel, type TaskFilters } from "@/components/Tasks/TaskFiltersPanel";
import { BulkActionsBar } from "@/components/Tasks/BulkActionsBar";
import { TaskCreateDrawer } from "@/components/Tasks/TaskCreateDrawer";
import type { Activity } from "@/lib/types";

function buildQueryString(
  preset: TaskPreset,
  filters: TaskFilters,
  sort: SortOption
): string {
  const p = new URLSearchParams();
  p.set("kind", "task");
  p.set("sort", sort);
  p.set("limit", "50");

  // Preset
  switch (preset) {
    case "pinned": p.set("is_pinned", "true"); p.set("is_closed", "false"); break;
    case "overdue": p.set("overdue", "true"); p.set("is_closed", "false"); break;
    case "done_unclosed": p.set("status", "done"); p.set("is_closed", "false"); break;
    case "today": {
      const d = new Date();
      const y = d.getFullYear(), m = d.getMonth(), day = d.getDate();
      p.set("due_from", new Date(y, m, day).toISOString().split("T")[0]);
      p.set("due_to", new Date(y, m, day).toISOString().split("T")[0]);
      break;
    }
    case "this_week": {
      const now = new Date();
      const monday = new Date(now);
      monday.setDate(now.getDate() - now.getDay() + 1);
      const sunday = new Date(monday);
      sunday.setDate(monday.getDate() + 6);
      p.set("due_from", monday.toISOString().split("T")[0]);
      p.set("due_to", sunday.toISOString().split("T")[0]);
      break;
    }
    case "next_week": {
      const now = new Date();
      const monday = new Date(now);
      monday.setDate(now.getDate() - now.getDay() + 8);
      const sunday = new Date(monday);
      sunday.setDate(monday.getDate() + 6);
      p.set("due_from", monday.toISOString().split("T")[0]);
      p.set("due_to", sunday.toISOString().split("T")[0]);
      break;
    }
    case "future": {
      const now = new Date();
      const monday = new Date(now);
      monday.setDate(now.getDate() - now.getDay() + 8);
      const endNext = new Date(monday);
      endNext.setDate(monday.getDate() + 6);
      const afterNext = new Date(endNext);
      afterNext.setDate(endNext.getDate() + 1);
      p.set("due_from", afterNext.toISOString().split("T")[0]);
      break;
    }
    case "mine": p.set("my_tasks", "true"); break;
    case "my_orders": p.set("my_orders", "true"); break;
    case "all": break;
  }

  // Extra filters
  filters.statuses.forEach((s) => p.append("status[]", s));
  filters.priorities.forEach((pr) => p.append("priority[]", pr));
  filters.categoryIds.forEach((c) => p.append("category_ids[]", c));
  if (filters.responsibleId) p.set("responsible_id", filters.responsibleId);
  if (filters.createdById) p.set("created_by_id", filters.createdById);
  if (filters.departmentId) p.set("department_id", filters.departmentId);
  if (filters.dueFrom) p.set("due_from", filters.dueFrom);
  if (filters.dueTo) p.set("due_to", filters.dueTo);
  if (filters.myTasks) p.set("my_tasks", "true");
  if (filters.myOrders) p.set("my_orders", "true");
  // Задачник по умолчанию показывает только открытые: явно шлём include_closed=false,
  // чтобы backend применил open-only фильтр (без параметра он вернёт open+closed).
  p.set("include_closed", filters.includeClosed ? "true" : "false");

  return "/activities?" + p.toString();
}

function countActiveFilters(filters: TaskFilters): number {
  let n = 0;
  if (filters.statuses.length) n++;
  if (filters.priorities.length) n++;
  if (filters.categoryIds.length) n++;
  if (filters.responsibleId) n++;
  if (filters.createdById) n++;
  if (filters.departmentId) n++;
  if (filters.dueFrom || filters.dueTo) n++;
  if (filters.myTasks) n++;
  if (filters.myOrders) n++;
  if (filters.includeClosed) n++;
  return n;
}

const DEFAULT_FILTERS: TaskFilters = {
  statuses: [],
  priorities: [],
  categoryIds: [],
  responsibleId: "",
  createdById: "",
  departmentId: "",
  dueFrom: "",
  dueTo: "",
  myTasks: false,
  myOrders: false,
  includeClosed: false,
};

export default function TasksPage() {
  const [preset, setPreset] = useState<TaskPreset>("all");
  const [sort, setSort] = useState<SortOption>("due_at_asc");
  const [filters, setFilters] = useState<TaskFilters>(DEFAULT_FILTERS);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [drawerOpen, setDrawerOpen] = useState(false);

  const swrKey = useMemo(
    () => buildQueryString(preset, filters, sort),
    [preset, filters, sort]
  );

  const { data: tasks, error, isLoading, mutate } = useSWR<Activity[]>(swrKey, fetcher);

  const { data: openCount } = useSWR<{ count: number }>("/activities/my-open-count", fetcher, {
    refreshInterval: 60_000,
  });

  function handleSelectAll(checked: boolean) {
    if (!tasks) return;
    setSelectedIds(checked ? tasks.map((t) => t.id) : []);
  }

  function handleSelect(id: number, checked: boolean) {
    setSelectedIds((prev) =>
      checked ? [...prev, id] : prev.filter((x) => x !== id)
    );
  }

  const allSelected = tasks ? tasks.length > 0 && selectedIds.length === tasks.length : false;
  const someSelected = selectedIds.length > 0 && !allSelected;
  const activeFiltersCount = countActiveFilters(filters);

  function handleMutate() {
    void mutate();
    void globalMutate("/activities/counts-by-preset");
    void globalMutate("/activities/my-open-count");
  }

  function handlePresetChange(p: TaskPreset) {
    setPreset(p);
    setSelectedIds([]);
  }

  const totalOpen = openCount?.count ?? 0;

  return (
    <div className="flex flex-col h-full">
      <PageHeader
        title="Задачи"
        description="планирование и контроль"
        actions={
          <button className="btn-primary" onClick={() => setDrawerOpen(true)}>
            <i className="bi bi-plus mr-1" />
            Создать задачу
          </button>
        }
      />

      {/* Горизонтальная панель пресетов */}
      <TaskPresetBar active={preset} onChange={handlePresetChange} />

      {/* Тулбар: select-all, сортировка, кнопка «Фильтры» */}
      <TaskListToolbar
        allSelected={allSelected}
        someSelected={someSelected}
        sort={sort}
        onSelectAll={handleSelectAll}
        onSortChange={setSort}
        onToggleFilters={() => setFiltersOpen(!filtersOpen)}
        activeFiltersCount={activeFiltersCount}
        filtersOpen={filtersOpen}
      />

      {/* Inline-панель фильтров (раскрывается под тулбаром) */}
      <TaskFiltersPanel
        open={filtersOpen}
        filters={filters}
        onChange={setFilters}
        onClose={() => setFiltersOpen(false)}
      />

      {/* Список задач */}
      <div className="flex-1 overflow-y-auto">
        {isLoading && (
          <div className="space-y-1 p-3">
            {Array.from({ length: 6 }).map((_, i) => (
              <div
                key={i}
                className="flex items-start gap-3 px-4 py-3 rounded-lg"
              >
                {/* priority bar */}
                <div className="mt-1 animate-pulse w-1 h-12 rounded bg-gray-100 dark:bg-gray-800 shrink-0" />
                {/* checkbox */}
                <div className="mt-1 animate-pulse w-4 h-4 rounded bg-gray-100 dark:bg-gray-800 shrink-0" />
                {/* star */}
                <div className="mt-1 animate-pulse w-4 h-4 rounded bg-gray-100 dark:bg-gray-800 shrink-0" />
                {/* text block */}
                <div className="flex-1 space-y-2">
                  <div className="animate-pulse h-3.5 rounded bg-gray-100 dark:bg-gray-800 w-3/5" />
                  <div className="animate-pulse h-2.5 rounded bg-gray-100 dark:bg-gray-800 w-2/5" />
                  <div className="animate-pulse h-2.5 rounded bg-gray-100 dark:bg-gray-800 w-1/4" />
                </div>
                {/* badge skeleton */}
                <div className="mt-0.5 animate-pulse h-5 w-16 rounded-full bg-gray-100 dark:bg-gray-800 shrink-0" />
              </div>
            ))}
          </div>
        )}

        {error && (
          <div className="mx-4 mt-3 text-sm text-danger bg-danger/10 dark:bg-danger/5 px-3 py-2.5 rounded-lg flex items-center gap-2">
            <i className="bi bi-exclamation-triangle shrink-0" />
            Не удалось загрузить задачи. Обновите страницу.
          </div>
        )}

        {!isLoading && !error && tasks && tasks.length === 0 && (
          <div className="flex items-center justify-center py-16">
            {activeFiltersCount > 0 || preset !== "all" ? (
              <EmptyState
                icon="bi-funnel"
                title="Нет задач по выбранным фильтрам"
                description="Попробуй изменить критерии"
                cta={
                  <button className="btn-ghost" onClick={() => { setFilters(DEFAULT_FILTERS); setPreset("all"); }}>
                    Сбросить фильтры
                  </button>
                }
              />
            ) : (
              <EmptyState
                icon="bi-clipboard-check"
                title="Пока нет задач"
                description="Создай первую задачу, чтобы начать"
                lordIcon={{ icon: puzzleIcon, trigger: "loop", size: 72 }}
                cta={
                  <button className="btn-primary" onClick={() => setDrawerOpen(true)}>
                    + Создать задачу
                  </button>
                }
              />
            )}
          </div>
        )}

        {!isLoading && !error && tasks && tasks.length > 0 && (
          <div>
            {tasks.map((task) => (
              <TaskListItem
                key={task.id}
                task={task}
                selected={selectedIds.includes(task.id)}
                onSelect={handleSelect}
                onMutate={handleMutate}
              />
            ))}
          </div>
        )}
      </div>

      {/* Bulk actions bar */}
      <BulkActionsBar
        selectedIds={selectedIds}
        onClear={() => setSelectedIds([])}
        onMutate={handleMutate}
      />

      {/* Create drawer */}
      <TaskCreateDrawer
        open={drawerOpen}
        onClose={() => setDrawerOpen(false)}
      />
    </div>
  );
}
