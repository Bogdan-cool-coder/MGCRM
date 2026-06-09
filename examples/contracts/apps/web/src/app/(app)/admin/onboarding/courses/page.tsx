"use client";

import { useState } from "react";
import Link from "next/link";
import useSWR from "swr";
import type { Course } from "@/lib/types";
import { api, ApiError, fetcher } from "@/lib/api";
import { PageHeader } from "@/components/PageHeader";
import { EmptyState } from "@/components/EmptyState";
import { ProgressMatrix } from "@/components/Onboarding/Admin/ProgressMatrix";
import { TeamProgressTable } from "@/components/Onboarding/Admin/TeamProgressTable";
import { RoleGate } from "@/components/RoleGate";
import { DataTable, type DataTableColumn } from "@/components/ui/DataTable";
import { useToast } from "@/components/ui/Toast";

type TabKind = "courses" | "progress" | "matrix";

// ─── Status badge ─────────────────────────────────────────────────────────────

function StatusBadge({ published }: { published: boolean }) {
  return published ? (
    <span className="inline-flex items-center rounded-full bg-success-50 dark:bg-success-500/10 text-success-700 dark:text-success-500 px-2.5 py-0.5 text-xs font-medium">
      Опубликован
    </span>
  ) : (
    <span className="inline-flex items-center rounded-full bg-warning-50 dark:bg-warning-500/10 text-warning-700 dark:text-warning-500 px-2.5 py-0.5 text-xs font-medium">
      Черновик
    </span>
  );
}

// ─── Courses tab ──────────────────────────────────────────────────────────────

function CoursesTab() {
  const { toast } = useToast();
  const [publishedFilter, setPublishedFilter] = useState<"" | "true" | "false">("");
  const [deletingId, setDeletingId] = useState<number | null>(null);

  const swrKey = `/admin/onboarding/courses${publishedFilter ? `?is_published=${publishedFilter}` : ""}`;
  const { data: courses, isLoading, isValidating, mutate } = useSWR<Course[]>(swrKey, fetcher);

  async function handleDelete(course: Course) {
    if (!confirm(`Удалить курс «${course.title}»? Прогресс студентов будет потерян.`)) return;
    setDeletingId(course.id);
    try {
      await api(`/admin/onboarding/courses/${course.id}`, { method: "DELETE" });
      toast.success("Курс удалён");
      mutate();
    } catch (e) {
      const msg = e instanceof ApiError ? String((e.detail as { detail?: string })?.detail ?? e.message) : "Ошибка";
      toast.error("Не удалось удалить курс", msg);
    } finally {
      setDeletingId(null);
    }
  }

  const columns: DataTableColumn<Course>[] = [
    {
      key: "title",
      header: "Название",
      render: (c) => (
        <div>
          <div className="font-medium text-gray-900 dark:text-gray-100">{c.title}</div>
          {c.description && (
            <div className="text-xs text-gray-400 dark:text-gray-500 truncate max-w-xs">{c.description}</div>
          )}
        </div>
      ),
      skeletonWidth: "70%",
    },
    {
      key: "target_roles",
      header: "Роли",
      render: (c) => (
        <div className="flex flex-wrap gap-1">
          {c.target_roles.map((r) => (
            <span
              key={r}
              className="inline-flex items-center rounded-full bg-primary/10 dark:bg-primary/20 text-primary text-[11px] font-medium px-2 py-0.5"
            >
              {r}
            </span>
          ))}
          {c.target_roles.length === 0 && <span className="text-xs text-gray-400">—</span>}
        </div>
      ),
      skeletonWidth: "50%",
    },
    {
      key: "is_published",
      header: "Статус",
      render: (c) => <StatusBadge published={c.is_published} />,
      skeletonWidth: "5rem",
    },
    {
      key: "is_mandatory",
      header: "Обязательность",
      render: (c) =>
        c.is_mandatory ? (
          <span className="inline-flex items-center rounded-full bg-warning-50 dark:bg-warning-500/10 text-warning-700 dark:text-warning-500 px-2.5 py-0.5 text-xs font-medium">
            Обязательный
          </span>
        ) : (
          <span className="text-xs text-gray-400 dark:text-gray-500">Информационный</span>
        ),
      skeletonWidth: "6rem",
    },
  ];

  const rowActions = (c: Course) => (
    <>
      <Link
        href={`/admin/onboarding/courses/${c.id}/edit`}
        className="btn-ghost text-xs flex items-center gap-1"
      >
        <i className="bi bi-pencil" aria-hidden="true" />
        Редактировать
      </Link>
      <button
        className="btn-ghost text-xs text-danger-600 dark:text-danger-500 hover:bg-danger-50 dark:hover:bg-danger-500/10 flex items-center gap-1"
        onClick={() => handleDelete(c)}
        disabled={deletingId === c.id}
        aria-label="Удалить курс"
      >
        <i className={`bi ${deletingId === c.id ? "bi-hourglass-split animate-spin" : "bi-trash"}`} aria-hidden="true" />
      </button>
    </>
  );

  return (
    <div>
      {/* Filters */}
      <div className="flex items-center gap-3 mb-5">
        <select
          className="input text-sm py-1.5 w-auto"
          value={publishedFilter}
          onChange={(e) => setPublishedFilter(e.target.value as "" | "true" | "false")}
        >
          <option value="">Все курсы</option>
          <option value="true">Опубликованные</option>
          <option value="false">Черновики</option>
        </select>
        {isValidating && !isLoading && (
          <span className="text-xs text-gray-400 dark:text-gray-500 flex items-center gap-1">
            <i className="bi bi-arrow-repeat animate-spin text-xs" aria-hidden="true" />
            Обновление…
          </span>
        )}
      </div>

      <DataTable<Course>
        columns={columns}
        rows={isLoading ? undefined : (courses ?? [])}
        getRowKey={(c) => c.id}
        rowActions={rowActions}
        skeletonRows={5}
        emptyIcon="bi-collection"
        emptyTitle="Курсов пока нет"
        emptyText="Создай первый курс для онбординга команды"
        emptyCta={
          <Link href="/admin/onboarding/courses/new" className="btn-primary text-sm">
            <i className="bi bi-plus-lg mr-1.5" aria-hidden="true" />
            Создать курс
          </Link>
        }
        ariaLabel="Список курсов"
        maxHeight="60vh"
      />
    </div>
  );
}

// ─── Page ─────────────────────────────────────────────────────────────────────

export default function AdminCoursesPage() {
  const [activeTab, setActiveTab] = useState<TabKind>("courses");

  return (
    <RoleGate allowed={["admin", "director"]}>
      <div>
        <PageHeader
          title="Курсы и онбординг"
          description="Управление курсами обучения и мониторинг прогресса команды"
          actions={
            activeTab === "courses" ? (
              <Link href="/admin/onboarding/courses/new" className="btn-primary text-sm flex items-center gap-1.5">
                <i className="bi bi-plus-lg" aria-hidden="true" />
                Создать курс
              </Link>
            ) : undefined
          }
        />

        <div className="px-8 pt-5">
          {/* Tabs */}
          <div className="flex border-b border-gray-200 dark:border-gray-700 mb-6 gap-1">
            {([
              { id: "courses"  as const, label: "Курсы",              icon: "bi-collection-fill" },
              { id: "progress" as const, label: "Прогресс команды",   icon: "bi-bar-chart-fill"  },
              { id: "matrix"   as const, label: "Матрица прогресса",  icon: "bi-grid-3x3"        },
            ] as const).map((tab) => (
              <button
                key={tab.id}
                type="button"
                onClick={() => setActiveTab(tab.id)}
                className={`flex items-center gap-2 px-4 py-2.5 text-sm font-medium border-b-2 -mb-px transition-colors ${
                  activeTab === tab.id
                    ? "border-primary text-primary"
                    : "border-transparent text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200"
                }`}
              >
                <i className={`bi ${tab.icon}`} aria-hidden="true" />
                {tab.label}
              </button>
            ))}
          </div>

          {activeTab === "courses"  && <CoursesTab />}
          {activeTab === "progress" && <TeamProgressTable />}
          {activeTab === "matrix"   && <ProgressMatrix />}
        </div>
      </div>
    </RoleGate>
  );
}
