"use client";

import { useState, useMemo } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import { fetcher } from "@/lib/api";
import { EmptyState } from "@/components/EmptyState";
import { TaskPresetSidebar, type TaskPreset } from "@/components/Tasks/TaskPresetSidebar";
import { TaskListToolbar, type SortOption } from "@/components/Tasks/TaskListToolbar";
import { TaskListItem } from "@/components/Tasks/TaskListItem";
import { TaskFiltersPanel, type TaskFilters } from "@/components/Tasks/TaskFiltersPanel";
import { BulkActionsBar } from "@/components/Tasks/BulkActionsBar";
import { TaskCreateDrawer } from "@/components/Tasks/TaskCreateDrawer";
import type { Activity } from "@/lib/types";

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

function buildQueryString(
  preset: TaskPreset,
  filters: TaskFilters,
  sort: SortOption,
  pipelineId: number | null
): string {
  const p = new URLSearchParams();
  p.set("kind", "task");
  p.set("sort", sort);
  p.set("limit", "100");
  p.set("target_type", "deal");
  if (pipelineId) p.set("pipeline_id", String(pipelineId));

  switch (preset) {
    case "pinned": p.set("is_pinned", "true"); p.set("is_closed", "false"); break;
    case "overdue": p.set("overdue", "true"); p.set("is_closed", "false"); break;
    case "done_unclosed": p.set("status", "done"); p.set("is_closed", "false"); break;
    case "today": {
      const d = new Date();
      const ds = d.toISOString().split("T")[0];
      p.set("due_from", ds);
      p.set("due_to", ds);
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
    case "mine": p.set("my_tasks", "true"); break;
    default: break;
  }

  filters.statuses.forEach((s) => p.append("status[]", s));
  filters.priorities.forEach((pr) => p.append("priority[]", pr));
  filters.categoryIds.forEach((c) => p.append("category_ids[]", c));
  if (filters.responsibleId) p.set("responsible_id", filters.responsibleId);
  if (filters.dueFrom) p.set("due_from", filters.dueFrom);
  if (filters.dueTo) p.set("due_to", filters.dueTo);
  if (filters.myTasks) p.set("my_tasks", "true");
  // Задачник по умолчанию показывает только открытые: явно шлём include_closed=false,
  // чтобы backend применил open-only фильтр (без параметра он вернёт open+closed).
  p.set("include_closed", filters.includeClosed ? "true" : "false");

  return "/activities?" + p.toString();
}

function countActiveFilters(f: TaskFilters): number {
  let n = 0;
  if (f.statuses.length) n++;
  if (f.priorities.length) n++;
  if (f.categoryIds.length) n++;
  if (f.responsibleId) n++;
  if (f.createdById) n++;
  if (f.departmentId) n++;
  if (f.dueFrom || f.dueTo) n++;
  if (f.myTasks) n++;
  if (f.myOrders) n++;
  if (f.includeClosed) n++;
  return n;
}

interface Props {
  pipelineId: number | null;
}

export function DealTaskView({ pipelineId }: Props) {
  const [preset, setPreset] = useState<TaskPreset>("today");
  const [sort, setSort] = useState<SortOption>("due_at_asc");
  const [filters, setFilters] = useState<TaskFilters>(DEFAULT_FILTERS);
  const [filtersOpen, setFiltersOpen] = useState(false);
  const [selectedIds, setSelectedIds] = useState<number[]>([]);
  const [drawerOpen, setDrawerOpen] = useState(false);

  const swrKey = useMemo(
    () => buildQueryString(preset, filters, sort, pipelineId),
    [preset, filters, sort, pipelineId]
  );

  const { data: tasks, isLoading, mutate } = useSWR<Activity[]>(swrKey, fetcher);

  const allSelected = tasks ? tasks.length > 0 && selectedIds.length === tasks.length : false;
  const someSelected = selectedIds.length > 0 && !allSelected;
  const activeFiltersCount = countActiveFilters(filters);

  function handleMutate() {
    void mutate();
    void globalMutate("/activities/counts-by-preset");
    void globalMutate("/activities/my-open-count");
  }

  return (
    <div className="flex flex-1 overflow-hidden">
      {/* Left preset sidebar */}
      <TaskPresetSidebar
        active={preset}
        onChange={(p) => { setPreset(p); setSelectedIds([]); }}
      />

      {/* Main canvas */}
      <div className="flex-1 flex flex-col overflow-hidden">
        <div className="flex items-center justify-between px-4 py-2 border-b border-gray-100 dark:border-gray-700 bg-white dark:bg-gray-800">
          <TaskListToolbar
            allSelected={allSelected}
            someSelected={someSelected}
            sort={sort}
            onSelectAll={(checked) => {
              if (!tasks) return;
              setSelectedIds(checked ? tasks.map((t) => t.id) : []);
            }}
            onSortChange={setSort}
            onToggleFilters={() => setFiltersOpen(!filtersOpen)}
            activeFiltersCount={activeFiltersCount}
          />
          <button
            className="btn-primary text-sm ml-3"
            onClick={() => setDrawerOpen(true)}
          >
            <i className="bi bi-plus mr-1" />
            Задача
          </button>
        </div>

        <div className="flex-1 overflow-y-auto">
          {isLoading && (
            <div className="space-y-2 p-4">
              {[1, 2, 3, 4].map((i) => (
                <div key={i} className="h-16 bg-gray-100 dark:bg-gray-700 animate-pulse rounded" />
              ))}
            </div>
          )}

          {!isLoading && tasks && tasks.length === 0 && (
            <div className="flex items-center justify-center py-16">
              <EmptyState
                icon="bi-clipboard-check"
                title="Нет задач"
                description="Задачи по сделкам этой воронки не найдены"
              />
            </div>
          )}

          {!isLoading && tasks && tasks.length > 0 && (
            <div>
              {tasks.map((task) => (
                <TaskListItem
                  key={task.id}
                  task={task}
                  selected={selectedIds.includes(task.id)}
                  onSelect={(id, checked) =>
                    setSelectedIds((prev) => checked ? [...prev, id] : prev.filter((x) => x !== id))
                  }
                  onMutate={handleMutate}
                />
              ))}
            </div>
          )}
        </div>
      </div>

      {/* Filters panel */}
      <TaskFiltersPanel
        open={filtersOpen}
        filters={filters}
        onChange={setFilters}
        onClose={() => setFiltersOpen(false)}
      />

      {/* Bulk actions */}
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
