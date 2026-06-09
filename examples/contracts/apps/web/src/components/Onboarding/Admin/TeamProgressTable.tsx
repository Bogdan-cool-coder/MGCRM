"use client";

import { useMemo, useState } from "react";
import useSWR, { mutate as globalMutate } from "swr";
import Link from "next/link";
import { fetcher, api } from "@/lib/api";
import { Modal } from "@/components/Modal";
import { EmptyState } from "@/components/EmptyState";
import { UserSelect } from "@/components/UserSelect";
import type { Course, OnboardingTeamProgressRow, OnboardingTeamProgressResponse } from "@/lib/types";

type SortKey = "assigned_count" | "completed_count" | "in_progress_count" | "overdue_count" | "progress_pct";
type SortDir = "asc" | "desc" | "none";

function progressBarColor(pct: number): string {
  if (pct < 40) return "bg-danger";
  if (pct < 70) return "bg-warning";
  return "bg-success";
}

function buildSwrKey(params: {
  managerId: string;
  courseId: string;
  period: string;
}) {
  const parts: string[] = [];
  if (params.managerId) parts.push(`manager_id=${params.managerId}`);
  if (params.courseId) parts.push(`course_id=${params.courseId}`);
  if (params.period) parts.push(`period=${params.period}`);
  return `/admin/onboarding/analytics/team-progress${parts.length ? "?" + parts.join("&") : ""}`;
}

export function TeamProgressTable() {
  // Filters
  const [managerId, setManagerId] = useState("");
  const [courseId, setCourseId] = useState("");
  const [period, setPeriod] = useState("");

  // Sorting
  const [sortKey, setSortKey] = useState<SortKey | null>(null);
  const [sortDir, setSortDir] = useState<SortDir>("none");

  // Bulk select
  const [selectedIds, setSelectedIds] = useState<Set<number>>(new Set());

  // Bulk assign modal
  const [assignOpen, setAssignOpen] = useState(false);
  const [assignCourseId, setAssignCourseId] = useState("");
  const [assigning, setAssigning] = useState(false);

  // Export
  const [exporting, setExporting] = useState(false);

  const swrKey = useMemo(
    () => buildSwrKey({ managerId, courseId, period }),
    [managerId, courseId, period]
  );

  const { data, isLoading, error } = useSWR<OnboardingTeamProgressResponse>(swrKey, fetcher);

  const { data: courses } = useSWR<Course[]>(
    "/admin/onboarding/courses?is_published=true",
    fetcher
  );

  const hasFilters = !!(managerId || courseId || period);

  function resetFilters() {
    setManagerId("");
    setCourseId("");
    setPeriod("");
    setSelectedIds(new Set());
  }

  // Sort
  function handleSort(key: SortKey) {
    if (sortKey !== key) {
      setSortKey(key);
      setSortDir("asc");
    } else {
      if (sortDir === "asc") setSortDir("desc");
      else if (sortDir === "desc") { setSortKey(null); setSortDir("none"); }
      else { setSortDir("asc"); }
    }
  }

  const sortedData = useMemo(() => {
    if (!data) return [];
    if (!sortKey || sortDir === "none") return data;
    const copy = [...data];
    copy.sort((a, b) => {
      const va = a[sortKey];
      const vb = b[sortKey];
      return sortDir === "asc" ? va - vb : vb - va;
    });
    return copy;
  }, [data, sortKey, sortDir]);

  // Bulk select
  function toggleAll() {
    if (!sortedData.length) return;
    if (selectedIds.size === sortedData.length) {
      setSelectedIds(new Set());
    } else {
      setSelectedIds(new Set(sortedData.map((r) => r.user_id)));
    }
  }

  function toggleRow(id: number) {
    setSelectedIds((prev) => {
      const next = new Set(prev);
      if (next.has(id)) next.delete(id);
      else next.add(id);
      return next;
    });
  }

  // Bulk assign
  async function handleAssign() {
    if (!assignCourseId) return;
    setAssigning(true);
    try {
      await api("/admin/onboarding/assignments/bulk", {
        method: "POST",
        body: {
          user_ids: Array.from(selectedIds),
          course_id: Number(assignCourseId),
        },
      });
      setAssignOpen(false);
      setAssignCourseId("");
      setSelectedIds(new Set());
      await globalMutate(swrKey);
    } catch {
      alert("Не удалось назначить курс");
    } finally {
      setAssigning(false);
    }
  }

  // Export
  async function handleExport() {
    setExporting(true);
    try {
      const body: Record<string, unknown> = {};
      if (managerId) body.manager_id = Number(managerId);
      if (courseId) body.course_id = Number(courseId);
      if (period) body.period = period;

      const response = await fetch("/api/admin/onboarding/analytics/export", {
        method: "POST",
        credentials: "same-origin",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(body),
      });

      if (!response.ok) throw new Error("Export failed");

      const blob = await response.blob();
      const url = URL.createObjectURL(blob);
      const a = document.createElement("a");
      a.href = url;
      a.download = "onboarding-team-progress.xlsx";
      a.click();
      URL.revokeObjectURL(url);
    } catch {
      alert("Не удалось экспортировать данные");
    } finally {
      setExporting(false);
    }
  }

  function sortIcon(key: SortKey) {
    if (sortKey !== key) return "bi-chevron-expand";
    if (sortDir === "asc") return "bi-chevron-up";
    if (sortDir === "desc") return "bi-chevron-down";
    return "bi-chevron-expand";
  }

  function SortableHeader({ label, colKey }: { label: string; colKey: SortKey }) {
    return (
      <th
        className="px-4 py-2.5 text-gray-600 dark:text-gray-400 font-semibold text-right cursor-pointer select-none hover:text-primary transition-colors"
        onClick={() => handleSort(colKey)}
      >
        <span className="flex items-center justify-end gap-1">
          {label}
          <i className={`bi ${sortIcon(colKey)} text-xs`} />
        </span>
      </th>
    );
  }

  return (
    <div>
      {/* Filters */}
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        {/* Department — disabled (Эпик 14 зависимость) */}
        <select
          className="input text-sm py-1.5 w-auto"
          disabled
          title="Доступно после настройки отделов (Эпик 14)"
        >
          <option>Отдел</option>
        </select>

        {/* Manager */}
        <UserSelect
          value={managerId}
          onChange={setManagerId}
          placeholder="Менеджер"
          className="input text-sm py-1.5 w-auto"
        />

        {/* Course */}
        <select
          className="input text-sm py-1.5 w-auto"
          value={courseId}
          onChange={(e) => setCourseId(e.target.value)}
        >
          <option value="">Все курсы</option>
          {(courses ?? []).map((c) => (
            <option key={c.id} value={String(c.id)}>
              {c.title}
            </option>
          ))}
        </select>

        {/* Period */}
        <select
          className="input text-sm py-1.5 w-auto"
          value={period}
          onChange={(e) => setPeriod(e.target.value)}
        >
          <option value="">За всё время</option>
          <option value="30d">30 дней</option>
          <option value="90d">90 дней</option>
          <option value="1y">Этот год</option>
        </select>

        {hasFilters && (
          <button
            onClick={resetFilters}
            className="btn-ghost text-sm flex items-center gap-1"
          >
            <i className="bi bi-x" />
            Сбросить
          </button>
        )}

        <button
          onClick={handleExport}
          disabled={exporting}
          className="btn-secondary text-sm flex items-center gap-1 ml-auto"
        >
          <i className="bi bi-file-earmark-excel" />
          {exporting ? "Готовим…" : "Экспорт Excel"}
        </button>
      </div>

      {/* Loading */}
      {isLoading && (
        <div className="card p-0 overflow-hidden">
          {Array.from({ length: 5 }).map((_, i) => (
            <div key={i} className="animate-pulse h-12 bg-gray-100 dark:bg-gray-700 mb-1 last:mb-0" />
          ))}
        </div>
      )}

      {/* Error */}
      {!isLoading && error && (
        <p className="text-danger text-sm py-4 text-center">
          Не удалось загрузить прогресс команды
        </p>
      )}

      {/* Empty states */}
      {!isLoading && !error && sortedData.length === 0 && (
        <>
          {hasFilters ? (
            <EmptyState
              icon="bi-people"
              title="Нет сотрудников по выбранным фильтрам"
              description="Попробуй изменить фильтры или назначить курсы команде"
            />
          ) : (
            <EmptyState
              icon="bi-mortarboard"
              title="Курсы ещё не назначены"
              description="Перейди во вкладку «Курсы» и назначь команде обучение"
              cta={
                <Link href="/admin/onboarding/courses" className="btn-primary text-sm">
                  Перейти к курсам
                </Link>
              }
            />
          )}
        </>
      )}

      {/* Table */}
      {!isLoading && !error && sortedData.length > 0 && (
        <div className="card p-0 overflow-hidden overflow-x-auto">
          <table className="w-full text-sm">
            <thead>
              <tr className="border-b border-gray-200 dark:border-gray-700 bg-gray-50 dark:bg-gray-900">
                <th className="w-10 px-4 py-2.5">
                  <input
                    type="checkbox"
                    checked={selectedIds.size === sortedData.length && sortedData.length > 0}
                    onChange={toggleAll}
                    className="cursor-pointer"
                    aria-label="Выбрать всех"
                  />
                </th>
                <th className="text-left px-4 py-2.5 text-gray-600 dark:text-gray-400 font-semibold min-w-[160px]">
                  ФИО
                </th>
                <th className="text-left px-4 py-2.5 text-gray-600 dark:text-gray-400 font-semibold w-32">
                  Отдел
                </th>
                <SortableHeader label="Назначено" colKey="assigned_count" />
                <SortableHeader label="Завершено" colKey="completed_count" />
                <SortableHeader label="В процессе" colKey="in_progress_count" />
                <SortableHeader label="Просрочено" colKey="overdue_count" />
                <SortableHeader label="Прогресс" colKey="progress_pct" />
              </tr>
            </thead>
            <tbody>
              {sortedData.map((row: OnboardingTeamProgressRow) => (
                <tr
                  key={row.user_id}
                  className="border-b border-gray-100 dark:border-gray-700 last:border-0 hover:bg-gray-50 dark:hover:bg-gray-700 transition-colors"
                >
                  <td className="w-10 px-4 py-3">
                    <input
                      type="checkbox"
                      checked={selectedIds.has(row.user_id)}
                      onChange={() => toggleRow(row.user_id)}
                      className="cursor-pointer"
                    />
                  </td>
                  <td className="px-4 py-3 min-w-[160px]">
                    <div className="font-medium text-gray-900 dark:text-gray-100">{row.user_name}</div>
                    <div className="text-xs text-gray-400 dark:text-gray-500">{row.user_role}</div>
                  </td>
                  <td className="px-4 py-3 w-32 text-gray-600 dark:text-gray-400">
                    {row.department_name ?? "—"}
                  </td>
                  <td className="px-4 py-3 w-24 text-right tabular-nums text-gray-700 dark:text-gray-300">
                    {row.assigned_count}
                  </td>
                  <td className="px-4 py-3 w-24 text-right tabular-nums text-success">
                    {row.completed_count}
                  </td>
                  <td className="px-4 py-3 w-24 text-right tabular-nums text-info">
                    {row.in_progress_count}
                  </td>
                  <td className="px-4 py-3 w-24 text-right tabular-nums">
                    <span className={row.overdue_count > 0 ? "text-danger" : "text-gray-400 dark:text-gray-500"}>
                      {row.overdue_count}
                    </span>
                  </td>
                  <td className="px-4 py-3 w-28">
                    <div className="flex items-center gap-2">
                      <div className="flex-1 h-1.5 bg-gray-100 dark:bg-gray-700 rounded-full overflow-hidden">
                        <div
                          className={`h-full rounded-full ${progressBarColor(row.progress_pct)}`}
                          style={{ width: `${row.progress_pct}%` }}
                        />
                      </div>
                      <span className="text-xs tabular-nums text-gray-600 dark:text-gray-400 w-8 text-right">
                        {row.progress_pct}%
                      </span>
                    </div>
                  </td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}

      {/* Bulk action bar */}
      {selectedIds.size > 0 && (
        <div className="sticky bottom-0 bg-white dark:bg-gray-800 border-t border-gray-200 dark:border-gray-700 p-3 flex items-center gap-3 mt-2 rounded-b-lg shadow-lg">
          <span className="text-sm text-gray-700 dark:text-gray-300">
            Выбрано: {selectedIds.size}
          </span>
          <button
            onClick={() => setAssignOpen(true)}
            className="btn-primary text-sm"
          >
            Назначить курс
          </button>
          <button
            onClick={() => setSelectedIds(new Set())}
            className="btn-ghost text-sm"
          >
            Снять выбор
          </button>
        </div>
      )}

      {/* Bulk assign modal */}
      <Modal
        open={assignOpen}
        title="Назначить курс"
        description="Выбранные сотрудники получат доступ к курсу"
        onClose={() => { setAssignOpen(false); setAssignCourseId(""); }}
        footer={
          <>
            <button
              onClick={() => { setAssignOpen(false); setAssignCourseId(""); }}
              className="btn-ghost"
            >
              Отмена
            </button>
            <button
              onClick={handleAssign}
              disabled={!assignCourseId || assigning}
              className="btn-primary"
            >
              {assigning ? "Назначаем…" : "Назначить"}
            </button>
          </>
        }
      >
        <div>
          <label className="label" htmlFor="bulk-course-select">
            Выбери курс
          </label>
          <select
            id="bulk-course-select"
            className="input w-full mt-1"
            value={assignCourseId}
            onChange={(e) => setAssignCourseId(e.target.value)}
          >
            <option value="">Выбери курс</option>
            {(courses ?? []).map((c) => (
              <option key={c.id} value={String(c.id)}>
                {c.title}
              </option>
            ))}
          </select>
        </div>
      </Modal>
    </div>
  );
}
