"use client";

import { useMemo, useState } from "react";
import useSWR from "swr";
import type { TeamProgressResponse, CourseProgressStatus } from "@/lib/types";
import { fetcher } from "@/lib/api";
import { ProgressMatrixCell } from "./ProgressMatrixCell";
import { UserCourseDetailsDrawer } from "./UserCourseDetailsDrawer";

const ROLE_FILTER_OPTIONS = [
  { value: "", label: "Все роли" },
  { value: "manager", label: "Менеджер" },
  { value: "lawyer", label: "Юрист" },
  { value: "director", label: "Директор" },
  { value: "admin", label: "Администратор" },
];

const STATUS_FILTER_OPTIONS = [
  { value: "", label: "Все статусы" },
  { value: "not_started", label: "Не начат" },
  { value: "in_progress", label: "В процессе" },
  { value: "completed", label: "Завершён" },
  { value: "overdue", label: "Просрочен" },
];

export function ProgressMatrix() {
  const { data, isLoading, mutate } = useSWR<TeamProgressResponse>(
    "/admin/onboarding/progress",
    fetcher
  );

  const [roleFilter, setRoleFilter] = useState("");
  const [statusFilter, setStatusFilter] = useState("");
  const [drawerUserId, setDrawerUserId] = useState<number | null>(null);
  const [drawerCourseId, setDrawerCourseId] = useState<number | null>(null);

  const filteredUsers = useMemo(() => {
    if (!data) return [];
    return data.users.filter((u) => {
      if (roleFilter && u.user_role !== roleFilter) return false;
      if (statusFilter) {
        const hasCourse = u.courses.some(
          (c) => c.status === statusFilter
        );
        if (!hasCourse) return false;
      }
      return true;
    });
  }, [data, roleFilter, statusFilter]);

  function openDrawer(userId: number, courseId: number) {
    setDrawerUserId(userId);
    setDrawerCourseId(courseId);
  }

  function resetFilters() {
    setRoleFilter("");
    setStatusFilter("");
  }

  if (isLoading) {
    return (
      <div className="space-y-3 animate-pulse">
        <div className="flex gap-3 mb-2">
          <div className="h-9 bg-gray-100 dark:bg-gray-700 rounded-lg w-32" />
          <div className="h-9 bg-gray-100 dark:bg-gray-700 rounded-lg w-32" />
        </div>
        {Array.from({ length: 5 }).map((_, i) => (
          <div key={i} className="h-10 bg-gray-100 dark:bg-gray-700 rounded-lg" />
        ))}
      </div>
    );
  }

  if (!data || data.users.length === 0) {
    return (
      <div className="text-sm text-gray-400 dark:text-gray-500 text-center py-10">
        Нет данных о прогрессе. Назначьте курсы сотрудникам.
      </div>
    );
  }

  return (
    <div>
      {/* Filters */}
      <div className="flex items-center gap-3 mb-4 flex-wrap">
        <select
          className="input text-sm py-1.5 w-auto"
          value={roleFilter}
          onChange={(e) => setRoleFilter(e.target.value)}
        >
          {ROLE_FILTER_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
        <select
          className="input text-sm py-1.5 w-auto"
          value={statusFilter}
          onChange={(e) => setStatusFilter(e.target.value)}
        >
          {STATUS_FILTER_OPTIONS.map((o) => (
            <option key={o.value} value={o.value}>{o.label}</option>
          ))}
        </select>
        {(roleFilter || statusFilter) && (
          <button
            type="button"
            className="btn-ghost text-sm flex items-center gap-1"
            onClick={resetFilters}
          >
            <i className="bi bi-x" />
            Сбросить
          </button>
        )}
        <span className="text-sm text-gray-500 ml-auto">
          Показано: {filteredUsers.length} из {data.users.length}
        </span>
      </div>

      {/* Matrix table */}
      <div className="overflow-x-auto">
        <table className="w-full text-sm border-collapse">
          <thead>
            <tr className="border-b border-gray-200">
              <th className="text-left px-3 py-2.5 text-gray-600 font-semibold min-w-[160px]">
                Сотрудник
              </th>
              {data.courses.map((c) => (
                <th
                  key={c.id}
                  className="text-left px-3 py-2.5 text-gray-600 font-semibold min-w-[140px]"
                >
                  {c.title}
                </th>
              ))}
              <th className="text-left px-3 py-2.5 text-gray-600 font-semibold w-20">
                Avg %
              </th>
            </tr>
          </thead>
          <tbody>
            {filteredUsers.map((user) => {
              const assignedCourses = user.courses.filter((c) => c.status !== "unassigned" && c.assignment_id !== null);
              const avgPct = assignedCourses.length > 0
                ? Math.round(assignedCourses.reduce((sum, c) => sum + c.percent, 0) / assignedCourses.length)
                : 0;

              return (
                <tr key={user.user_id} className="border-b border-gray-100 last:border-0">
                  <td className="px-3 py-2">
                    <div className="font-medium text-gray-800 truncate">{user.user_name}</div>
                    <div className="text-xs text-gray-400">{user.user_role}</div>
                  </td>
                  {data.courses.map((course) => {
                    const userCourse = user.courses.find((c) => c.course_id === course.id);
                    const status = (userCourse?.status ?? "unassigned") as CourseProgressStatus | "unassigned";
                    const percent = userCourse?.percent ?? 0;
                    return (
                      <ProgressMatrixCell
                        key={course.id}
                        status={status}
                        percent={percent}
                        onClick={
                          status !== "unassigned"
                            ? () => openDrawer(user.user_id, course.id)
                            : undefined
                        }
                      />
                    );
                  })}
                  <td className="px-3 py-2 tabular-nums text-sm font-medium text-gray-600">
                    {assignedCourses.length > 0 ? `${avgPct}%` : "—"}
                  </td>
                </tr>
              );
            })}
          </tbody>
        </table>
      </div>

      <UserCourseDetailsDrawer
        isOpen={drawerUserId !== null && drawerCourseId !== null}
        userId={drawerUserId}
        courseId={drawerCourseId}
        onClose={() => { setDrawerUserId(null); setDrawerCourseId(null); }}
        onRefresh={() => mutate()}
      />
    </div>
  );
}
