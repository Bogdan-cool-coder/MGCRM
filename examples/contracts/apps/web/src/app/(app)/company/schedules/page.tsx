"use client";

import { useEffect, useMemo, useState } from "react";
import useSWR from "swr";
import { PageHeader } from "@/components/PageHeader";
import { DepartmentScheduleEditor } from "@/components/Company/DepartmentScheduleEditor";
import { EmptyState } from "@/components/EmptyState";
import { useToast } from "@/components/ui/Toast";
import { api, ApiError, fetcher } from "@/lib/api";
import type { Department, WorkSchedule } from "@/lib/types";

export default function SchedulesPage() {
  const { toast } = useToast();
  const { data: departments, isLoading: depsLoading } = useSWR<Department[]>("/departments", fetcher);
  const { data: schedules, isLoading: schLoading } = useSWR<WorkSchedule[]>(
    "/admin/work-schedules?scope_type=department",
    fetcher
  );

  const [localSchedules, setLocalSchedules] = useState<WorkSchedule[]>([]);
  const [saving, setSaving] = useState(false);

  useEffect(() => {
    if (schedules) setLocalSchedules(schedules);
  }, [schedules]);

  const isDirty = useMemo(() => {
    if (!schedules) return false;
    return JSON.stringify(schedules) !== JSON.stringify(localSchedules);
  }, [schedules, localSchedules]);

  function handleDeptScheduleChange(deptId: number, updated: WorkSchedule[]) {
    setLocalSchedules((prev) => {
      const without = prev.filter((s) => s.department_id !== deptId);
      return [...without, ...updated];
    });
  }

  async function handleSave() {
    setSaving(true);
    try {
      await api("/admin/work-schedules/bulk", {
        method: "PATCH",
        body: localSchedules,
      });
      toast.success("Расписание сохранено");
    } catch (err) {
      toast.error(
        "Не удалось сохранить",
        err instanceof ApiError
          ? String((err.detail as Record<string, unknown>)?.detail ?? err.message)
          : "Что-то пошло не так"
      );
    } finally {
      setSaving(false);
    }
  }

  const isLoading = depsLoading || schLoading;

  return (
    <>
      <PageHeader
        title="График работы"
        description="Рабочее расписание по отделам для расчёта слотов встреч"
        actions={
          <button
            className="btn-primary"
            onClick={handleSave}
            disabled={saving || !isDirty}
          >
            {saving ? (
              <>
                <i className="bi bi-arrow-repeat animate-spin mr-1" />
                Сохраняем…
              </>
            ) : (
              "Сохранить изменения"
            )}
          </button>
        }
      />

      <div className="p-8">
        {isLoading && (
          <div className="space-y-3">
            {[1, 2, 3].map((i) => (
              <div
                key={i}
                className="animate-pulse rounded-xl bg-gray-100 dark:bg-gray-800"
                style={{ height: "48px" }}
              />
            ))}
          </div>
        )}

        {!isLoading && departments && departments.length === 0 && (
          <div className="card rounded-2xl shadow-elev-1 p-8">
            <EmptyState
              icon="bi-diagram-3"
              title="Нет отделов"
              description="Сначала создай отделы в разделе Отделы"
              cta={
                <a href="/admin/departments" className="btn-secondary text-sm">
                  Перейти к отделам
                </a>
              }
            />
          </div>
        )}

        {!isLoading && departments && departments.length > 0 && (
          <div className="space-y-3">
            {departments.map((dept, idx) => (
              <DepartmentScheduleEditor
                key={dept.id}
                department={dept}
                schedules={localSchedules.filter((s) => s.department_id === dept.id)}
                onChange={(updated) => handleDeptScheduleChange(dept.id, updated)}
                defaultOpen={idx === 0}
              />
            ))}
          </div>
        )}
      </div>
    </>
  );
}
